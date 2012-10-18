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

    <comment>%command.full_name% --edit</comment>

To choose your editor you can set the "EDITOR" env variable.

To get a list of configuration values in the file:

    <comment>%command.full_name% --list</comment>

You can always pass more than one option. As an example, if you want to edit the
global config.json file.

    <comment>%command.full_name% --edit --global</comment>
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

        // Get the local composer.json, global config.json, or if the user
        // passed in a file to use
        $configFile = $input->getOption('global')
            ? (Factory::createConfig()->get('home') . '/config.json')
            : $input->getOption('file');

        $this->configFile = new JsonFile($configFile);
        $this->configSource = new JsonConfigSource($this->configFile);

        // initialize the global file if it's not there
        if ($input->getOption('global') && !$this->configFile->exists()) {
            touch($this->configFile->getPath());
            $this->configFile->write(array('config' => new \ArrayObject));
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

        // List the configuration of the file settings
        if ($input->getOption('list')) {
            $this->listConfiguration($this->configFile->read(), $output);

            return 0;
        }

        if (!$input->getArgument('setting-key')) {
            return 0;
        }

        // If the user enters in a config variable, parse it and save to file
        if (array() !== $input->getArgument('setting-value') && $input->getOption('unset')) {
            throw new \RuntimeException('You can not combine a setting value with --unset');
        }
        if (array() === $input->getArgument('setting-value') && !$input->getOption('unset')) {
            throw new \RuntimeException('You must include a setting value or pass --unset to clear the value');
        }

        $values = $input->getArgument('setting-value'); // what the user is trying to add/change

        // handle repositories
        if (preg_match('/^repos?(?:itories)?\.(.+)/', $input->getArgument('setting-key'), $matches)) {
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

        // handle config values
        $uniqueConfigValues = array(
            'process-timeout' => array('is_numeric', 'intval'),
            'vendor-dir' => array('is_string', function ($val) { return $val; }),
            'bin-dir' => array('is_string', function ($val) { return $val; }),
            'notify-on-install' => array(
                function ($val) { return true; },
                function ($val) { return $val !== 'false' && (bool) $val; }
            ),
        );
        $multiConfigValues = array(
            'github-protocols' => array(
                function ($vals) {
                    if (!is_array($vals)) {
                        return 'array expected';
                    }

                    foreach ($vals as $val) {
                        if (!in_array($val, array('git', 'https', 'http'))) {
                            return 'valid protocols include: git, https, http';
                        }
                    }

                    return true;
                },
                function ($vals) {
                    return $vals;
                }
            ),
        );

        $settingKey = $input->getArgument('setting-key');
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
     * @param OutputInterface $output
     * @param string|null     $k
     */
    protected function listConfiguration(array $contents, OutputInterface $output, $k = null)
    {
        foreach ($contents as $key => $value) {
            if ($k === null && !in_array($key, array('config', 'repositories'))) {
                continue;
            }

            if (is_array($value)) {
                $k .= preg_replace('{^config\.}', '', $key . '.');
                $this->listConfiguration($value, $output, $k);

                if (substr_count($k,'.') > 1) {
                    $k = str_split($k,strrpos($k,'.',-2));
                    $k = $k[0] . '.';
                } else {
                    $k = null;
                }

                continue;
            }

            $output->writeln('[<comment>' . $k . $key . '</comment>] <info>' . $value . '</info>');
        }
    }
}
