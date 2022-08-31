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

namespace Composer\Package\Loader;

use Composer\Package\BasePackage;
use Composer\Config;
use Composer\IO\IOInterface;
use Composer\Package\RootAliasPackage;
use Composer\Pcre\Preg;
use Composer\Repository\RepositoryFactory;
use Composer\Package\Version\VersionGuesser;
use Composer\Package\Version\VersionParser;
use Composer\Package\RootPackage;
use Composer\Repository\RepositoryManager;
use Composer\Util\Platform;
use Composer\Util\ProcessExecutor;

/**
 * ArrayLoader built for the sole purpose of loading the root package
 *
 * Sets additional defaults and loads repositories
 *
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class RootPackageLoader extends ArrayLoader
{
    /**
     * @var RepositoryManager
     */
    private $manager;

    /**
     * @var Config
     */
    private $config;

    /**
     * @var VersionGuesser
     */
    private $versionGuesser;

    public function __construct(RepositoryManager $manager, Config $config, ?VersionParser $parser = null, ?VersionGuesser $versionGuesser = null, ?IOInterface $io = null)
    {
        parent::__construct($parser);

        $this->manager = $manager;
        $this->config = $config;
        $this->versionGuesser = $versionGuesser ?: new VersionGuesser($config, new ProcessExecutor($io), $this->versionParser);
    }

    /**
     * @inheritDoc
     *
     * @return RootPackage|RootAliasPackage
     *
     * @phpstan-param class-string<RootPackage> $class
     */
    public function load(array $config, string $class = 'Composer\Package\RootPackage', ?string $cwd = null): BasePackage
    {
        if ($class !== 'Composer\Package\RootPackage') {
            trigger_error('The $class arg is deprecated, please reach out to Composer maintainers ASAP if you still need this.', E_USER_DEPRECATED);
        }

        if (!isset($config['name'])) {
            $config['name'] = '__root__';
        } elseif ($err = ValidatingArrayLoader::hasPackageNamingError($config['name'])) {
            throw new \RuntimeException('Your package name '.$err);
        }
        $autoVersioned = false;
        if (!isset($config['version'])) {
            $commit = null;

            // override with env var if available
            if (Platform::getEnv('COMPOSER_ROOT_VERSION')) {
                $config['version'] = Platform::getEnv('COMPOSER_ROOT_VERSION');
            } else {
                $versionData = $this->versionGuesser->guessVersion($config, $cwd ?? Platform::getCwd(true));
                if ($versionData) {
                    $config['version'] = $versionData['pretty_version'];
                    $config['version_normalized'] = $versionData['version'];
                    $commit = $versionData['commit'];
                }
            }

            if (!isset($config['version'])) {
                $config['version'] = '1.0.0';
                $autoVersioned = true;
            }

            if ($commit) {
                $config['source'] = [
                    'type' => '',
                    'url' => '',
                    'reference' => $commit,
                ];
                $config['dist'] = [
                    'type' => '',
                    'url' => '',
                    'reference' => $commit,
                ];
            }
        }

        /** @var RootPackage|RootAliasPackage $package */
        $package = parent::load($config, $class);
        if ($package instanceof RootAliasPackage) {
            $realPackage = $package->getAliasOf();
        } else {
            $realPackage = $package;
        }

        if (!$realPackage instanceof RootPackage) {
            throw new \LogicException('Expecting a Composer\Package\RootPackage at this point');
        }

        if ($autoVersioned) {
            $realPackage->replaceVersion($realPackage->getVersion(), RootPackage::DEFAULT_PRETTY_VERSION);
        }

        if (isset($config['minimum-stability'])) {
            $realPackage->setMinimumStability(VersionParser::normalizeStability($config['minimum-stability']));
        }

        $aliases = [];
        $stabilityFlags = [];
        $references = [];
        foreach (['require', 'require-dev'] as $linkType) {
            if (isset($config[$linkType])) {
                $linkInfo = BasePackage::$supportedLinkTypes[$linkType];
                $method = 'get'.ucfirst($linkInfo['method']);
                $links = [];
                foreach ($realPackage->{$method}() as $link) {
                    $links[$link->getTarget()] = $link->getConstraint()->getPrettyString();
                }
                $aliases = $this->extractAliases($links, $aliases);
                $stabilityFlags = self::extractStabilityFlags($links, $realPackage->getMinimumStability(), $stabilityFlags);
                $references = self::extractReferences($links, $references);

                if (isset($links[$config['name']])) {
                    throw new \RuntimeException(sprintf('Root package \'%s\' cannot require itself in its composer.json' . PHP_EOL .
                                'Did you accidentally name your root package after an external package?', $config['name']));
                }
            }
        }

        foreach (array_keys(BasePackage::$supportedLinkTypes) as $linkType) {
            if (isset($config[$linkType])) {
                foreach ($config[$linkType] as $linkName => $constraint) {
                    if ($err = ValidatingArrayLoader::hasPackageNamingError($linkName, true)) {
                        throw new \RuntimeException($linkType.'.'.$err);
                    }
                }
            }
        }

        $realPackage->setAliases($aliases);
        $realPackage->setStabilityFlags($stabilityFlags);
        $realPackage->setReferences($references);

        if (isset($config['prefer-stable'])) {
            $realPackage->setPreferStable((bool) $config['prefer-stable']);
        }

        if (isset($config['config'])) {
            $realPackage->setConfig($config['config']);
        }

        $repos = RepositoryFactory::defaultRepos(null, $this->config, $this->manager);
        foreach ($repos as $repo) {
            $this->manager->addRepository($repo);
        }
        $realPackage->setRepositories($this->config->getRepositories());

        return $package;
    }

    /**
     * @param array<string, string>                                                                  $requires
     * @param list<array{package: string, version: string, alias: string, alias_normalized: string}> $aliases
     *
     * @return list<array{package: string, version: string, alias: string, alias_normalized: string}>
     */
    private function extractAliases(array $requires, array $aliases): array
    {
        foreach ($requires as $reqName => $reqVersion) {
            if (Preg::isMatch('{^([^,\s#]+)(?:#[^ ]+)? +as +([^,\s]+)$}', $reqVersion, $match)) {
                $aliases[] = [
                    'package' => strtolower($reqName),
                    'version' => $this->versionParser->normalize($match[1], $reqVersion),
                    'alias' => $match[2],
                    'alias_normalized' => $this->versionParser->normalize($match[2], $reqVersion),
                ];
            } elseif (strpos($reqVersion, ' as ') !== false) {
                throw new \UnexpectedValueException('Invalid alias definition in "'.$reqName.'": "'.$reqVersion.'". Aliases should be in the form "exact-version as other-exact-version".');
            }
        }

        return $aliases;
    }

    /**
     * @internal
     *
     * @param array<string, string> $requires
     * @param array<string, int>    $stabilityFlags
     *
     * @return array<string, int>
     *
     * @phpstan-param array<string, BasePackage::STABILITY_*> $stabilityFlags
     * @phpstan-return array<string, BasePackage::STABILITY_*>
     */
    public static function extractStabilityFlags(array $requires, string $minimumStability, array $stabilityFlags): array
    {
        $stabilities = BasePackage::$stabilities;
        /** @var int $minimumStability */
        $minimumStability = $stabilities[$minimumStability];
        foreach ($requires as $reqName => $reqVersion) {
            $constraints = [];

            // extract all sub-constraints in case it is an OR/AND multi-constraint
            $orSplit = Preg::split('{\s*\|\|?\s*}', trim($reqVersion));
            foreach ($orSplit as $orConstraint) {
                $andSplit = Preg::split('{(?<!^|as|[=>< ,]) *(?<!-)[, ](?!-) *(?!,|as|$)}', $orConstraint);
                foreach ($andSplit as $andConstraint) {
                    $constraints[] = $andConstraint;
                }
            }

            // parse explicit stability flags to the most unstable
            $matched = false;
            foreach ($constraints as $constraint) {
                if (Preg::isMatch('{^[^@]*?@('.implode('|', array_keys($stabilities)).')$}i', $constraint, $match)) {
                    $name = strtolower($reqName);
                    $stability = $stabilities[VersionParser::normalizeStability($match[1])];

                    if (isset($stabilityFlags[$name]) && $stabilityFlags[$name] > $stability) {
                        continue;
                    }
                    $stabilityFlags[$name] = $stability;
                    $matched = true;
                }
            }

            if ($matched) {
                continue;
            }

            foreach ($constraints as $constraint) {
                // infer flags for requirements that have an explicit -dev or -beta version specified but only
                // for those that are more unstable than the minimumStability or existing flags
                $reqVersion = Preg::replace('{^([^,\s@]+) as .+$}', '$1', $constraint);
                if (Preg::isMatch('{^[^,\s@]+$}', $reqVersion) && 'stable' !== ($stabilityName = VersionParser::parseStability($reqVersion))) {
                    $name = strtolower($reqName);
                    $stability = $stabilities[$stabilityName];
                    if ((isset($stabilityFlags[$name]) && $stabilityFlags[$name] > $stability) || ($minimumStability > $stability)) {
                        continue;
                    }
                    $stabilityFlags[$name] = $stability;
                }
            }
        }

        return $stabilityFlags;
    }

    /**
     * @internal
     *
     * @param array<string, string> $requires
     * @param array<string, string> $references
     *
     * @return array<string, string>
     */
    public static function extractReferences(array $requires, array $references): array
    {
        foreach ($requires as $reqName => $reqVersion) {
            $reqVersion = Preg::replace('{^([^,\s@]+) as .+$}', '$1', $reqVersion);
            if (Preg::isMatch('{^[^,\s@]+?#([a-f0-9]+)$}', $reqVersion, $match) && 'dev' === VersionParser::parseStability($reqVersion)) {
                $name = strtolower($reqName);
                $references[$name] = $match[1];
            }
        }

        return $references;
    }
}
