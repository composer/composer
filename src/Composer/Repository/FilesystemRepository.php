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
     * @param   string  $group  registry (installer) group
     */
    public function __construct($repositoryFile)
    {
        $this->file = $repositoryFile;
        $path       = dirname($this->file);

        if (!is_dir($path)) {
            if (file_exists($path)) {
                throw new \UnexpectedValueException(
                    $path.' exists and is not a directory.'
                );
            }
            if (!mkdir($path, 0777, true)) {
                throw new \UnexpectedValueException(
                    $path.' does not exist and could not be created.'
                );
            }
        }
    }

    /**
     * Initializes repository (reads file, or remote address).
     */
    protected function initialize()
    {
        parent::initialize();

        $packages = @json_decode(file_get_contents($this->file), true);

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

        file_put_contents($this->file, json_encode($packages));
    }
}
