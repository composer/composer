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

namespace Composer\Installer;

/**
 * Package Events.
 *
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class PackageEvents
{
    /**
     * The PRE_PACKAGE_INSTALL event occurs before a package is installed.
     *
     * The event listener method receives a Composer\Installer\PackageEvent instance.
     *
     * @var string
     */
    public const PRE_PACKAGE_INSTALL = 'pre-package-install';

    /**
     * The POST_PACKAGE_INSTALL event occurs after a package is installed.
     *
     * The event listener method receives a Composer\Installer\PackageEvent instance.
     *
     * @var string
     */
    public const POST_PACKAGE_INSTALL = 'post-package-install';

    /**
     * The PRE_PACKAGE_UPDATE event occurs before a package is updated.
     *
     * The event listener method receives a Composer\Installer\PackageEvent instance.
     *
     * @var string
     */
    public const PRE_PACKAGE_UPDATE = 'pre-package-update';

    /**
     * The POST_PACKAGE_UPDATE event occurs after a package is updated.
     *
     * The event listener method receives a Composer\Installer\PackageEvent instance.
     *
     * @var string
     */
    public const POST_PACKAGE_UPDATE = 'post-package-update';

    /**
     * The PRE_PACKAGE_UNINSTALL event occurs before a package has been uninstalled.
     *
     * The event listener method receives a Composer\Installer\PackageEvent instance.
     *
     * @var string
     */
    public const PRE_PACKAGE_UNINSTALL = 'pre-package-uninstall';

    /**
     * The POST_PACKAGE_UNINSTALL event occurs after a package has been uninstalled.
     *
     * The event listener method receives a Composer\Installer\PackageEvent instance.
     *
     * @var string
     */
    public const POST_PACKAGE_UNINSTALL = 'post-package-uninstall';
}
