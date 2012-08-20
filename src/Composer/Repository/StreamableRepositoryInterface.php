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
interface StreamableRepositoryInterface extends RepositoryInterface
{
    /**
     * Return partial package data without loading them all to save on memory
     *
     * @return array
     */
    public function getMinimalPackages();

    public function loadPackage(array $data, $id);
}
