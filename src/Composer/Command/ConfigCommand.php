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
     * @var JsonFile
     */
    protected $configFile;

    /**
     * @var JsonConfigSource
     */
    protected $configSource;

    /**
     * @var JsonFile
     */
    protected $authConfigFile;

    /**
     * @var JsonConfigSource
     */
    protected $authConfigSource;

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
                new InputOption('auth', 'a', InputOption::VALUE_NONE, 'Affect auth config file (only used for --editor)'),
                new InputOption('unset', null, InputOption::VALUE_NONE, 'Unset the given setting-key'),
                new InputOption('list', 'l', InputOption::VALUE_NONE, 'List configuration settings'),
                new InputOption('file', 'f', InputOption::VALUE_REQUIRED, 'If you want to choose a different composer.json or config.json'),
                new InputOption('absolute', null, InputOption::VALUE_NONE, 'Returns absolute paths when fetching *-dir config values instead of relative'),
                new InputArgument('setting-key', null, 'Setting key'),
                new InputArgument('setting-value', InputArgument::IS_ARRAY, 'Setting value'),
            ))
            ->setHelp(<<<EOT
This command allows you to edit some basic composer settings in either the
local composer.json file or the global config.json file.

To set a config setting:

    <comment>%command.full_name% bin-dir bin/</comment>

To read a config setting:

    <comment>%command.full_name% bin-dir</comment>
    Outputs: <info>bin</info>

To edit the global config.json file:

    <comment>%command.full_name% --global</comment>

To add a repository:

    <comment>%command.full_name% repositories.foo vcs http://bar.com</comment>

To remove a repository (repo is a short alias for repositories):

    <comment>%command.full_name% --unset repo.foo</comment>

To disable packagist:

    <comment>%command.full_name% repo.packagist false</comment>

You can alter repositories in the global config.json file by passing in the
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
        parent::initialize($input, $output);

        if ($input->getOption('global') && null !== $input->getOption('file')) {
            throw new \RuntimeException('--file and --global can not be combined');
        }

        $this->config = Factory::createConfig($this->getIO());

        // Get the local composer.json, global config.json, or if the user
        // passed in a file to use
        $configFile = $input->getOption('global')
            ? ($this->config->get('home') . '/config.json')
            : ($input->getOption('file') ?: trim(getenv('COMPOSER')) ?: 'composer.json');

        // create global composer.json if this was invoked using `composer global config`
        if ($configFile === 'composer.json' && !file_exists($configFile) && realpath(getcwd()) === realpath($this->config->get('home'))) {
            file_put_contents($configFile, "{\n}\n");
        }

        $this->configFile = new JsonFile($configFile);
        $this->configSource = new JsonConfigSource($this->configFile);

        $authConfigFile = $input->getOption('global')
            ? ($this->config->get('home') . '/auth.json')
            : dirname(realpath($configFile)) . '/auth.json';

        $this->authConfigFile = new JsonFile($authConfigFile);
        $this->authConfigSource = new JsonConfigSource($this->authConfigFile, true);

        // initialize the global file if it's not there
        if ($input->getOption('global') && !$this->configFile->exists()) {
            touch($this->configFile->getPath());
            $this->configFile->write(array('config' => new \ArrayObject));
            @chmod($this->configFile->getPath(), 0600);
        }
        if ($input->getOption('global') && !$this->authConfigFile->exists()) {
            touch($this->authConfigFile->getPath());
            $this->authConfigFile->write(array('http-basic' => new \ArrayObject, 'github-oauth' => new \ArrayObject));
            @chmod($this->authConfigFile->getPath(), 0600);
        }

        if (!$this->configFile->exists()) {
            throw new \RuntimeException(sprintf('File "%s" cannot be found in the current directory', $configFile));
        }
    }

    /**
     * {@inheritDoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // Open file in editor
        if ($input->getOption('editor')) {
            $editor = escapeshellcmd(getenv('EDITOR'));
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

            $file = $input->getOption('auth') ? $this->authConfigFile->getPath() : $this->configFile->getPath();
            system($editor . ' ' . $file . (defined('PHP_WINDOWS_VERSION_BUILD') ? '' : ' > `tty`'));

            return 0;
        }

        if (!$input->getOption('global')) {
            $this->config->merge($this->configFile->read());
            $this->config->merge(array('config' => $this->authConfigFile->exists() ? $this->authConfigFile->read() : array()));
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
                $match = false;
                foreach ($bits as $bit) {
                    $key = isset($key) ? $key.'.'.$bit : $bit;
                    $match = false;
                    if (isset($data[$key])) {
                        $match = true;
                        $data = $data[$key];
                        unset($key);
                    }
                }

                if (!$match) {
                    throw new \RuntimeException($settingKey.' is not defined.');
                }

                $value = $data;
            } elseif (isset($data['config'][$settingKey])) {
                $value = $this->config->get($settingKey, $input->getOption('absolute') ? 0 : Config::RELATIVE_PATHS);
            } else {
                throw new \RuntimeException($settingKey.' is not defined');
            }

            if (is_array($value)) {
                $value = json_encode($value);
            }

            $this->getIO()->write($value);

            return 0;
        }

        $values = $input->getArgument('setting-value'); // what the user is trying to add/change

        $booleanValidator = function ($val) { return in_array($val, array('true', 'false', '1', '0'), true); };
        $booleanNormalizer = function ($val) { return $val !== 'false' && (bool) $val; };

        // handle config values
        $uniqueConfigValues = array(
            'process-timeout' => array('is_numeric', 'intval'),
            'use-include-path' => array($booleanValidator, $booleanNormalizer),
            'preferred-install' => array(
                function ($val) { return in_array($val, array('auto', 'source', 'dist'), true); },
                function ($val) { return $val; },
            ),
            'store-auths' => array(
                function ($val) { return in_array($val, array('true', 'false', 'prompt'), true); },
                function ($val) {
                    if ('prompt' === $val) {
                        return 'prompt';
                    }

                    return $val !== 'false' && (bool) $val;
                },
            ),
            'notify-on-install' => array($booleanValidator, $booleanNormalizer),
            'vendor-dir' => array('is_string', function ($val) { return $val; }),
            'bin-dir' => array('is_string', function ($val) { return $val; }),
            'archive-dir' => array('is_string', function ($val) { return $val; }),
            'archive-format' => array('is_string', function ($val) { return $val; }),
            'cache-dir' => array('is_string', function ($val) { return $val; }),
            'cache-files-dir' => array('is_string', function ($val) { return $val; }),
            'cache-repo-dir' => array('is_string', function ($val) { return $val; }),
            'cache-vcs-dir' => array('is_string', function ($val) { return $val; }),
            'cache-ttl' => array('is_numeric', 'intval'),
            'cache-files-ttl' => array('is_numeric', 'intval'),
            'cache-files-maxsize' => array(
                function ($val) { return preg_match('/^\s*([0-9.]+)\s*(?:([kmg])(?:i?b)?)?\s*$/i', $val) > 0; },
                function ($val) { return $val; },
            ),
            'discard-changes' => array(
                function ($val) { return in_array($val, array('stash', 'true', 'false', '1', '0'), true); },
                function ($val) {
                    if ('stash' === $val) {
                        return 'stash';
                    }

                    return $val !== 'false' && (bool) $val;
                },
            ),
            'autoloader-suffix' => array('is_string', function ($val) { return $val === 'null' ? null : $val; }),
            'optimize-autoloader' => array($booleanValidator, $booleanNormalizer),
            'classmap-authoritative' => array($booleanValidator, $booleanNormalizer),
            'prepend-autoloader' => array($booleanValidator, $booleanNormalizer),
            'github-expose-hostname' => array($booleanValidator, $booleanNormalizer),
        );
        $multiConfigValues = array(
            'github-protocols' => array(
                function ($vals) {
                    if (!is_array($vals)) {
                        return 'array expected';
                    }

                    foreach ($vals as $val) {
                        if (!in_array($val, array('git', 'https', 'ssh'))) {
                            return 'valid protocols include: git, https, ssh';
                        }
                    }

                    return true;
                },
                function ($vals) {
                    return $vals;
                },
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
                },
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

        // handle repositories
        if (preg_match('/^repos?(?:itories)?\.(.+)/', $settingKey, $matches)) {
            if ($input->getOption('unset')) {
                return $this->configSource->removeRepository($matches[1]);
            }

            if (2 === count($values)) {
                return $this->configSource->addRepository($matches[1], array(
                    'type' => $values[0],
                    'url'  => $values[1],
                ));
            }

            if (1 === count($values)) {
                $bool = strtolower($values[0]);
                if (true === $booleanValidator($bool) && false === $booleanNormalizer($bool)) {
                    return $this->configSource->addRepository($matches[1], false);
                }
            }

            throw new \RuntimeException('You must pass the type and a url. Example: php composer.phar config repositories.foo vcs http://bar.com');
        }

        // handle platform
        if (preg_match('/^platform\.(.+)/', $settingKey, $matches)) {
            if ($input->getOption('unset')) {
                return $this->configSource->removeConfigSetting($settingKey);
            }

            return $this->configSource->addConfigSetting($settingKey, $values[0]);
        }

        // handle github-oauth
        if (preg_match('/^(github-oauth|http-basic)\.(.+)/', $settingKey, $matches)) {
            if ($input->getOption('unset')) {
                $this->authConfigSource->removeConfigSetting($matches[1].'.'.$matches[2]);
                $this->configSource->removeConfigSetting($matches[1].'.'.$matches[2]);

                return;
            }

            if ($matches[1] === 'github-oauth') {
                if (1 !== count($values)) {
                    throw new \RuntimeException('Too many arguments, expected only one token');
                }
                $this->configSource->removeConfigSetting($matches[1].'.'.$matches[2]);
                $this->authConfigSource->addConfigSetting($matches[1].'.'.$matches[2], $values[0]);
            } elseif ($matches[1] === 'http-basic') {
                if (2 !== count($values)) {
                    throw new \RuntimeException('Expected two arguments (username, password), got '.count($values));
                }
                $this->configSource->removeConfigSetting($matches[1].'.'.$matches[2]);
                $this->authConfigSource->addConfigSetting($matches[1].'.'.$matches[2], array('username' => $values[0], 'password' => $values[1]));
            }

            return;
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
        $io = $this->getIO();
        foreach ($contents as $key => $value) {
            if ($k === null && !in_array($key, array('config', 'repositories'))) {
                continue;
            }

            $rawVal = isset($rawContents[$key]) ? $rawContents[$key] : null;

            if (is_array($value) && (!is_numeric(key($value)) || ($key === 'repositories' && null === $k))) {
                $k .= preg_replace('{^config\.}', '', $key . '.');
                $this->listConfiguration($value, $rawVal, $output, $k);
                $k = $origK;

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
                $io->write('[<comment>' . $k . $key . '</comment>] <info>' . $rawVal . ' (' . $value . ')</info>');
            } else {
                $io->write('[<comment>' . $k . $key . '</comment>] <info>' . $value . '</info>');
            }
        }
    }
}
