<?php declare(strict_types=1);

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

/**
 * Optional support for larger package requests with bounded pending metadata
 */
interface BatchLoadingRepositoryInterface extends RepositoryInterface
{
    /**
     * Supplies the memory budget for pending package metadata
     */
    public function configurePackageLoading(PackageLoadBudget $budget): void;
}
