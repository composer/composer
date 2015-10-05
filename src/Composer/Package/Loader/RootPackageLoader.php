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
use Composer\Factory;
use Composer\Package\Version\VersionGuesser;
use Composer\Semver\VersionParser;
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

    public function load(array $config, $class = 'Composer\Package\RootPackage')
    {
        if (!isset($config['name'])) {
            $config['name'] = '__root__';
        }
        $autoVersioned = false;
        if (!isset($config['version'])) {
            // override with env var if available
            if (getenv('COMPOSER_ROOT_VERSION')) {
                $version = getenv('COMPOSER_ROOT_VERSION');
            } else {
                $version = $this->versionGuesser->guessVersion($config, getcwd());
            }

            if (!$version) {
                $version = '1.0.0';
                $autoVersioned = true;
            }

            $config['version'] = $version;
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

        $realPackage->setAliases($aliases);
        $realPackage->setStabilityFlags($stabilityFlags);
        $realPackage->setReferences($references);

        if (isset($config['prefer-stable'])) {
            $realPackage->setPreferStable((bool) $config['prefer-stable']);
        }

        $repos = Factory::createDefaultRepositories(null, $this->config, $this->manager);
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
            // parse explicit stability flags to the most unstable
            if (preg_match('{^[^@]*?@('.implode('|', array_keys($stabilities)).')$}i', $reqVersion, $match)) {
                $name = strtolower($reqName);
                $stability = $stabilities[VersionParser::normalizeStability($match[1])];

                if (isset($stabilityFlags[$name]) && $stabilityFlags[$name] > $stability) {
                    continue;
                }
                $stabilityFlags[$name] = $stability;

                continue;
            }

            // infer flags for requirements that have an explicit -dev or -beta version specified but only
            // for those that are more unstable than the minimumStability or existing flags
            $reqVersion = preg_replace('{^([^,\s@]+) as .+$}', '$1', $reqVersion);
            if (preg_match('{^[^,\s@]+$}', $reqVersion) && 'stable' !== ($stabilityName = VersionParser::parseStability($reqVersion))) {
                $name = strtolower($reqName);
                $stability = $stabilities[$stabilityName];
                if ((isset($stabilityFlags[$name]) && $stabilityFlags[$name] > $stability) || ($minimumStability > $stability)) {
                    continue;
                }
                $stabilityFlags[$name] = $stability;
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
