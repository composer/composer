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

        $loader = new ArrayLoader();
        foreach ($packages as $packageData) {
            $package = $loader->load($packageData);

            // package was installed as alias, so we only add the alias
            if ($this instanceof InstalledRepositoryInterface && !empty($packageData['installed-as-alias'])) {
                $alias = $packageData['installed-as-alias'];
                $package->setAlias($alias);
                $package->setPrettyAlias($alias);
                $package->setInstalledAsAlias(true);
                $this->addPackage($this->createAliasPackage($package, $alias, $alias));
            } else {
                // only add regular package - if it's not an installed repo the alias will be created on the fly
                $this->addPackage($package);
            }
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
            $data = $dumper->dump($package);
            if ($this instanceof InstalledRepositoryInterface && $package->isInstalledAsAlias()) {
                $data['installed-as-alias'] = $package->getAlias();
            }
            $packages[] = $data;
        }

        $this->file->write($packages);
    }
}
