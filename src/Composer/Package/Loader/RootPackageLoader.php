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
use Composer\Config;
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
    private $manager;
    private $config;
    private $process;

    public function __construct(RepositoryManager $manager, Config $config, VersionParser $parser = null, ProcessExecutor $process = null)
    {
        $this->manager = $manager;
        $this->config = $config;
        $this->process = $process ?: new ProcessExecutor();
        parent::__construct($parser);
    }

    public function load($config)
    {
        if (!isset($config['name'])) {
            $config['name'] = '__root__';
        }
        if (!isset($config['version'])) {
            // override with env var if available
            if (getenv('COMPOSER_ROOT_VERSION')) {
                $version = getenv('COMPOSER_ROOT_VERSION');
            } else {
                $version = $this->guessVersion($config);
            }

            if (!$version) {
                $version = '1.0.0';
            }

            $config['version'] = $version;
        } else {
            $version = $config['version'];
        }

        $package = parent::load($config);

        $aliases = array();
        $stabilityFlags = array();
        $references = array();
        foreach (array('require', 'require-dev') as $linkType) {
            if (isset($config[$linkType])) {
                $aliases = $this->extractAliases($config[$linkType], $aliases);
                $stabilityFlags = $this->extractStabilityFlags($config[$linkType], $stabilityFlags);
                $references = $this->extractReferences($config[$linkType], $references);
            }
        }

        $package->setAliases($aliases);
        $package->setStabilityFlags($stabilityFlags);
        $package->setReferences($references);

        if (isset($config['minimum-stability'])) {
            $package->setMinimumStability(VersionParser::normalizeStability($config['minimum-stability']));
        }

        $defaultRepositories = array_keys($this->config->getRepositories());

        if (isset($config['repositories'])) {
            foreach ($config['repositories'] as $index => $repo) {
                foreach ($defaultRepositories as $name) {
                    if (isset($repo[$name]) && $repo[$name] === false) {
                        continue 2;
                    }
                }
                if (!is_array($repo)) {
                    throw new \UnexpectedValueException('Repository '.$index.' should be an array, '.gettype($repo).' given');
                }
                if (!isset($repo['type'])) {
                    throw new \UnexpectedValueException('Repository '.$index.' ('.json_encode($repo).') must have a type defined');
                }
                $repository = $this->manager->createRepository($repo['type'], $repo);
                $this->manager->addRepository($repository);
            }
            $package->setRepositories($config['repositories']);
        }

        return $package;
    }

    private function extractAliases(array $requires, array $aliases)
    {
        foreach ($requires as $reqName => $reqVersion) {
            if (preg_match('{^([^,\s]+) +as +([^,\s]+)$}', $reqVersion, $match)) {
                $aliases[] = array(
                    'package' => strtolower($reqName),
                    'version' => $this->versionParser->normalize($match[1]),
                    'alias' => $match[2],
                    'alias_normalized' => $this->versionParser->normalize($match[2]),
                );
            }
        }

        return $aliases;
    }

    private function extractStabilityFlags(array $requires, array $stabilityFlags)
    {
        $stabilities = BasePackage::$stabilities;
        foreach ($requires as $reqName => $reqVersion) {
            // parse explicit stability flags
            if (preg_match('{^[^,\s]*?@('.implode('|', array_keys($stabilities)).')$}i', $reqVersion, $match)) {
                $name = strtolower($reqName);
                $stability = $stabilities[VersionParser::normalizeStability($match[1])];

                if (isset($stabilityFlags[$name]) && $stabilityFlags[$name] > $stability) {
                    continue;
                }
                $stabilityFlags[$name] = $stability;

                continue;
            }

            // infer flags for requirements that have an explicit -dev or -beta version specified for example
            if (preg_match('{^[^,\s@]+$}', $reqVersion) && 'stable' !== ($stabilityName = VersionParser::parseStability($reqVersion))) {
                $name = strtolower($reqName);
                $stability = $stabilities[$stabilityName];
                if (isset($stabilityFlags[$name]) && $stabilityFlags[$name] > $stability) {
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
            if (preg_match('{^[^,\s@]+?#([a-f0-9]+)$}', $reqVersion, $match) && 'dev' === ($stabilityName = VersionParser::parseStability($reqVersion))) {
                $name = strtolower($reqName);
                $references[$name] = $match[1];
            }
        }

        return $references;
    }

    private function guessVersion(array $config)
    {
        // try to fetch current version from git branch
        if (function_exists('proc_open') && 0 === $this->process->execute('git branch --no-color --no-abbrev -v', $output)) {
            $branches = array();
            $isFeatureBranch = false;
            $version = null;

            foreach ($this->process->splitLines($output) as $branch) {
                if ($branch && preg_match('{^(?:\* ) *(?:[^/ ]+?/)?(\S+|\(no branch\)) *([a-f0-9]+) .*$}', $branch, $match)) {
                    if ($match[1] === '(no branch)') {
                        $version = 'dev-'.$match[2];
                        $isFeatureBranch = true;
                    } else {
                        $version = $this->versionParser->normalizeBranch($match[1]);
                        $isFeatureBranch = 0 === strpos($version, 'dev-');
                        if ('9999999-dev' === $version) {
                            $version = 'dev-'.$match[1];
                        }
                    }
                }

                if ($branch && !preg_match('{^ *[^/]+/HEAD }', $branch)) {
                    if (preg_match('{^(?:\* )? *(?:[^/ ]+?/)?(\S+) *([a-f0-9]+) .*$}', $branch, $match)) {
                        $branches[] = $match[1];
                    }
                }
            }

            if (!$isFeatureBranch) {
                return $version;
            }

            // ignore feature branches if they have no branch-alias or self.version is used
            // and find the branch they came from to use as a version instead
            if ((isset($config['extra']['branch-alias']) && !isset($config['extra']['branch-alias'][$version]))
                || strpos(json_encode($config), '"self.version"')
            ) {
                $branch = preg_replace('{^dev-}', '', $version);
                $length = PHP_INT_MAX;
                foreach ($branches as $candidate) {
                    // do not compare against other feature branches
                    if ($candidate === $branch || !preg_match('{^(master|trunk|default|develop|\d+\..+)$}', $candidate)) {
                        continue;
                    }
                    if (0 !== $this->process->execute('git rev-list '.$candidate.'..'.$branch, $output)) {
                        continue;
                    }
                    if (strlen($output) < $length) {
                        $length = strlen($output);
                        $version = $this->versionParser->normalizeBranch($candidate);
                        if ('9999999-dev' === $version) {
                            $version = 'dev-'.$match[1];
                        }
                    }
                }
            }

            return $version;
        }
    }
}
