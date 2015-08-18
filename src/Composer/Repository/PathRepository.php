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

namespace Composer\Repository;

use Composer\Json\JsonFile;
use Composer\Package\Loader\ArrayLoader;
use Symfony\Component\Filesystem\Filesystem;

/**
 * This repository allows installing local packages that are not necessarily under their own VCS.
 *
 * The local packages will be symlinked when possible, else they will be copied.
 *
 * @code
 * "require": {
 *     "<vendor>/<local-package>": "*"
 * },
 * "repositories": [
 *     {
 *         "type": "path",
 *         "url": "../../relative/path/to/package/"
 *     },
 *     {
 *         "type": "path",
 *         "url": "/absolute/path/to/package/"
 *     }
 * ]
 * @endcode
 *
 * @author Samuel Roze <samuel.roze@gmail.com>
 * @author Johann Reinke <johann.reinke@gmail.com>
 */
class PathRepository extends ArrayRepository
{
    /**
     * @var Filesystem
     */
    private $fileSystem;

    /**
     * @var ArrayLoader
     */
    private $loader;

    /**
     * @var string
     */
    private $path;

    /**
     * Initializes path repository.
     *
     * @param array $config package definition
     */
    public function __construct(array $config)
    {
        if (!isset($config['url'])) {
            throw new \RuntimeException('You must specify the `url` configuration for the path repository');
        }

        $this->fileSystem = new Filesystem();
        $this->loader = new ArrayLoader();
        $this->path = realpath(rtrim($config['url'], '/')) . '/';
    }

    /**
     * Initializes path repository.
     *
     * This method will basically read the folder and add the found package.
     *
     */
    protected function initialize()
    {
        parent::initialize();

        $composerFilePath = $this->path.'composer.json';
        if (!$this->fileSystem->exists($composerFilePath)) {
            throw new \RuntimeException(sprintf('No `composer.json` file found in path repository "%s"', $this->path));
        }

        $json = file_get_contents($composerFilePath);
        $package = JsonFile::parseJson($json, $composerFilePath);
        $package['dist'] = array(
            'type' => 'folder',
            'url' => $this->path,
        );

        if (!isset($package['version'])) {
            $package['version'] = 'dev-master';
        }

        $package = $this->loader->load($package);
        $this->addPackage($package);
    }
}
