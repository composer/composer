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

namespace Composer\Trigger;

/**
 * The Trigger Events.
 *
 * @author François Pluchino <francois.pluchino@opendisplay.com>
 */
class TriggerEvents
{
    /**
     * The PRE_INSTALL event occurs at begging installation packages.
     *
     * This event allows you to execute a trigger before any other code in the
     * composer is executed. The event listener method receives a
     * Composer\Trigger\GetTriggerEvent instance.
     *
     * @var string
     */
    const PRE_INSTALL = 'pre_install';

    /**
     * The POST_INSTALL event occurs at end installation packages.
     *
     * This event allows you to execute a trigger after any other code in the
     * composer is executed. The event listener method receives a
     * Composer\Trigger\GetTriggerEvent instance.
     *
     * @var string
     */
    const POST_INSTALL = 'post_install';

    /**
     * The PRE_UPDATE event occurs at begging update packages.
     *
     * This event allows you to execute a trigger before any other code in the
     * composer is executed. The event listener method receives a
     * Composer\Trigger\GetTriggerEvent instance.
     *
     * @var string
     */
    const PRE_UPDATE = 'pre_update';

    /**
     * The POST_UPDATE event occurs at end update packages.
     *
     * This event allows you to execute a trigger after any other code in the
     * composer is executed. The event listener method receives a
     * Composer\Trigger\GetTriggerEvent instance.
     *
     * @var string
     */
    const POST_UPDATE = 'post_update';

    /**
     * The PRE_UNINSTALL event occurs at begging uninstallation packages.
     *
     * This event allows you to execute a trigger after any other code in the
     * composer is executed. The event listener method receives a
     * Composer\Trigger\TriggerEvent instance.
     *
     * @var string
     */
    const PRE_UNINSTALL = 'pre_uninstall';
    //TODO add the dispatcher when the uninstall command will be doing

    /**
     * The PRE_UNINSTALL event occurs at end uninstallation packages.
     *
     * This event allows you to execute a trigger after any other code in the
     * composer is executed. The event listener method receives a
     * Composer\Trigger\TriggerEvent instance.
     *
     * @var string
     */
    const POST_UNINSTALL = 'post_uninstall';
    //TODO add the dispatcher when the uninstall command will be doing
}
