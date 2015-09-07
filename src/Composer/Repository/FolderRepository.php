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

/**
 * This repository allows installing local packages that are not under any VCS.
 * Just add paths in repositories so the local package can be found.
 * The local packages will be added as symlinks, it means that any modification
 * made in local packages will be automatically applied to other packages depending on it.
 * Both relative and absolute paths are handled.
 *
 * @code
 * "require": {
 *     "<vendor>/<local-package>": "*"
 * },
 * "repositories": [
 *     {
 *         "type": "folder",
 *         "url": "../../relative/path/to/package/"
 *     },
 *     {
 *         "type": "folder",
 *         "url": "/absolute/path/to/package/"
 *     }
 * ]
 * @endcode
 *
 * @author Johann Reinke <johann.reinke@gmail.com>
 */
class FolderRepository extends ArrayRepository
{
    protected $path;

    protected $loader;

    /**
     * Initializes folder repository.
     *
     * @param array $config package definition
     */
    public function __construct(array $config)
    {
        if (!isset($config['url'])) {
            throw new \RuntimeException('You must specify a path for the folder repository');
        }
        $this->path = realpath(rtrim($config['url'], '/')) . '/';
        $this->loader = new ArrayLoader();
    }

    /**
     * Initializes repository (reads folders).
     */
    protected function initialize()
    {
        parent::initialize();

        $file = $this->path . 'composer.json';
        if (is_file($file) && is_readable($file)) {
            $json = file_get_contents($file);
            $package = JsonFile::parseJson($json, $file);
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
}
