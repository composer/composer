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

namespace Composer\Installer;

use Composer\Package\PackageInterface;
use Composer\Composer;

/**
 * @author Konstantin Kudryashov <ever.zet@gmail.com>
 */
class InstallerInterface
{
    function setComposer(Composer $composer);

    function isInstalled(PackageInterface $package);
    function install(PackageInterface $package);
    function update(PackageInterface $package);
    function remove(PackageInterface $package);
}
