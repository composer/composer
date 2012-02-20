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

    public function __construct(RepositoryManager $manager, VersionParser $parser = null)
    {
        $this->manager = $manager;
        parent::__construct($parser);
    }

    public function load($config)
    {
        if (!isset($config['name'])) {
            $config['name'] = '__root__';
        }
        if (!isset($config['version'])) {
            $config['version'] = '1.0.0';
        }

        $package = parent::load($config);

        if (isset($config['repositories'])) {
            foreach ($config['repositories'] as $index => $repo) {
                if (isset($repo['packagist']) && $repo['packagist'] === false) {
                    continue;
                }
                if (!is_array($repo)) {
                    throw new \UnexpectedValueException('Repository '.$index.' should be an array, '.gettype($repo).' given');
                }
                if (!isset($repo['type'])) {
                    throw new \UnexpectedValueException('Repository '.$index.' must have a type defined');
                }
                $repository = $this->manager->createRepository($repo['type'], $repo);
                $this->manager->addRepository($repository);
            }
            $package->setRepositories($config['repositories']);
        }

        return $package;
    }
}
