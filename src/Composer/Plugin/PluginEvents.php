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
     * The PREPARE_REMOTE_FILESYSTEM event occurs before downloading a file
     *
     * The event listener method receives a
     * Composer\Plugin\PrepareRemoteFilesystemEvent instance.
     *
     * @var string
     */
    const PREPARE_REMOTE_FILESYSTEM = 'prepare-remote-filesystem';
}
