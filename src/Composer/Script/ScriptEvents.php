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

namespace Composer\Script;

/**
 * The Script Events.
 *
 * @author François Pluchino <francois.pluchino@opendisplay.com>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class ScriptEvents
{
    /**
     * The PRE_INSTALL_CMD event occurs before the install command is executed.
     *
     * The event listener method receives a Composer\Script\CommandEvent instance.
     *
     * @var string
     */
    const PRE_INSTALL_CMD = 'pre-install-cmd';

    /**
     * The POST_INSTALL_CMD event occurs after the install command is executed.
     *
     * The event listener method receives a Composer\Script\CommandEvent instance.
     *
     * @var string
     */
    const POST_INSTALL_CMD = 'post-install-cmd';

    /**
     * The PRE_UPDATE_CMD event occurs before the update command is executed.
     *
     * The event listener method receives a Composer\Script\CommandEvent instance.
     *
     * @var string
     */
    const PRE_UPDATE_CMD = 'pre-update-cmd';

    /**
     * The POST_UPDATE_CMD event occurs after the update command is executed.
     *
     * The event listener method receives a Composer\Script\CommandEvent instance.
     *
     * @var string
     */
    const POST_UPDATE_CMD = 'post-update-cmd';

    /**
     * The PRE_STATUS_CMD event occurs before the status command is executed.
     *
     * The event listener method receives a Composer\Script\CommandEvent instance.
     *
     * @var string
     */
    const PRE_STATUS_CMD = 'pre-status-cmd';

    /**
     * The POST_STATUS_CMD event occurs after the status command is executed.
     *
     * The event listener method receives a Composer\Script\CommandEvent instance.
     *
     * @var string
     */
    const POST_STATUS_CMD = 'post-status-cmd';

    /**
     * The PRE_PACKAGE_INSTALL event occurs before a package is installed.
     *
     * The event listener method receives a Composer\Script\PackageEvent instance.
     *
     * @var string
     */
    const PRE_PACKAGE_INSTALL = 'pre-package-install';

    /**
     * The POST_PACKAGE_INSTALL event occurs after a package is installed.
     *
     * The event listener method receives a Composer\Script\PackageEvent instance.
     *
     * @var string
     */
    const POST_PACKAGE_INSTALL = 'post-package-install';

    /**
     * The PRE_PACKAGE_UPDATE event occurs before a package is updated.
     *
     * The event listener method receives a Composer\Script\PackageEvent instance.
     *
     * @var string
     */
    const PRE_PACKAGE_UPDATE = 'pre-package-update';

    /**
     * The POST_PACKAGE_UPDATE event occurs after a package is updated.
     *
     * The event listener method receives a Composer\Script\PackageEvent instance.
     *
     * @var string
     */
    const POST_PACKAGE_UPDATE = 'post-package-update';

    /**
     * The PRE_PACKAGE_UNINSTALL event occurs before a package has been uninstalled.
     *
     * The event listener method receives a Composer\Script\PackageEvent instance.
     *
     * @var string
     */
    const PRE_PACKAGE_UNINSTALL = 'pre-package-uninstall';

    /**
     * The POST_PACKAGE_UNINSTALL event occurs after a package has been uninstalled.
     *
     * The event listener method receives a Composer\Script\PackageEvent instance.
     *
     * @var string
     */
    const POST_PACKAGE_UNINSTALL = 'post-package-uninstall';

    /**
     * The PRE_AUTOLOAD_DUMP event occurs before the autoload file is generated.
     *
     * The event listener method receives a Composer\Script\Event instance.
     *
     * @var string
     */
    const PRE_AUTOLOAD_DUMP = 'pre-autoload-dump';

    /**
     * The POST_AUTOLOAD_DUMP event occurs after the autoload file has been generated.
     *
     * The event listener method receives a Composer\Script\Event instance.
     *
     * @var string
     */
    const POST_AUTOLOAD_DUMP = 'post-autoload-dump';

    /**
     * The POST_ROOT_PACKAGE_INSTALL event occurs after the root package has been installed.
     *
     * The event listener method receives a Composer\Script\PackageEvent instance.
     *
     * @var string
     */
    const POST_ROOT_PACKAGE_INSTALL = 'post-root-package-install';

    /**
     * The POST_CREATE_PROJECT event occurs after the create-project command has been executed.
     * Note: Event occurs after POST_INSTALL_CMD
     *
     * The event listener method receives a Composer\Script\PackageEvent instance.
     *
     * @var string
     */
    const POST_CREATE_PROJECT_CMD = 'post-create-project-cmd';

    /**
     * The PRE_ARCHIVE_CMD event occurs before the update command is executed.
     *
     * The event listener method receives a Composer\Script\CommandEvent instance.
     *
     * @var string
     */
    const PRE_ARCHIVE_CMD = 'pre-archive-cmd';

    /**
     * The POST_ARCHIVE_CMD event occurs after the status command is executed.
     *
     * The event listener method receives a Composer\Script\CommandEvent instance.
     *
     * @var string
     */
    const POST_ARCHIVE_CMD = 'post-archive-cmd';
}
