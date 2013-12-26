<?php

/*
 * This file is part of Composer.
 *
 * (c) Nils Adermann <naderman@naderman.de>
 *     Jordi Boggiano <j.boggiano@seld.be>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Composer\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Composer\Config;
use Composer\Config\JsonConfigSource;
use Composer\Factory;
use Composer\Json\JsonFile;

/**
 * @author Joshua Estes <Joshua.Estes@iostudio.com>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class ConfigCommand extends Command
{
    /**
     * @var Config
     */
    protected $config;

    /**
     * @var Composer\Json\JsonFile
     */
    protected $configFile;

    /**
     * @var Composer\Config\JsonConfigSource
     */
    protected $configSource;

    /**
     * {@inheritDoc}
     */
    protected function configure()
    {
        $this
            ->setName('config')
            ->setDescription('Set config options')
            ->setDefinition(array(
                new InputOption('global', 'g', InputOption::VALUE_NONE, 'Apply command to the global config file'),
                new InputOption('editor', 'e', InputOption::VALUE_NONE, 'Open editor'),
                new InputOption('unset', null, InputOption::VALUE_NONE, 'Unset the given setting-key'),
                new InputOption('list', 'l', InputOption::VALUE_NONE, 'List configuration settings'),
                new InputOption('file', 'f', InputOption::VALUE_REQUIRED, 'If you want to choose a different composer.json or config.json', 'composer.json'),
                new InputArgument('setting-key', null, 'Setting key'),
                new InputArgument('setting-value', InputArgument::IS_ARRAY, 'Setting value'),
            ))
            ->setHelp(<<<EOT
This command allows you to edit some basic composer settings in either the
local composer.json file or the global config.json file.

To edit the global config.json file:

    <comment>%command.full_name% --global</comment>

To add a repository:

    <comment>%command.full_name% repositories.foo vcs http://bar.com</comment>

You can add a repository to the global config.json file by passing in the
<info>--global</info> option.

To edit the file in an external editor:

    <comment>%command.full_name% --editor</comment>

To choose your editor you can set the "EDITOR" env variable.

To get a list of configuration values in the file:

    <comment>%command.full_name% --list</comment>

You can always pass more than one option. As an example, if you want to edit the
global config.json file.

    <comment>%command.full_name% --editor --global</comment>
EOT
            )
        ;
    }

    /**
     * {@inheritDoc}
     */
    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        if ($input->getOption('global') && 'composer.json' !== $input->getOption('file')) {
            throw new \RuntimeException('--file and --global can not be combined');
        }

        $this->config = Factory::createConfig();

        // Get the local composer.json, global config.json, or if the user
        // passed in a file to use
        $configFile = $input->getOption('global')
            ? ($this->config->get('home') . '/config.json')
            : $input->getOption('file');

        $this->configFile = new JsonFile($configFile);
        $this->configSource = new JsonConfigSource($this->configFile);

        // initialize the global file if it's not there
        if ($input->getOption('global') && !$this->configFile->exists()) {
            touch($this->configFile->getPath());
            $this->configFile->write(array('config' => new \ArrayObject));
            @chmod($this->configFile->getPath(), 0600);
        }

        if (!$this->configFile->exists()) {
            throw new \RuntimeException('No composer.json found in the current directory');
        }
    }

    /**
     * {@inheritDoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // Open file in editor
        if ($input->getOption('editor')) {
            $editor = getenv('EDITOR');
            if (!$editor) {
                if (defined('PHP_WINDOWS_VERSION_BUILD')) {
                    $editor = 'notepad';
                } else {
                    foreach (array('vim', 'vi', 'nano', 'pico', 'ed') as $candidate) {
                        if (exec('which '.$candidate)) {
                            $editor = $candidate;
                            break;
                        }
                    }
                }
            }

            system($editor . ' ' . $this->configFile->getPath() . (defined('PHP_WINDOWS_VERSION_BUILD') ? '':  ' > `tty`'));

            return 0;
        }

        if (!$input->getOption('global')) {
            $this->config->merge($this->configFile->read());
        }

        // List the configuration of the file settings
        if ($input->getOption('list')) {
            $this->listConfiguration($this->config->all(), $this->config->raw(), $output);

            return 0;
        }

        $settingKey = $input->getArgument('setting-key');
        if (!$settingKey) {
            return 0;
        }

        // If the user enters in a config variable, parse it and save to file
        if (array() !== $input->getArgument('setting-value') && $input->getOption('unset')) {
            throw new \RuntimeException('You can not combine a setting value with --unset');
        }

        // show the value if no value is provided
        if (array() === $input->getArgument('setting-value') && !$input->getOption('unset')) {
            $data = $this->config->all();
            if (preg_match('/^repos?(?:itories)?(?:\.(.+))?/', $settingKey, $matches)) {
                if (empty($matches[1])) {
                    $value = isset($data['repositories']) ? $data['repositories'] : array();
                } else {
                    if (!isset($data['repositories'][$matches[1]])) {
                        throw new \InvalidArgumentException('There is no '.$matches[1].' repository defined');
                    }

                    $value = $data['repositories'][$matches[1]];
                }
            } elseif (strpos($settingKey, '.')) {
                $bits = explode('.', $settingKey);
                $data = $data['config'];
                foreach ($bits as $bit) {
                    if (isset($data[$bit])) {
                        $data = $data[$bit];
                    } elseif (isset($data[implode('.', $bits)])) {
                        // last bit can contain domain names and such so try to join whatever is left if it exists
                        $data = $data[implode('.', $bits)];
                        break;
                    } else {
                        throw new \RuntimeException($settingKey.' is not defined');
                    }
                    array_shift($bits);
                }

                $value = $data;
            } elseif (isset($data['config'][$settingKey])) {
                $value = $data['config'][$settingKey];
            } else {
                throw new \RuntimeException($settingKey.' is not defined');
            }

            if (is_array($value)) {
                $value = json_encode($value);
            }

            $output->writeln($value);

            return 0;
        }

        $values = $input->getArgument('setting-value'); // what the user is trying to add/change

        // handle repositories
        if (preg_match('/^repos?(?:itories)?\.(.+)/', $settingKey, $matches)) {
            if ($input->getOption('unset')) {
                return $this->configSource->removeRepository($matches[1]);
            }

            if (2 !== count($values)) {
                throw new \RuntimeException('You must pass the type and a url. Example: php composer.phar config repositories.foo vcs http://bar.com');
            }

            return $this->configSource->addRepository($matches[1], array(
                'type' => $values[0],
                'url'  => $values[1],
            ));
        }

        // handle github-oauth
        if (preg_match('/^github-oauth\.(.+)/', $settingKey, $matches)) {
            if ($input->getOption('unset')) {
                return $this->configSource->removeConfigSetting('github-oauth.'.$matches[1]);
            }

            if (1 !== count($values)) {
                throw new \RuntimeException('Too many arguments, expected only one token');
            }

            return $this->configSource->addConfigSetting('github-oauth.'.$matches[1], $values[0]);
        }

        $booleanValidator = function ($val) { return in_array($val, array('true', 'false', '1', '0'), true); };
        $booleanNormalizer = function ($val) { return $val !== 'false' && (bool) $val; };

        // handle config values
        $uniqueConfigValues = array(
            'process-timeout' => array('is_numeric', 'intval'),
            'use-include-path' => array($booleanValidator, $booleanNormalizer),
            'preferred-install' => array(
                function ($val) { return in_array($val, array('auto', 'source', 'dist'), true); },
                function ($val) { return $val; }
            ),
            'notify-on-install' => array($booleanValidator, $booleanNormalizer),
            'vendor-dir' => array('is_string', function ($val) { return $val; }),
            'bin-dir' => array('is_string', function ($val) { return $val; }),
            'cache-dir' => array('is_string', function ($val) { return $val; }),
            'cache-files-dir' => array('is_string', function ($val) { return $val; }),
            'cache-repo-dir' => array('is_string', function ($val) { return $val; }),
            'cache-vcs-dir' => array('is_string', function ($val) { return $val; }),
            'cache-ttl' => array('is_numeric', 'intval'),
            'cache-files-ttl' => array('is_numeric', 'intval'),
            'cache-files-maxsize' => array(
                function ($val) { return preg_match('/^\s*([0-9.]+)\s*(?:([kmg])(?:i?b)?)?\s*$/i', $val) > 0; },
                function ($val) { return $val; }
            ),
            'discard-changes' => array(
                function ($val) { return in_array($val, array('stash', 'true', 'false', '1', '0'), true); },
                function ($val) {
                    if ('stash' === $val) {
                        return 'stash';
                    }

                    return $val !== 'false' && (bool) $val;
                }
            ),
            'autoloader-suffix' => array('is_string', function ($val) { return $val === 'null' ? null : $val; }),
            'prepend-autoloader' => array($booleanValidator, $booleanNormalizer),
        );
        $multiConfigValues = array(
            'github-protocols' => array(
                function ($vals) {
                    if (!is_array($vals)) {
                        return 'array expected';
                    }

                    foreach ($vals as $val) {
                        if (!in_array($val, array('git', 'https'))) {
                            return 'valid protocols include: git, https';
                        }
                    }

                    return true;
                },
                function ($vals) {
                    return $vals;
                }
            ),
            'github-domains' => array(
                function ($vals) {
                    if (!is_array($vals)) {
                        return 'array expected';
                    }

                    return true;
                },
                function ($vals) {
                    return $vals;
                }
            ),
        );

        foreach ($uniqueConfigValues as $name => $callbacks) {
             if ($settingKey === $name) {
                if ($input->getOption('unset')) {
                    return $this->configSource->removeConfigSetting($settingKey);
                }

                list($validator, $normalizer) = $callbacks;
                if (1 !== count($values)) {
                    throw new \RuntimeException('You can only pass one value. Example: php composer.phar config process-timeout 300');
                }

                if (true !== $validation = $validator($values[0])) {
                    throw new \RuntimeException(sprintf(
                        '"%s" is an invalid value'.($validation ? ' ('.$validation.')' : ''),
                        $values[0]
                    ));
                }

                return $this->configSource->addConfigSetting($settingKey, $normalizer($values[0]));
            }
        }

        foreach ($multiConfigValues as $name => $callbacks) {
            if ($settingKey === $name) {
                if ($input->getOption('unset')) {
                    return $this->configSource->removeConfigSetting($settingKey);
                }

                list($validator, $normalizer) = $callbacks;
                if (true !== $validation = $validator($values)) {
                    throw new \RuntimeException(sprintf(
                        '%s is an invalid value'.($validation ? ' ('.$validation.')' : ''),
                        json_encode($values)
                    ));
                }

                return $this->configSource->addConfigSetting($settingKey, $normalizer($values));
            }
        }

        throw new \InvalidArgumentException('Setting '.$settingKey.' does not exist or is not supported by this command');
    }

    /**
     * Display the contents of the file in a pretty formatted way
     *
     * @param array           $contents
     * @param array           $rawContents
     * @param OutputInterface $output
     * @param string|null     $k
     */
    protected function listConfiguration(array $contents, array $rawContents, OutputInterface $output, $k = null)
    {
        $origK = $k;
        foreach ($contents as $key => $value) {
            if ($k === null && !in_array($key, array('config', 'repositories'))) {
                continue;
            }

            $rawVal = isset($rawContents[$key]) ? $rawContents[$key] : null;

            if (is_array($value) && (!is_numeric(key($value)) || ($key === 'repositories' && null === $k))) {
                $k .= preg_replace('{^config\.}', '', $key . '.');
                $this->listConfiguration($value, $rawVal, $output, $k);

                if (substr_count($k, '.') > 1) {
                    $k = str_split($k, strrpos($k, '.', -2));
                    $k = $k[0] . '.';
                } else {
                    $k = $origK;
                }

                continue;
            }

            if (is_array($value)) {
                $value = array_map(function ($val) {
                    return is_array($val) ? json_encode($val) : $val;
                }, $value);

                $value = '['.implode(', ', $value).']';
            }

            if (is_bool($value)) {
                $value = var_export($value, true);
            }

            if (is_string($rawVal) && $rawVal != $value) {
                $output->writeln('[<comment>' . $k . $key . '</comment>] <info>' . $rawVal . ' (' . $value . ')</info>');
            } else {
                $output->writeln('[<comment>' . $k . $key . '</comment>] <info>' . $value . '</info>');
            }
        }
    }
}
