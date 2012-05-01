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

use Composer\Package\Version\VersionParser;
use Composer\Repository\RepositoryManager;
use Composer\Util\ProcessExecutor;
use Composer\Package\AliasPackage;

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
    private $process;

    public function __construct(RepositoryManager $manager, VersionParser $parser = null, ProcessExecutor $process = null)
    {
        $this->manager = $manager;
        $this->process = $process ?: new ProcessExecutor();
        parent::__construct($parser);
    }

    public function load($config)
    {
        if (!isset($config['name'])) {
            $config['name'] = '__root__';
        }
        if (!isset($config['version'])) {
            $version = '1.0.0';

            // try to fetch current version from git branch
            if (0 === $this->process->execute('git branch --no-color --no-abbrev -v', $output)) {
                foreach ($this->process->splitLines($output) as $branch) {
                    if ($branch && preg_match('{^(?:\* ) *(?:[^/ ]+?/)?(\S+) *[a-f0-9]+ .*$}', $branch, $match)) {
                        $version = 'dev-'.$match[1];
                    }
                }
            }

            $config['version'] = $version;
        } else {
            $version = $config['version'];
        }

        $package = parent::load($config);

        if (isset($config['require'])) {
            $aliases = array();
            foreach ($config['require'] as $reqName => $reqVersion) {
                if (preg_match('{^([^,\s]+) +as +([^,\s]+)$}', $reqVersion, $match)) {
                    $aliases[] = array(
                        'package' => strtolower($reqName),
                        'version' => $this->versionParser->normalize($match[1]),
                        'alias' => $match[2],
                        'alias_normalized' => $this->versionParser->normalize($match[2]),
                    );
                }
            }

            $package->setAliases($aliases);
        }

        if (isset($config['repositories'])) {
            foreach ($config['repositories'] as $index => $repo) {
                if (isset($repo['packagist']) && $repo['packagist'] === false) {
                    continue;
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

        if (isset($config['extra']['branch-alias'][$version])
            && substr($config['extra']['branch-alias'][$version], -4) === '-dev'
        ) {
            $targetBranch = $config['extra']['branch-alias'][$version];
            $normalized = $this->versionParser->normalizeBranch(substr($targetBranch, 0, -4));
            $version = preg_replace('{(\.9{7})+}', '.x', $normalized);

            return new AliasPackage($package, $normalized, $version);
        }

        return $package;
    }
}
