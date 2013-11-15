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

/**
 * Filesystem repository.
 *
 * @author Konstantin Kudryashov <ever.zet@gmail.com>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class FilesystemRepository extends WritableArrayRepository
{
    protected $file;

    /**
     * Initializes filesystem repository.
     *
     * @param JsonFile $repositoryFile repository json file
     */
    public function __construct(JsonFile $repositoryFile)
    {
        $this->file = $repositoryFile;
    }

    public function reload()
    {
        $this->packages = null;
        $this->initialize();
    }

    /**
     * Writes writable repository.
     */
    public function write()
    {
        $data = array();
        $dumper = new ArrayDumper();

        foreach ($this->getCanonicalPackages() as $package) {
            $data[] = $dumper->dump($package);
        }

        $this->file->write($data);
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
            $packages = $this->file->read();

            if (!is_array($packages)) {
                throw new \UnexpectedValueException('Could not parse package list from the repository');
            }
        } catch (\Exception $e) {
            throw new InvalidRepositoryException('Invalid repository data in '.$this->file->getPath().', packages could not be loaded: ['.get_class($e).'] '.$e->getMessage());
        }

        $this->loadPackages($packages);
    }

    protected function loadPackages($packages)
    {
        $loader = new ArrayLoader();

        foreach ($packages as $packageData) {
            $package = $loader->load($packageData);
            $this->addPackage($package);
        }
    }
}
