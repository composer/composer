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

namespace Composer\Package\Loader;

use Composer\Package\BasePackage;
use Composer\Package\AliasPackage;
use Composer\Config;
use Composer\Package\RootPackageInterface;
use Composer\Repository\RepositoryFactory;
use Composer\Package\Version\VersionGuesser;
use Composer\Package\Version\VersionParser;
use Composer\Repository\RepositoryManager;
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

    public function __construct(RepositoryManager $manager, Config $config, VersionParser $parser = null, VersionGuesser $versionGuesser = null)
    {
        parent::__construct($parser);

        $this->manager = $manager;
        $this->config = $config;
        $this->versionGuesser = $versionGuesser ?: new VersionGuesser($config, new ProcessExecutor(), $this->versionParser);
    }

    /**
     * @param  array                $config package data
     * @param  string               $class  FQCN to be instantiated
     * @param  string               $cwd    cwd of the root package to be used to guess the version if it is not provided
     * @return RootPackageInterface
     */
    public function load(array $config, $class = 'Composer\Package\RootPackage', $cwd = null)
    {
        if (!isset($config['name'])) {
            $config['name'] = '__root__';
        }
        $autoVersioned = false;
        if (!isset($config['version'])) {
            $commit = null;

            // override with env var if available
            if (getenv('COMPOSER_ROOT_VERSION')) {
                $config['version'] = getenv('COMPOSER_ROOT_VERSION');
            } else {
                $versionData = $this->versionGuesser->guessVersion($config, $cwd ?: getcwd());
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
                $config['source'] = array(
                    'type' => '',
                    'url' => '',
                    'reference' => $commit,
                );
                $config['dist'] = array(
                    'type' => '',
                    'url' => '',
                    'reference' => $commit,
                );
            }
        }

        $realPackage = $package = parent::load($config, $class);
        if ($realPackage instanceof AliasPackage) {
            $realPackage = $package->getAliasOf();
        }

        if ($autoVersioned) {
            $realPackage->replaceVersion($realPackage->getVersion(), 'No version set (parsed as 1.0.0)');
        }

        if (isset($config['minimum-stability'])) {
            $realPackage->setMinimumStability(VersionParser::normalizeStability($config['minimum-stability']));
        }

        $aliases = array();
        $stabilityFlags = array();
        $references = array();
        foreach (array('require', 'require-dev') as $linkType) {
            if (isset($config[$linkType])) {
                $linkInfo = BasePackage::$supportedLinkTypes[$linkType];
                $method = 'get'.ucfirst($linkInfo['method']);
                $links = array();
                foreach ($realPackage->$method() as $link) {
                    $links[$link->getTarget()] = $link->getConstraint()->getPrettyString();
                }
                $aliases = $this->extractAliases($links, $aliases);
                $stabilityFlags = $this->extractStabilityFlags($links, $stabilityFlags, $realPackage->getMinimumStability());
                $references = $this->extractReferences($links, $references);
            }
        }

        if (isset($links[$config['name']])) {
            throw new \InvalidArgumentException(sprintf('Root package \'%s\' cannot require itself in its composer.json' . PHP_EOL .
                        'Did you accidentally name your root package after an external package?', $config['name']));
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

    private function extractAliases(array $requires, array $aliases)
    {
        foreach ($requires as $reqName => $reqVersion) {
            if (preg_match('{^([^,\s#]+)(?:#[^ ]+)? +as +([^,\s]+)$}', $reqVersion, $match)) {
                $aliases[] = array(
                    'package' => strtolower($reqName),
                    'version' => $this->versionParser->normalize($match[1], $reqVersion),
                    'alias' => $match[2],
                    'alias_normalized' => $this->versionParser->normalize($match[2], $reqVersion),
                );
            }
        }

        return $aliases;
    }

    private function extractStabilityFlags(array $requires, array $stabilityFlags, $minimumStability)
    {
        $stabilities = BasePackage::$stabilities;
        $minimumStability = $stabilities[$minimumStability];
        foreach ($requires as $reqName => $reqVersion) {
            $constraints = array();

            // extract all sub-constraints in case it is an OR/AND multi-constraint
            $orSplit = preg_split('{\s*\|\|?\s*}', trim($reqVersion));
            foreach ($orSplit as $orConstraint) {
                $andSplit = preg_split('{(?<!^|as|[=>< ,]) *(?<!-)[, ](?!-) *(?!,|as|$)}', $orConstraint);
                foreach ($andSplit as $andConstraint) {
                    $constraints[] = $andConstraint;
                }
            }

            // parse explicit stability flags to the most unstable
            $match = false;
            foreach ($constraints as $constraint) {
                if (preg_match('{^[^@]*?@('.implode('|', array_keys($stabilities)).')$}i', $constraint, $match)) {
                    $name = strtolower($reqName);
                    $stability = $stabilities[VersionParser::normalizeStability($match[1])];

                    if (isset($stabilityFlags[$name]) && $stabilityFlags[$name] > $stability) {
                        continue;
                    }
                    $stabilityFlags[$name] = $stability;
                    $match = true;
                }
            }

            if ($match) {
                continue;
            }

            foreach ($constraints as $constraint) {
                // infer flags for requirements that have an explicit -dev or -beta version specified but only
                // for those that are more unstable than the minimumStability or existing flags
                $reqVersion = preg_replace('{^([^,\s@]+) as .+$}', '$1', $constraint);
                if (preg_match('{^[^,\s@]+$}', $reqVersion) && 'stable' !== ($stabilityName = VersionParser::parseStability($reqVersion))) {
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

    private function extractReferences(array $requires, array $references)
    {
        foreach ($requires as $reqName => $reqVersion) {
            $reqVersion = preg_replace('{^([^,\s@]+) as .+$}', '$1', $reqVersion);
            if (preg_match('{^[^,\s@]+?#([a-f0-9]+)$}', $reqVersion, $match) && 'dev' === ($stabilityName = VersionParser::parseStability($reqVersion))) {
                $name = strtolower($reqName);
                $references[$name] = $match[1];
            }
        }

        return $references;
    }
}
