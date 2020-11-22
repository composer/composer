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

namespace Composer\DependencyResolver;

use Composer\Repository\RepositoryInterface;

/**
 * @author Nils Adermann <naderman@naderman.de>
 * @internal
 */
class LocalRepoTransaction extends Transaction
{
    public function __construct(RepositoryInterface $lockedRepository, $localRepository)
    {
        parent::__construct(
            $localRepository->getPackages(),
            $lockedRepository->getPackages()
        );
    }
}
