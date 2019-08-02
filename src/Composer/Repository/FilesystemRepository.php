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
use Composer\Package\Dumper\ArrayDumper;
use Composer\Installer\InstallationManager;
use Composer\Util\Filesystem;

/**
 * Filesystem repository.
 *
 * @author Konstantin Kudryashov <ever.zet@gmail.com>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class FilesystemRepository extends WritableArrayRepository
{
    private $file;

    /**
     * Initializes filesystem repository.
     *
     * @param JsonFile $repositoryFile repository json file
     */
    public function __construct(JsonFile $repositoryFile)
    {
        parent::__construct();
        $this->file = $repositoryFile;
    }

    /**
     * Initializes repository (reads file, or remote address).
     */
    protected function initialize()
    {
        parent::initialize();

        if (!$this->file->exists()) {
            return;
        }

        try {
            $data = $this->file->read();
            if (isset($data['packages'])) {
                $packages = $data['packages'];
            } else {
                $packages = $data;
            }

            // forward compatibility for composer v2 installed.json
            if (isset($packages['packages'])) {
                $packages = $packages['packages'];
            }

            if (!is_array($packages)) {
                throw new \UnexpectedValueException('Could not parse package list from the repository');
            }
        } catch (\Exception $e) {
            throw new InvalidRepositoryException('Invalid repository data in '.$this->file->getPath().', packages could not be loaded: ['.get_class($e).'] '.$e->getMessage());
        }

        $loader = new ArrayLoader(null, true);
        foreach ($packages as $packageData) {
            $package = $loader->load($packageData);
            $this->addPackage($package);
        }
    }

    public function reload()
    {
        $this->packages = null;
        $this->initialize();
    }

    /**
     * Writes writable repository.
     */
    public function write($devMode, InstallationManager $installationManager)
    {
        $data = array('packages' => array(), 'dev' => $devMode);
        $dumper = new ArrayDumper();
        $fs = new Filesystem();
        $repoDir = dirname($fs->normalizePath($this->file->getPath()));

        foreach ($this->getCanonicalPackages() as $package) {
            $pkgArray = $dumper->dump($package);
            $path = $installationManager->getInstallPath($package);
            $pkgArray['install-path'] = ('' !== $path && null !== $path) ? $fs->findShortestPath($repoDir, $path, true) : null;
            $data['packages'][] = $pkgArray;
        }

        usort($data['packages'], function ($a, $b) {
            return strcmp($a['name'], $b['name']);
        });

        $this->file->write($data);
    }
}
