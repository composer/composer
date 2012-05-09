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
use Composer\Package\AliasPackage;
use Composer\Package\Loader\ArrayLoader;
use Composer\Package\Dumper\ArrayDumper;

/**
 * Filesystem repository.
 *
 * @author Konstantin Kudryashov <ever.zet@gmail.com>
 * @author Jordi Boggiano <j.boggiano@seld.be>
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

        if (!$this->file->exists()) {
            return;
        }

        $packages = $this->file->read();

        if (!is_array($packages)) {
            throw new \UnexpectedValueException('Could not parse package list from the '.$this->file->getPath().' repository');
        }

        $aliases = array();

        $loader = new ArrayLoader();
        foreach ($packages as $packageData) {
            $package = $loader->load($packageData);

            // aliases need to be looked up in the end to set up references correctly
            if ($this instanceof InstalledRepositoryInterface && !empty($packageData['alias'])) {
                $aliases[] = array(
                    'package' => $package,
                    'alias' => $packageData['alias'],
                    'alias_pretty' => $packageData['alias_pretty']
                );
            }

            $this->addPackage($package);
        }

        foreach ($aliases as $aliasData) {
            $temporaryPackage = $aliasData['package'];

            $package = $this->findPackage($package->getName(), $package->getVersion());

            $package->setAlias($aliasData['alias']);
            $package->setPrettyAlias($aliasData['alias_pretty']);
            $this->addPackage($this->createAliasPackage($package, $aliasData['alias'], $aliasData['alias_pretty']));
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
    public function write()
    {
        $packages = array();
        $dumper   = new ArrayDumper();
        foreach ($this->getPackages() as $package) {
            if (!$package instanceof AliasPackage) {
                $data = $dumper->dump($package);
                $packages[] = $data;
            }
        }

        $this->file->write($packages);
    }
}
