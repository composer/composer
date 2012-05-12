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
 * Installed array repository.
 *
 * This is used for serving the RootPackage inside an in-memory InstalledRepository
 *
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class InstalledArrayRepository extends ArrayRepository implements InstalledRepositoryInterface
{
    /**
     * {@inheritDoc}
     */
    public function write()
    {
    }

    /**
     * {@inheritDoc}
     */
    public function reload()
    {
    }
}
