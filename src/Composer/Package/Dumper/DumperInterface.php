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
namespace Composer\Package\Dumper;

use Composer\Package\PackageInterface;

/**
 * @author Till Klampaeckel <till@php.net>
 */
interface DumperInterface
{
    /**
     * Return value depends on implementation - e.g. generating a tar or zip the
     * method currently returns void, the ArrayDumper returns an array.
     *
     * @param PackageInterface $package
     *
     * @return void
     */
    public function dump(PackageInterface $package);
}
