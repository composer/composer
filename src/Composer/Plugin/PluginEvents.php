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

namespace Composer\Plugin;

/**
 * The Plugin Events.
 *
 * @author Nils Adermann <naderman@naderman.de>
 */
class PluginEvents
{
    /**
     * The INIT event occurs after a Composer instance is done being initialized
     *
     * The event listener method receives a
     * Composer\EventDispatcher\Event instance.
     *
     * @var string
     */
    const INIT = 'init';

    /**
     * The COMMAND event occurs as a command begins
     *
     * The event listener method receives a
     * Composer\Plugin\CommandEvent instance.
     *
     * @var string
     */
    const COMMAND = 'command';

    /**
     * The PRE_FILE_DOWNLOAD event occurs before downloading a file
     *
     * The event listener method receives a
     * Composer\Plugin\PreFileDownloadEvent instance.
     *
     * @var string
     */
    const PRE_FILE_DOWNLOAD = 'pre-file-download';
}
