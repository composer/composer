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
use Composer\Package\PackageInterface;
use Composer\Package\Loader\ArrayLoader;
use Composer\Package\Dumper\ArrayDumper;

/**
 * Filesystem repository.
 *
 * @author Konstantin Kudryashov <ever.zet@gmail.com>
 */
class FilesystemRepository extends ArrayRepository implements WritableRepositoryInterface
{
    private $file;

    /**
     * Initializes filesystem repository.
     *
     * @param   JsonFile    $repositoryFile repository json file
     */
    public function __construct(JsonFile $repositoryFile)
    {
        $this->file = $repositoryFile;
    }

    /**
     * Initializes repository (reads file, or remote address).
     */
    protected function initialize()
    {
        parent::initialize();

        $packages = $this->file->read();
        if (is_array($packages)) {
            $loader = new ArrayLoader();
            foreach ($packages as $package) {
                $this->addPackage($loader->load($package));
            }
        }
    }

    /**
     * Writes writable repository.
     */
    public function write()
    {
        $packages = array();
        $dumper   = new ArrayDumper();
        foreach ($this->getPackages() as $package) {
            $packages[] = $dumper->dump($package);
        }

        $this->file->write($packages);
    }
}
