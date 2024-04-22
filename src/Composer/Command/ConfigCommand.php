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

use Composer\Advisory\Auditor;
use Composer\Pcre\Preg;
use Composer\Util\Filesystem;
use Composer\Util\Platform;
use Composer\Util\Silencer;
use Symfony\Component\Console\Input\InputInterface;
use Composer\Console\Input\InputArgument;
use Symfony\Component\Console\Completion\CompletionInput;
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
     * List of additional configurable package-properties
     *
     * @var string[]
     */
    protected const CONFIGURABLE_PACKAGE_PROPERTIES = [
        'name',
        'type',
        'description',
        'homepage',
        'version',
        'minimum-stability',
        'prefer-stable',
        'keywords',
        'license',
        'repositories',
        'suggest',
        'extra',
    ];

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

    protected function configure(): void
    {
        $this
            ->setName('config')
            ->setDescription('Sets config options')
            ->setDefinition([
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
                new InputArgument('setting-key', null, 'Setting key', null, $this->suggestSettingKeys()),
                new InputArgument('setting-value', InputArgument::IS_ARRAY, 'Setting value'),
            ])
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

        $configFile = $this->getComposerConfigFile($input, $this->config);

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

        $authConfigFile = $this->getAuthConfigFile($input, $this->config);

        $this->authConfigFile = new JsonFile($authConfigFile, null, $io);
        $this->authConfigSource = new JsonConfigSource($this->authConfigFile, true);

        // Initialize the global file if it's not there, ignoring any warnings or notices
        if ($input->getOption('global') && !$this->configFile->exists()) {
            touch($this->configFile->getPath());
            $this->configFile->write(['config' => new \ArrayObject]);
            Silencer::call('chmod', $this->configFile->getPath(), 0600);
        }
        if ($input->getOption('global') && !$this->authConfigFile->exists()) {
            touch($this->authConfigFile->getPath());
            $this->authConfigFile->write(['bitbucket-oauth' => new \ArrayObject, 'github-oauth' => new \ArrayObject, 'gitlab-oauth' => new \ArrayObject, 'gitlab-token' => new \ArrayObject, 'http-basic' => new \ArrayObject, 'bearer' => new \ArrayObject]);
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
                    foreach (['editor', 'vim', 'vi', 'nano', 'pico', 'ed'] as $candidate) {
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
            $this->config->merge(['config' => $this->authConfigFile->exists() ? $this->authConfigFile->read() : []], $this->authConfigFile->getPath());
        }

        $this->getIO()->loadConfiguration($this->config);

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
        if ([] !== $input->getArgument('setting-value') && $input->getOption('unset')) {
            throw new \RuntimeException('You can not combine a setting value with --unset');
        }

        // show the value if no value is provided
        if ([] === $input->getArgument('setting-value') && !$input->getOption('unset')) {
            $properties = self::CONFIGURABLE_PACKAGE_PROPERTIES;
            $propertiesDefaults = [
                'type' => 'library',
                'description' => '',
                'homepage' => '',
                'minimum-stability' => 'stable',
                'prefer-stable' => false,
                'keywords' => [],
                'license' => [],
                'suggest' => [],
                'extra' => [],
            ];
            $rawData = $this->configFile->read();
            $data = $this->config->all();
            $source = $this->config->getSourceOfValue($settingKey);

            if (Preg::isMatch('/^repos?(?:itories)?(?:\.(.+))?/', $settingKey, $matches)) {
                if (!isset($matches[1]) || $matches[1] === '') {
                    $value = $data['repositories'] ?? [];
                } else {
                    if (!isset($data['repositories'][$matches[1]])) {
                        throw new \InvalidArgumentException('There is no '.$matches[1].' repository defined');
                    }

                    $value = $data['repositories'][$matches[1]];
                }
            } elseif (strpos($settingKey, '.')) {
                $bits = explode('.', $settingKey);
                if ($bits[0] === 'extra' || $bits[0] === 'suggest') {
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
                // ensure we get {} output for properties which are objects
                if ($value === []) {
                    $schema = JsonFile::parseJson((string) file_get_contents(JsonFile::COMPOSER_SCHEMA_PATH));
                    if (
                        isset($schema['properties']['config']['properties'][$settingKey]['type'])
                        && in_array('object', (array) $schema['properties']['config']['properties'][$settingKey]['type'], true)
                    ) {
                        $value = new \stdClass;
                    }
                }
            } elseif (isset($rawData[$settingKey]) && in_array($settingKey, $properties, true)) {
                $value = $rawData[$settingKey];
                $source = $this->configFile->getPath();
            } elseif (isset($propertiesDefaults[$settingKey])) {
                $value = $propertiesDefaults[$settingKey];
                $source = 'defaults';
            } else {
                throw new \RuntimeException($settingKey.' is not defined');
            }

            if (is_array($value) || is_object($value) || is_bool($value)) {
                $value = JsonFile::encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }

            $sourceOfConfigValue = '';
            if ($input->getOption('source')) {
                $sourceOfConfigValue = ' (' . $source . ')';
            }

            $this->getIO()->write($value . $sourceOfConfigValue, true, IOInterface::QUIET);

            return 0;
        }

        $values = $input->getArgument('setting-value'); // what the user is trying to add/change

        $booleanValidator = static function ($val): bool {
            return in_array($val, ['true', 'false', '1', '0'], true);
        };
        $booleanNormalizer = static function ($val): bool {
            return $val !== 'false' && (bool) $val;
        };

        // handle config values
        $uniqueConfigValues = [
            'process-timeout' => ['is_numeric', 'intval'],
            'use-include-path' => [$booleanValidator, $booleanNormalizer],
            'use-github-api' => [$booleanValidator, $booleanNormalizer],
            'preferred-install' => [
                static function ($val): bool {
                    return in_array($val, ['auto', 'source', 'dist'], true);
                },
                static function ($val) {
                    return $val;
                },
            ],
            'gitlab-protocol' => [
                static function ($val): bool {
                    return in_array($val, ['git', 'http', 'https'], true);
                },
                static function ($val) {
                    return $val;
                },
            ],
            'store-auths' => [
                static function ($val): bool {
                    return in_array($val, ['true', 'false', 'prompt'], true);
                },
                static function ($val) {
                    if ('prompt' === $val) {
                        return 'prompt';
                    }

                    return $val !== 'false' && (bool) $val;
                },
            ],
            'notify-on-install' => [$booleanValidator, $booleanNormalizer],
            'vendor-dir' => ['is_string', static function ($val) {
                return $val;
            }],
            'bin-dir' => ['is_string', static function ($val) {
                return $val;
            }],
            'archive-dir' => ['is_string', static function ($val) {
                return $val;
            }],
            'archive-format' => ['is_string', static function ($val) {
                return $val;
            }],
            'data-dir' => ['is_string', static function ($val) {
                return $val;
            }],
            'cache-dir' => ['is_string', static function ($val) {
                return $val;
            }],
            'cache-files-dir' => ['is_string', static function ($val) {
                return $val;
            }],
            'cache-repo-dir' => ['is_string', static function ($val) {
                return $val;
            }],
            'cache-vcs-dir' => ['is_string', static function ($val) {
                return $val;
            }],
            'cache-ttl' => ['is_numeric', 'intval'],
            'cache-files-ttl' => ['is_numeric', 'intval'],
            'cache-files-maxsize' => [
                static function ($val): bool {
                    return Preg::isMatch('/^\s*([0-9.]+)\s*(?:([kmg])(?:i?b)?)?\s*$/i', $val);
                },
                static function ($val) {
                    return $val;
                },
            ],
            'bin-compat' => [
                static function ($val): bool {
                    return in_array($val, ['auto', 'full', 'proxy', 'symlink']);
                },
                static function ($val) {
                    return $val;
                },
            ],
            'discard-changes' => [
                static function ($val): bool {
                    return in_array($val, ['stash', 'true', 'false', '1', '0'], true);
                },
                static function ($val) {
                    if ('stash' === $val) {
                        return 'stash';
                    }

                    return $val !== 'false' && (bool) $val;
                },
            ],
            'autoloader-suffix' => ['is_string', static function ($val) {
                return $val === 'null' ? null : $val;
            }],
            'sort-packages' => [$booleanValidator, $booleanNormalizer],
            'optimize-autoloader' => [$booleanValidator, $booleanNormalizer],
            'classmap-authoritative' => [$booleanValidator, $booleanNormalizer],
            'apcu-autoloader' => [$booleanValidator, $booleanNormalizer],
            'prepend-autoloader' => [$booleanValidator, $booleanNormalizer],
            'disable-tls' => [$booleanValidator, $booleanNormalizer],
            'secure-http' => [$booleanValidator, $booleanNormalizer],
            'bump-after-update' => [$booleanValidator, $booleanNormalizer],
            'cafile' => [
                static function ($val): bool {
                    return file_exists($val) && Filesystem::isReadable($val);
                },
                static function ($val) {
                    return $val === 'null' ? null : $val;
                },
            ],
            'capath' => [
                static function ($val): bool {
                    return is_dir($val) && Filesystem::isReadable($val);
                },
                static function ($val) {
                    return $val === 'null' ? null : $val;
                },
            ],
            'github-expose-hostname' => [$booleanValidator, $booleanNormalizer],
            'htaccess-protect' => [$booleanValidator, $booleanNormalizer],
            'lock' => [$booleanValidator, $booleanNormalizer],
            'allow-plugins' => [$booleanValidator, $booleanNormalizer],
            'platform-check' => [
                static function ($val): bool {
                    return in_array($val, ['php-only', 'true', 'false', '1', '0'], true);
                },
                static function ($val) {
                    if ('php-only' === $val) {
                        return 'php-only';
                    }

                    return $val !== 'false' && (bool) $val;
                },
            ],
            'use-parent-dir' => [
                static function ($val): bool {
                    return in_array($val, ['true', 'false', 'prompt'], true);
                },
                static function ($val) {
                    if ('prompt' === $val) {
                        return 'prompt';
                    }

                    return $val !== 'false' && (bool) $val;
                },
            ],
            'audit.abandoned' => [
                static function ($val): bool {
                    return in_array($val, [Auditor::ABANDONED_IGNORE, Auditor::ABANDONED_REPORT, Auditor::ABANDONED_FAIL], true);
                },
                static function ($val) {
                    return $val;
                },
            ],
        ];
        $multiConfigValues = [
            'github-protocols' => [
                static function ($vals) {
                    if (!is_array($vals)) {
                        return 'array expected';
                    }

                    foreach ($vals as $val) {
                        if (!in_array($val, ['git', 'https', 'ssh'])) {
                            return 'valid protocols include: git, https, ssh';
                        }
                    }

                    return true;
                },
                static function ($vals) {
                    return $vals;
                },
            ],
            'github-domains' => [
                static function ($vals) {
                    if (!is_array($vals)) {
                        return 'array expected';
                    }

                    return true;
                },
                static function ($vals) {
                    return $vals;
                },
            ],
            'gitlab-domains' => [
                static function ($vals) {
                    if (!is_array($vals)) {
                        return 'array expected';
                    }

                    return true;
                },
                static function ($vals) {
                    return $vals;
                },
            ],
            'audit.ignore' => [
                static function ($vals) {
                    if (!is_array($vals)) {
                        return 'array expected';
                    }

                    return true;
                },
                static function ($vals) {
                    return $vals;
                },
            ],
        ];

        // allow unsetting audit config entirely
        if ($input->getOption('unset') && $settingKey === 'audit') {
            $this->configSource->removeConfigSetting($settingKey);

            return 0;
        }

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

            [$validator] = $uniqueConfigValues['preferred-install'];
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
        $uniqueProps = [
            'name' => ['is_string', static function ($val) {
                return $val;
            }],
            'type' => ['is_string', static function ($val) {
                return $val;
            }],
            'description' => ['is_string', static function ($val) {
                return $val;
            }],
            'homepage' => ['is_string', static function ($val) {
                return $val;
            }],
            'version' => ['is_string', static function ($val) {
                return $val;
            }],
            'minimum-stability' => [
                static function ($val): bool {
                    return isset(BasePackage::$stabilities[VersionParser::normalizeStability($val)]);
                },
                static function ($val): string {
                    return VersionParser::normalizeStability($val);
                },
            ],
            'prefer-stable' => [$booleanValidator, $booleanNormalizer],
        ];
        $multiProps = [
            'keywords' => [
                static function ($vals) {
                    if (!is_array($vals)) {
                        return 'array expected';
                    }

                    return true;
                },
                static function ($vals) {
                    return $vals;
                },
            ],
            'license' => [
                static function ($vals) {
                    if (!is_array($vals)) {
                        return 'array expected';
                    }

                    return true;
                },
                static function ($vals) {
                    return $vals;
                },
            ],
        ];

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
        if (Preg::isMatchStrictGroups('/^repos?(?:itories)?\.(.+)/', $settingKey, $matches)) {
            if ($input->getOption('unset')) {
                $this->configSource->removeRepository($matches[1]);

                return 0;
            }

            if (2 === count($values)) {
                $this->configSource->addRepository($matches[1], [
                    'type' => $values[0],
                    'url' => $values[1],
                ], $input->getOption('append'));

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
                    if (is_array($currentValue) && is_array($value)) {
                        if (array_is_list($currentValue) && array_is_list($value)) {
                            $value = array_merge($currentValue, $value);
                        } else {
                            $value = $value + $currentValue;
                        }
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
        if (in_array($settingKey, ['suggest', 'extra'], true) && $input->getOption('unset')) {
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
                $this->authConfigSource->addConfigSetting($matches[1].'.'.$matches[2], ['consumer-key' => $values[0], 'consumer-secret' => $values[1]]);
            } elseif ($matches[1] === 'gitlab-token' && 2 === count($values)) {
                $this->configSource->removeConfigSetting($matches[1].'.'.$matches[2]);
                $this->authConfigSource->addConfigSetting($matches[1].'.'.$matches[2], ['username' => $values[0], 'token' => $values[1]]);
            } elseif (in_array($matches[1], ['github-oauth', 'gitlab-oauth', 'gitlab-token', 'bearer'], true)) {
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
                $this->authConfigSource->addConfigSetting($matches[1].'.'.$matches[2], ['username' => $values[0], 'password' => $values[1]]);
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
     * @param array{callable, callable} $callbacks Validator and normalizer callbacks
     * @param array<string> $values
     */
    protected function handleSingleValue(string $key, array $callbacks, array $values, string $method): void
    {
        [$validator, $normalizer] = $callbacks;
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

        call_user_func([$this->configSource, $method], $key, $normalizedValue);
    }

    /**
     * @param array{callable, callable} $callbacks Validator and normalizer callbacks
     * @param array<string> $values
     */
    protected function handleMultiValue(string $key, array $callbacks, array $values, string $method): void
    {
        [$validator, $normalizer] = $callbacks;
        if (true !== $validation = $validator($values)) {
            throw new \RuntimeException(sprintf(
                '%s is an invalid value'.($validation ? ' ('.$validation.')' : ''),
                json_encode($values)
            ));
        }

        call_user_func([$this->configSource, $method], $key, $normalizer($values));
    }

    /**
     * Display the contents of the file in a pretty formatted way
     *
     * @param array<mixed[]|bool|string> $contents
     * @param array<mixed[]|string>      $rawContents
     */
    protected function listConfiguration(array $contents, array $rawContents, OutputInterface $output, ?string $k = null, bool $showSource = false): void
    {
        $origK = $k;
        $io = $this->getIO();
        foreach ($contents as $key => $value) {
            if ($k === null && !in_array($key, ['config', 'repositories'])) {
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
                $value = array_map(static function ($val) {
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
            if (is_string($rawVal) && $rawVal !== $value) {
                $io->write('[<fg=yellow;href=' . $link .'>' . $k . $key . '</>] <info>' . $rawVal . ' (' . $value . ')</info>' . $source, true, IOInterface::QUIET);
            } else {
                $io->write('[<fg=yellow;href=' . $link .'>' . $k . $key . '</>] <info>' . $value . '</info>' . $source, true, IOInterface::QUIET);
            }
        }
    }

    /**
     * Get the local composer.json, global config.json, or the file passed by the user
     */
    private function getComposerConfigFile(InputInterface $input, Config $config): string
    {
        return $input->getOption('global')
            ? ($config->get('home') . '/config.json')
            : ($input->getOption('file') ?: Factory::getComposerFile())
        ;
    }

    /**
     * Get the local auth.json or global auth.json, or if the user passed in a file to use,
     * the corresponding auth.json
     */
    private function getAuthConfigFile(InputInterface $input, Config $config): string
    {
        return $input->getOption('global')
            ? ($config->get('home') . '/auth.json')
            : dirname($this->getComposerConfigFile($input, $config)) . '/auth.json'
        ;
    }

    /**
     * Suggest setting-keys, while taking given options in acount.
     */
    private function suggestSettingKeys(): \Closure
    {
        return function (CompletionInput $input): array {
            if ($input->getOption('list') || $input->getOption('editor') || $input->getOption('auth')) {
                return [];
            }

            // initialize configuration
            $config = Factory::createConfig();

            // load configuration
            $configFile = new JsonFile($this->getComposerConfigFile($input, $config));
            if ($configFile->exists()) {
                $config->merge($configFile->read(), $configFile->getPath());
            }

            // load auth-configuration
            $authConfigFile = new JsonFile($this->getAuthConfigFile($input, $config));
            if ($authConfigFile->exists()) {
                $config->merge(['config' => $authConfigFile->read()], $authConfigFile->getPath());
            }

            // collect all configuration setting-keys
            $rawConfig = $config->raw();
            $keys = array_merge(
                $this->flattenSettingKeys($rawConfig['config']),
                $this->flattenSettingKeys($rawConfig['repositories'], 'repositories.')
            );

            // if unsetting …
            if ($input->getOption('unset')) {
                // … keep only the currently customized setting-keys …
                $sources = [$configFile->getPath(), $authConfigFile->getPath()];
                $keys = array_filter(
                    $keys,
                    static function (string $key) use ($config, $sources): bool {
                        return in_array($config->getSourceOfValue($key), $sources, true);
                    }
                );

            // … else if showing or setting a value …
            } else {
                // … add all configurable package-properties, no matter if it exist
                $keys = array_merge($keys, self::CONFIGURABLE_PACKAGE_PROPERTIES);

                // it would be nice to distinguish between showing and setting
                // a value, but that makes the implementation much more complex
                // and partially impossible because symfony's implementation
                // does not complete arguments followed by other arguments
            }

            // add all existing configurable package-properties
            if ($configFile->exists()) {
                $properties = array_filter(
                    $configFile->read(),
                    static function (string $key): bool {
                        return in_array($key, self::CONFIGURABLE_PACKAGE_PROPERTIES, true);
                    },
                    ARRAY_FILTER_USE_KEY
                );

                $keys = array_merge(
                    $keys,
                    $this->flattenSettingKeys($properties)
                );
            }

            // filter settings-keys by completion value
            $completionValue = $input->getCompletionValue();

            if ($completionValue !== '') {
                $keys = array_filter(
                    $keys,
                    static function (string $key) use ($completionValue): bool {
                        return str_starts_with($key, $completionValue);
                    }
                );
            }

            sort($keys);

            return array_unique($keys);
        };
    }

    /**
     * build a flat list of dot-separated setting-keys from given config
     *
     * @param array<mixed[]|string>  $config
     * @return string[]
     */
    private function flattenSettingKeys(array $config, string $prefix = ''): array
    {
        $keys = [];
        foreach ($config as $key => $value) {
            $keys[] = [$prefix . $key];
            // array-lists must not be added to completion
            // sub-keys of repository-keys must not be added to completion
            if (is_array($value) && !array_is_list($value) && $prefix !== 'repositories.') {
                $keys[] = $this->flattenSettingKeys($value, $prefix . $key . '.');
            }
        }

        return array_merge(...$keys);
    }
}
