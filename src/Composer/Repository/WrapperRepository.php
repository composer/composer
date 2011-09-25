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

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class WrapperRepository extends ArrayRepository implements WritableRepositoryInterface
{
    private $repositories;

    public function __construct(array $repositories)
    {
        $this->repositories = $repositories;
    }

    protected function initialize()
    {
        parent::initialize();

        foreach ($this->repositories as $repo) {
            foreach ($repo->getPackages() as $package) {
                $this->packages[] = $package;
            }
        }
    }

    /**
     * {@inheritDoc}
     */
    public function addPackage(PackageInterface $package)
    {
        throw new \LogicException('Can not add packages to a wrapper repository');
    }

    /**
     * {@inheritDoc}
     */
    public function removePackage(PackageInterface $package)
    {
        throw new \LogicException('Can not remove packages to a wrapper repository');
    }

    public function write()
    {
        foreach ($this->repositories as $repo) {
            if ($repo instanceof WritableRepositoryInterface) {
                $repo->write();
            }
        }
    }
}
