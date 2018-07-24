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

use Composer\Util\Platform;
use Composer\Util\Silencer;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Composer\Config;
use Composer\Config\JsonConfigSource;
use Composer\Factory;
use Composer\Json\JsonFile;
use Composer\Semver\VersionParser;
use Composer\Package\BasePackage;

/**
 * @author Joshua Estes <Joshua.Estes@iostudio.com>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class ConfigCommand extends BaseCommand
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
            ->setDescription('Sets config options.')
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
            ->setHelp(
                <<<EOT
This command allows you to edit composer config settings and repositories
in either the local composer.json file or the global config.json file.

Additionally it lets you edit most properties in the local composer.json.

To set a config setting:

    <comment>%command.full_name% bin-dir bin/</comment>

To read a config setting:

    <comment>%command.full_name% bin-dir</comment>
    Outputs: <info>bin</info>

To edit the global config.json file:

    <comment>%command.full_name% --global</comment>

To add a repository:

    <comment>%command.full_name% repositories.foo vcs https://bar.com</comment>

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

        $io = $this->getIO();
        $this->config = Factory::createConfig($io);

        // Get the local composer.json, global config.json, or if the user
        // passed in a file to use
        $configFile = $input->getOption('global')
            ? ($this->config->get('home') . '/config.json')
            : ($input->getOption('file') ?: Factory::getComposerFile());

        // Create global composer.json if this was invoked using `composer global config`
        if (
            ($configFile === 'composer.json' || $configFile === './composer.json')
            && !file_exists($configFile)
            && realpath(getcwd()) === realpath($this->config->get('home'))
        ) {
            file_put_contents($configFile, "{\n}\n");
        }

        $this->configFile = new JsonFile($configFile, null, $io);
        $this->configSource = new JsonConfigSource($this->configFile);

        $authConfigFile = $input->getOption('global')
            ? ($this->config->get('home') . '/auth.json')
            : dirname(realpath($configFile)) . '/auth.json';

        $this->authConfigFile = new JsonFile($authConfigFile, null, $io);
        $this->authConfigSource = new JsonConfigSource($this->authConfigFile, true);

        // Initialize the global file if it's not there, ignoring any warnings or notices
        if ($input->getOption('global') && !$this->configFile->exists()) {
            touch($this->configFile->getPath());
            $this->configFile->write(array('config' => new \ArrayObject));
            Silencer::call('chmod', $this->configFile->getPath(), 0600);
        }
        if ($input->getOption('global') && !$this->authConfigFile->exists()) {
            touch($this->authConfigFile->getPath());
            $this->authConfigFile->write(array('bitbucket-oauth' => new \ArrayObject, 'github-oauth' => new \ArrayObject, 'gitlab-oauth' => new \ArrayObject, 'gitlab-token' => new \ArrayObject, 'http-basic' => new \ArrayObject));
            Silencer::call('chmod', $this->authConfigFile->getPath(), 0600);
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
                if (Platform::isWindows()) {
                    $editor = 'notepad';
                } else {
                    foreach (array('editor', 'vim', 'vi', 'nano', 'pico', 'ed') as $candidate) {
                        if (exec('which '.$candidate)) {
                            $editor = $candidate;
                            break;
                        }
                    }
                }
            }

            $file = $input->getOption('auth') ? $this->authConfigFile->getPath() : $this->configFile->getPath();
            system($editor . ' ' . $file . (Platform::isWindows() ? '' : ' > `tty`'));

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
            $properties = array('name', 'type', 'description', 'homepage', 'version', 'minimum-stability', 'prefer-stable', 'keywords', 'license', 'extra');
            $rawData = $this->configFile->read();
            $data = $this->config->all();
            if (preg_match('/^repos?(?:itories)?(?:\.(.+))?/', $settingKey, $matches)) {
                if (!isset($matches[1]) || $matches[1] === '') {
                    $value = isset($data['repositories']) ? $data['repositories'] : array();
                } else {
                    if (!isset($data['repositories'][$matches[1]])) {
                        throw new \InvalidArgumentException('There is no '.$matches[1].' repository defined');
                    }

                    $value = $data['repositories'][$matches[1]];
                }
            } elseif (strpos($settingKey, '.')) {
                $bits = explode('.', $settingKey);
                if ($bits[0] === 'extra') {
                    $data = $rawData;
                } else {
                    $data = $data['config'];
                }
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
            } elseif (in_array($settingKey, $properties, true) && isset($rawData[$settingKey])) {
                $value = $rawData[$settingKey];
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

        $booleanValidator = function ($val) {
            return in_array($val, array('true', 'false', '1', '0'), true);
        };
        $booleanNormalizer = function ($val) {
            return $val !== 'false' && (bool) $val;
        };

        // handle config values
        $uniqueConfigValues = array(
            'process-timeout' => array('is_numeric', 'intval'),
            'use-include-path' => array($booleanValidator, $booleanNormalizer),
            'preferred-install' => array(
                function ($val) {
                    return in_array($val, array('auto', 'source', 'dist'), true);
                },
                function ($val) {
                    return $val;
                },
            ),
            'store-auths' => array(
                function ($val) {
                    return in_array($val, array('true', 'false', 'prompt'), true);
                },
                function ($val) {
                    if ('prompt' === $val) {
                        return 'prompt';
                    }

                    return $val !== 'false' && (bool) $val;
                },
            ),
            'notify-on-install' => array($booleanValidator, $booleanNormalizer),
            'vendor-dir' => array('is_string', function ($val) {
                return $val;
            }),
            'bin-dir' => array('is_string', function ($val) {
                return $val;
            }),
            'archive-dir' => array('is_string', function ($val) {
                return $val;
            }),
            'archive-format' => array('is_string', function ($val) {
                return $val;
            }),
            'data-dir' => array('is_string', function ($val) {
                return $val;
            }),
            'cache-dir' => array('is_string', function ($val) {
                return $val;
            }),
            'cache-files-dir' => array('is_string', function ($val) {
                return $val;
            }),
            'cache-repo-dir' => array('is_string', function ($val) {
                return $val;
            }),
            'cache-vcs-dir' => array('is_string', function ($val) {
                return $val;
            }),
            'cache-ttl' => array('is_numeric', 'intval'),
            'cache-files-ttl' => array('is_numeric', 'intval'),
            'cache-files-maxsize' => array(
                function ($val) {
                    return preg_match('/^\s*([0-9.]+)\s*(?:([kmg])(?:i?b)?)?\s*$/i', $val) > 0;
                },
                function ($val) {
                    return $val;
                },
            ),
            'bin-compat' => array(
                function ($val) {
                    return in_array($val, array('auto', 'full'));
                },
                function ($val) {
                    return $val;
                },
            ),
            'discard-changes' => array(
                function ($val) {
                    return in_array($val, array('stash', 'true', 'false', '1', '0'), true);
                },
                function ($val) {
                    if ('stash' === $val) {
                        return 'stash';
                    }

                    return $val !== 'false' && (bool) $val;
                },
            ),
            'autoloader-suffix' => array('is_string', function ($val) {
                return $val === 'null' ? null : $val;
            }),
            'sort-packages' => array($booleanValidator, $booleanNormalizer),
            'optimize-autoloader' => array($booleanValidator, $booleanNormalizer),
            'classmap-authoritative' => array($booleanValidator, $booleanNormalizer),
            'apcu-autoloader' => array($booleanValidator, $booleanNormalizer),
            'prepend-autoloader' => array($booleanValidator, $booleanNormalizer),
            'disable-tls' => array($booleanValidator, $booleanNormalizer),
            'secure-http' => array($booleanValidator, $booleanNormalizer),
            'cafile' => array(
                function ($val) {
                    return file_exists($val) && is_readable($val);
                },
                function ($val) {
                    return $val === 'null' ? null : $val;
                },
            ),
            'capath' => array(
                function ($val) {
                    return is_dir($val) && is_readable($val);
                },
                function ($val) {
                    return $val === 'null' ? null : $val;
                },
            ),
            'github-expose-hostname' => array($booleanValidator, $booleanNormalizer),
            'htaccess-protect' => array($booleanValidator, $booleanNormalizer),
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
            'gitlab-domains' => array(
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

        if ($input->getOption('unset') && (isset($uniqueConfigValues[$settingKey]) || isset($multiConfigValues[$settingKey]))) {
            return $this->configSource->removeConfigSetting($settingKey);
        }
        if (isset($uniqueConfigValues[$settingKey])) {
            return $this->handleSingleValue($settingKey, $uniqueConfigValues[$settingKey], $values, 'addConfigSetting');
        }
        if (isset($multiConfigValues[$settingKey])) {
            return $this->handleMultiValue($settingKey, $multiConfigValues[$settingKey], $values, 'addConfigSetting');
        }

        // handle properties
        $uniqueProps = array(
            'name' => array('is_string', function ($val) {
                return $val;
            }),
            'type' => array('is_string', function ($val) {
                return $val;
            }),
            'description' => array('is_string', function ($val) {
                return $val;
            }),
            'homepage' => array('is_string', function ($val) {
                return $val;
            }),
            'version' => array('is_string', function ($val) {
                return $val;
            }),
            'minimum-stability' => array(
                function ($val) {
                    return isset(BasePackage::$stabilities[VersionParser::normalizeStability($val)]);
                },
                function ($val) {
                    return VersionParser::normalizeStability($val);
                },
            ),
            'prefer-stable' => array($booleanValidator, $booleanNormalizer),
        );
        $multiProps = array(
            'keywords' => array(
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
            'license' => array(
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

        if ($input->getOption('global') && (isset($uniqueProps[$settingKey]) || isset($multiProps[$settingKey]) || substr($settingKey, 0, 6) === 'extra.')) {
            throw new \InvalidArgumentException('The '.$settingKey.' property can not be set in the global config.json file. Use `composer global config` to apply changes to the global composer.json');
        }
        if ($input->getOption('unset') && (isset($uniqueProps[$settingKey]) || isset($multiProps[$settingKey]))) {
            return $this->configSource->removeProperty($settingKey);
        }
        if (isset($uniqueProps[$settingKey])) {
            return $this->handleSingleValue($settingKey, $uniqueProps[$settingKey], $values, 'addProperty');
        }
        if (isset($multiProps[$settingKey])) {
            return $this->handleMultiValue($settingKey, $multiProps[$settingKey], $values, 'addProperty');
        }

        // handle repositories
        if (preg_match('/^repos?(?:itories)?\.(.+)/', $settingKey, $matches)) {
            if ($input->getOption('unset')) {
                return $this->configSource->removeRepository($matches[1]);
            }

            if (2 === count($values)) {
                return $this->configSource->addRepository($matches[1], array(
                    'type' => $values[0],
                    'url' => $values[1],
                ));
            }

            if (1 === count($values)) {
                $value = strtolower($values[0]);
                if (true === $booleanValidator($value)) {
                    if (false === $booleanNormalizer($value)) {
                        return $this->configSource->addRepository($matches[1], false);
                    }
                } else {
                    $value = JsonFile::parseJson($values[0]);

                    return $this->configSource->addRepository($matches[1], $value);
                }
            }

            throw new \RuntimeException('You must pass the type and a url. Example: php composer.phar config repositories.foo vcs https://bar.com');
        }

        // handle extra
        if (preg_match('/^extra\.(.+)/', $settingKey, $matches)) {
            if ($input->getOption('unset')) {
                return $this->configSource->removeProperty($settingKey);
            }

            return $this->configSource->addProperty($settingKey, $values[0]);
        }

        // handle platform
        if (preg_match('/^platform\.(.+)/', $settingKey, $matches)) {
            if ($input->getOption('unset')) {
                return $this->configSource->removeConfigSetting($settingKey);
            }

            return $this->configSource->addConfigSetting($settingKey, $values[0]);
        }
        if ($settingKey === 'platform' && $input->getOption('unset')) {
            return $this->configSource->removeConfigSetting($settingKey);
        }

        // handle auth
        if (preg_match('/^(bitbucket-oauth|github-oauth|gitlab-oauth|gitlab-token|http-basic)\.(.+)/', $settingKey, $matches)) {
            if ($input->getOption('unset')) {
                $this->authConfigSource->removeConfigSetting($matches[1].'.'.$matches[2]);
                $this->configSource->removeConfigSetting($matches[1].'.'.$matches[2]);

                return;
            }

            if ($matches[1] === 'bitbucket-oauth') {
                if (2 !== count($values)) {
                    throw new \RuntimeException('Expected two arguments (consumer-key, consumer-secret), got '.count($values));
                }
                $this->configSource->removeConfigSetting($matches[1].'.'.$matches[2]);
                $this->authConfigSource->addConfigSetting($matches[1].'.'.$matches[2], array('consumer-key' => $values[0], 'consumer-secret' => $values[1]));
            } elseif (in_array($matches[1], array('github-oauth', 'gitlab-oauth', 'gitlab-token'), true)) {
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

        // handle script
        if (preg_match('/^scripts\.(.+)/', $settingKey, $matches)) {
            if ($input->getOption('unset')) {
                return $this->configSource->removeProperty($settingKey);
            }

            return $this->configSource->addProperty($settingKey, count($values) > 1 ? $values : $values[0]);
        }

        throw new \InvalidArgumentException('Setting '.$settingKey.' does not exist or is not supported by this command');
    }

    protected function handleSingleValue($key, array $callbacks, array $values, $method)
    {
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

        return call_user_func(array($this->configSource, $method), $key, $normalizer($values[0]));
    }

    protected function handleMultiValue($key, array $callbacks, array $values, $method)
    {
        list($validator, $normalizer) = $callbacks;
        if (true !== $validation = $validator($values)) {
            throw new \RuntimeException(sprintf(
                '%s is an invalid value'.($validation ? ' ('.$validation.')' : ''),
                json_encode($values)
            ));
        }

        return call_user_func(array($this->configSource, $method), $key, $normalizer($values));
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
