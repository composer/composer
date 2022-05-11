<?php declare(strict_types=1);

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

use Composer\Pcre\Preg;
use Composer\Util\Filesystem;
use Composer\Util\Platform;
use Composer\Util\Silencer;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Composer\Config;
use Composer\Config\JsonConfigSource;
use Composer\Factory;
use Composer\IO\IOInterface;
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
     * @return void
     */
    protected function configure(): void
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
                new InputOption('json', 'j', InputOption::VALUE_NONE, 'JSON decode the setting value, to be used with extra.* keys'),
                new InputOption('merge', 'm', InputOption::VALUE_NONE, 'Merge the setting value with the current value, to be used with extra.* keys in combination with --json'),
                new InputOption('append', null, InputOption::VALUE_NONE, 'When adding a repository, append it (lowest priority) to the existing ones instead of prepending it (highest priority)'),
                new InputOption('source', null, InputOption::VALUE_NONE, 'Display where the config value is loaded from'),
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

To add or edit suggested packages you can use:

    <comment>%command.full_name% suggest.package reason for the suggestion</comment>

To add or edit extra properties you can use:

    <comment>%command.full_name% extra.property value</comment>

Or to add a complex value you can use json with:

    <comment>%command.full_name% extra.property --json '{"foo":true, "bar": []}'</comment>

To edit the file in an external editor:

    <comment>%command.full_name% --editor</comment>

To choose your editor you can set the "EDITOR" env variable.

To get a list of configuration values in the file:

    <comment>%command.full_name% --list</comment>

You can always pass more than one option. As an example, if you want to edit the
global config.json file.

    <comment>%command.full_name% --editor --global</comment>

