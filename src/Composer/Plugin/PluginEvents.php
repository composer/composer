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
 * @author Mohamed Tahrioui <mohamed.tahrioui@wheregroup.com>
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
     * Composer\Plugin\FileDownloadEvent instance.
     *
     * @var string
     */

    const PRE_FILE_DOWNLOAD = 'pre-file-download';


    /**
     * The POST_FILE_DOWNLOAD event occurs after downloading a file
     *
     * The event listener method receives a
     * Composer\Plugin\FileDownloadEvent instance.
     *
     * @var string
     */
    const POST_FILE_DOWNLOAD = 'post-file-download';


    /**
     * The PRE_VCS_DOWNLOAD event occurs before downloading a repository
     *
     * The event listener method receives a
     * Composer\Plugin\FileDownloadEvent instance.
     *
     * @var string
     */
    const PRE_VCS_DOWNLOAD = 'pre-vcs-download';


    /**
     * The POST_VCS_DOWNLOAD event occurs after downloading a repository
     *
     * The event listener method receives a
     * Composer\Plugin\FileDownloadEvent instance.
     *
     * @var string
     */
    const POST_VCS_DOWNLOAD = 'post-vcs-download';
}