Read more at https://getcomposer.org/doc/03-cli.md#config
EOT
            )
        ;
    }

    /**
     * @return void
     * @throws \Exception
     */
    protected function initialize(InputInterface $input, OutputInterface $output): void
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
            && realpath(Platform::getCwd()) === realpath($this->config->get('home'))
        ) {
            file_put_contents($configFile, "{\n}\n");
        }

        $this->configFile = new JsonFile($configFile, null, $io);
        $this->configSource = new JsonConfigSource($this->configFile);

        $authConfigFile = $input->getOption('global')
            ? ($this->config->get('home') . '/auth.json')
            : dirname($configFile) . '/auth.json';

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
            $this->authConfigFile->write(array('bitbucket-oauth' => new \ArrayObject, 'github-oauth' => new \ArrayObject, 'gitlab-oauth' => new \ArrayObject, 'gitlab-token' => new \ArrayObject, 'http-basic' => new \ArrayObject, 'bearer' => new \ArrayObject));
            Silencer::call('chmod', $this->authConfigFile->getPath(), 0600);
        }

        if (!$this->configFile->exists()) {
            throw new \RuntimeException(sprintf('File "%s" cannot be found in the current directory', $configFile));
        }
    }

    /**
     * @throws \Seld\JsonLint\ParsingException
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Open file in editor
        if (true === $input->getOption('editor')) {
            $editor = Platform::getEnv('EDITOR');
            if (false === $editor || '' === $editor) {
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
            } else {
                $editor = escapeshellcmd($editor);
            }

            $file = $input->getOption('auth') ? $this->authConfigFile->getPath() : $this->configFile->getPath();
            system($editor . ' ' . $file . (Platform::isWindows() ? '' : ' > `tty`'));

            return 0;
        }

        if (false === $input->getOption('global')) {
            $this->config->merge($this->configFile->read(), $this->configFile->getPath());
            $this->config->merge(array('config' => $this->authConfigFile->exists() ? $this->authConfigFile->read() : array()), $this->authConfigFile->getPath());
        }

        // List the configuration of the file settings
        if (true === $input->getOption('list')) {
            $this->listConfiguration($this->config->all(), $this->config->raw(), $output, null, $input->getOption('source'));

            return 0;
        }

        $settingKey = $input->getArgument('setting-key');
        if (!is_string($settingKey)) {
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
            if (Preg::isMatch('/^repos?(?:itories)?(?:\.(.+))?/', $settingKey, $matches)) {
                if (!isset($matches[1]) || $matches[1] === '') {
                    $value = $data['repositories'] ?? array();
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
            } elseif (isset($rawData[$settingKey]) && in_array($settingKey, $properties, true)) {
                $value = $rawData[$settingKey];
            } else {
                throw new \RuntimeException($settingKey.' is not defined');
            }

            if (is_array($value)) {
                $value = json_encode($value);
            }

            $sourceOfConfigValue = '';
            if ($input->getOption('source')) {
                $sourceOfConfigValue = ' (' . $this->config->getSourceOfValue($settingKey) . ')';
            }

            $this->getIO()->write($value . $sourceOfConfigValue, true, IOInterface::QUIET);

            return 0;
        }

        $values = $input->getArgument('setting-value'); // what the user is trying to add/change

        $booleanValidator = function ($val): bool {
            return in_array($val, array('true', 'false', '1', '0'), true);
        };
        $booleanNormalizer = function ($val): bool {
            return $val !== 'false' && (bool) $val;
        };

        // handle config values
        $uniqueConfigValues = array(
            'process-timeout' => array('is_numeric', 'intval'),
            'use-include-path' => array($booleanValidator, $booleanNormalizer),
            'use-github-api' => array($booleanValidator, $booleanNormalizer),
            'preferred-install' => array(
                function ($val): bool {
                    return in_array($val, array('auto', 'source', 'dist'), true);
                },
                function ($val) {
                    return $val;
                },
            ),
            'gitlab-protocol' => array(
                function ($val): bool {
                    return in_array($val, array('git', 'http', 'https'), true);
                },
                function ($val) {
                    return $val;
                },
            ),
            'store-auths' => array(
                function ($val): bool {
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
                function ($val): bool {
                    return Preg::isMatch('/^\s*([0-9.]+)\s*(?:([kmg])(?:i?b)?)?\s*$/i', $val);
                },
                function ($val) {
                    return $val;
                },
            ),
            'bin-compat' => array(
                function ($val): bool {
                    return in_array($val, array('auto', 'full', 'symlink'));
                },
                function ($val) {
                    return $val;
                },
            ),
            'discard-changes' => array(
                function ($val): bool {
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
                function ($val): bool {
                    return file_exists($val) && Filesystem::isReadable($val);
                },
                function ($val) {
                    return $val === 'null' ? null : $val;
                },
            ),
            'capath' => array(
                function ($val): bool {
                    return is_dir($val) && Filesystem::isReadable($val);
                },
                function ($val) {
                    return $val === 'null' ? null : $val;
                },
            ),
            'github-expose-hostname' => array($booleanValidator, $booleanNormalizer),
            'htaccess-protect' => array($booleanValidator, $booleanNormalizer),
            'lock' => array($booleanValidator, $booleanNormalizer),
            'allow-plugins' => array($booleanValidator, $booleanNormalizer),
            'platform-check' => array(
                function ($val): bool {
                    return in_array($val, array('php-only', 'true', 'false', '1', '0'), true);
                },
                function ($val) {
                    if ('php-only' === $val) {
                        return 'php-only';
                    }

                    return $val !== 'false' && (bool) $val;
                },
            ),
            'use-parent-dir' => array(
                function ($val): bool {
                    return in_array($val, array('true', 'false', 'prompt'), true);
                },
                function ($val) {
                    if ('prompt' === $val) {
                        return 'prompt';
                    }

                    return $val !== 'false' && (bool) $val;
                },
            ),
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
            if ($settingKey === 'disable-tls' && $this->config->get('disable-tls')) {
                $this->getIO()->writeError('<info>You are now running Composer with SSL/TLS protection enabled.</info>');
            }

            $this->configSource->removeConfigSetting($settingKey);

            return 0;
        }
        if (isset($uniqueConfigValues[$settingKey])) {
            $this->handleSingleValue($settingKey, $uniqueConfigValues[$settingKey], $values, 'addConfigSetting');

            return 0;
        }
        if (isset($multiConfigValues[$settingKey])) {
            $this->handleMultiValue($settingKey, $multiConfigValues[$settingKey], $values, 'addConfigSetting');

            return 0;
        }
        // handle preferred-install per-package config
        if (Preg::isMatch('/^preferred-install\.(.+)/', $settingKey, $matches)) {
            if ($input->getOption('unset')) {
                $this->configSource->removeConfigSetting($settingKey);

                return 0;
            }

            list($validator) = $uniqueConfigValues['preferred-install'];
            if (!$validator($values[0])) {
                throw new \RuntimeException('Invalid value for '.$settingKey.'. Should be one of: auto, source, or dist');
            }

            $this->configSource->addConfigSetting($settingKey, $values[0]);

            return 0;
        }

        // handle allow-plugins config setting elements true or false to add/remove
        if (Preg::isMatch('{^allow-plugins\.([a-zA-Z0-9/*-]+)}', $settingKey, $matches)) {
            if ($input->getOption('unset')) {
                $this->configSource->removeConfigSetting($settingKey);

                return 0;
            }

            if (true !== $booleanValidator($values[0])) {
                throw new \RuntimeException(sprintf(
                    '"%s" is an invalid value',
                    $values[0]
                ));
            }

            $normalizedValue = $booleanNormalizer($values[0]);

            $this->configSource->addConfigSetting($settingKey, $normalizedValue);

            return 0;
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
                function ($val): bool {
                    return isset(BasePackage::$stabilities[VersionParser::normalizeStability($val)]);
                },
                function ($val): string {
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

        if ($input->getOption('global') && (isset($uniqueProps[$settingKey]) || isset($multiProps[$settingKey]) || strpos($settingKey, 'extra.') === 0)) {
            throw new \InvalidArgumentException('The ' . $settingKey . ' property can not be set in the global config.json file. Use `composer global config` to apply changes to the global composer.json');
        }
        if ($input->getOption('unset') && (isset($uniqueProps[$settingKey]) || isset($multiProps[$settingKey]))) {
            $this->configSource->removeProperty($settingKey);

            return 0;
        }
        if (isset($uniqueProps[$settingKey])) {
            $this->handleSingleValue($settingKey, $uniqueProps[$settingKey], $values, 'addProperty');

            return 0;
        }
        if (isset($multiProps[$settingKey])) {
            $this->handleMultiValue($settingKey, $multiProps[$settingKey], $values, 'addProperty');

            return 0;
        }

        // handle repositories
        if (Preg::isMatch('/^repos?(?:itories)?\.(.+)/', $settingKey, $matches)) {
            if ($input->getOption('unset')) {
                $this->configSource->removeRepository($matches[1]);

                return 0;
            }

            if (2 === count($values)) {
                $this->configSource->addRepository($matches[1], array(
                    'type' => $values[0],
                    'url' => $values[1],
                ), $input->getOption('append'));

                return 0;
            }

            if (1 === count($values)) {
                $value = strtolower($values[0]);
                if (true === $booleanValidator($value)) {
                    if (false === $booleanNormalizer($value)) {
                        $this->configSource->addRepository($matches[1], false, $input->getOption('append'));

                        return 0;
                    }
                } else {
                    $value = JsonFile::parseJson($values[0]);
                    $this->configSource->addRepository($matches[1], $value, $input->getOption('append'));

                    return 0;
                }
            }

            throw new \RuntimeException('You must pass the type and a url. Example: php composer.phar config repositories.foo vcs https://bar.com');
        }

        // handle extra
        if (Preg::isMatch('/^extra\.(.+)/', $settingKey, $matches)) {
            if ($input->getOption('unset')) {
                $this->configSource->removeProperty($settingKey);

                return 0;
            }

            $value = $values[0];
            if ($input->getOption('json')) {
                $value = JsonFile::parseJson($value);
                if ($input->getOption('merge')) {
                    $currentValue = $this->configFile->read();
                    $bits = explode('.', $settingKey);
                    foreach ($bits as $bit) {
                        $currentValue = $currentValue[$bit] ?? null;
                    }
                    if (is_array($currentValue)) {
                        $value = array_merge($currentValue, $value);
                    }
                }
            }
            $this->configSource->addProperty($settingKey, $value);

            return 0;
        }

        // handle suggest
        if (Preg::isMatch('/^suggest\.(.+)/', $settingKey, $matches)) {
            if ($input->getOption('unset')) {
                $this->configSource->removeProperty($settingKey);

                return 0;
            }

            $this->configSource->addProperty($settingKey, implode(' ', $values));

            return 0;
        }

        // handle unsetting extra/suggest
        if (in_array($settingKey, array('suggest', 'extra'), true) && $input->getOption('unset')) {
            $this->configSource->removeProperty($settingKey);

            return 0;
        }

        // handle platform
        if (Preg::isMatch('/^platform\.(.+)/', $settingKey, $matches)) {
            if ($input->getOption('unset')) {
                $this->configSource->removeConfigSetting($settingKey);

                return 0;
            }

            $this->configSource->addConfigSetting($settingKey, $values[0] === 'false' ? false : $values[0]);

            return 0;
        }

        // handle unsetting platform
        if ($settingKey === 'platform' && $input->getOption('unset')) {
            $this->configSource->removeConfigSetting($settingKey);

            return 0;
        }

        // handle auth
        if (Preg::isMatch('/^(bitbucket-oauth|github-oauth|gitlab-oauth|gitlab-token|http-basic|bearer)\.(.+)/', $settingKey, $matches)) {
            if ($input->getOption('unset')) {
                $this->authConfigSource->removeConfigSetting($matches[1].'.'.$matches[2]);
                $this->configSource->removeConfigSetting($matches[1].'.'.$matches[2]);

                return 0;
            }

            if ($matches[1] === 'bitbucket-oauth') {
                if (2 !== count($values)) {
                    throw new \RuntimeException('Expected two arguments (consumer-key, consumer-secret), got '.count($values));
                }
                $this->configSource->removeConfigSetting($matches[1].'.'.$matches[2]);
                $this->authConfigSource->addConfigSetting($matches[1].'.'.$matches[2], array('consumer-key' => $values[0], 'consumer-secret' => $values[1]));
            } elseif ($matches[1] === 'gitlab-token' && 2 === count($values)) {
                $this->configSource->removeConfigSetting($matches[1].'.'.$matches[2]);
                $this->authConfigSource->addConfigSetting($matches[1].'.'.$matches[2], array('username' => $values[0], 'token' => $values[1]));
            } elseif (in_array($matches[1], array('github-oauth', 'gitlab-oauth', 'gitlab-token', 'bearer'), true)) {
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

            return 0;
        }

        // handle script
        if (Preg::isMatch('/^scripts\.(.+)/', $settingKey, $matches)) {
            if ($input->getOption('unset')) {
                $this->configSource->removeProperty($settingKey);

                return 0;
            }

            $this->configSource->addProperty($settingKey, count($values) > 1 ? $values : $values[0]);

            return 0;
        }

        // handle unsetting other top level properties
        if ($input->getOption('unset')) {
            $this->configSource->removeProperty($settingKey);

            return 0;
        }

        throw new \InvalidArgumentException('Setting '.$settingKey.' does not exist or is not supported by this command');
    }

    /**
     * @param string $key
     * @param array{callable, callable} $callbacks Validator and normalizer callbacks
     * @param array<string> $values
     * @param string $method
     *
     * @return void
     */
    protected function handleSingleValue(string $key, array $callbacks, array $values, string $method): void
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

        $normalizedValue = $normalizer($values[0]);

        if ($key === 'disable-tls') {
            if (!$normalizedValue && $this->config->get('disable-tls')) {
                $this->getIO()->writeError('<info>You are now running Composer with SSL/TLS protection enabled.</info>');
            } elseif ($normalizedValue && !$this->config->get('disable-tls')) {
                $this->getIO()->writeError('<warning>You are now running Composer with SSL/TLS protection disabled.</warning>');
            }
        }

        call_user_func(array($this->configSource, $method), $key, $normalizedValue);
    }

    /**
     * @param string $key
     * @param array{callable, callable} $callbacks Validator and normalizer callbacks
     * @param array<string> $values
     * @param string $method
     *
     * @return void
     */
    protected function handleMultiValue(string $key, array $callbacks, array $values, string $method): void
    {
        list($validator, $normalizer) = $callbacks;
        if (true !== $validation = $validator($values)) {
            throw new \RuntimeException(sprintf(
                '%s is an invalid value'.($validation ? ' ('.$validation.')' : ''),
                json_encode($values)
            ));
        }

        call_user_func(array($this->configSource, $method), $key, $normalizer($values));
    }

    /**
     * Display the contents of the file in a pretty formatted way
     *
     * @param array<mixed[]|bool|string> $contents
     * @param array<mixed[]|string>      $rawContents
     * @param string|null                $k
     * @param bool                       $showSource
     *
     * @return void
     */
    protected function listConfiguration(array $contents, array $rawContents, OutputInterface $output, ?string $k = null, bool $showSource = false): void
    {
        $origK = $k;
        $io = $this->getIO();
        foreach ($contents as $key => $value) {
            if ($k === null && !in_array($key, array('config', 'repositories'))) {
                continue;
            }

            $rawVal = $rawContents[$key] ?? null;

            if (is_array($value) && (!is_numeric(key($value)) || ($key === 'repositories' && null === $k))) {
                $k .= Preg::replace('{^config\.}', '', $key . '.');
                $this->listConfiguration($value, $rawVal, $output, $k, $showSource);
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

            $source = '';
            if ($showSource) {
                $source = ' (' . $this->config->getSourceOfValue($k . $key) . ')';
            }

            if (null !== $k && 0 === strpos($k, 'repositories')) {
                $link = 'https://getcomposer.org/doc/05-repositories.md';
            } else {
                $id = Preg::replace('{\..*$}', '', $k === '' || $k === null ? (string) $key : $k);
                $id = Preg::replace('{[^a-z0-9]}i', '-', strtolower(trim($id)));
                $id = Preg::replace('{-+}', '-', $id);
                $link = 'https://getcomposer.org/doc/06-config.md#' . $id;
            }
            if (is_string($rawVal) && $rawVal != $value) {
                $io->write('[<fg=yellow;href=' . $link .'>' . $k . $key . '</>] <info>' . $rawVal . ' (' . $value . ')</info>' . $source, true, IOInterface::QUIET);
            } else {
                $io->write('[<fg=yellow;href=' . $link .'>' . $k . $key . '</>] <info>' . $value . '</info>' . $source, true, IOInterface::QUIET);
            }
        }
    }
}
