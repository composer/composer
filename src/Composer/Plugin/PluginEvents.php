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
    public const INIT = 'init';

    /**
     * The COMMAND event occurs as a command begins
     *
     * The event listener method receives a
     * Composer\Plugin\CommandEvent instance.
     *
     * @var string
     */
    public const COMMAND = 'command';

    /**
     * The PRE_FILE_DOWNLOAD event occurs before downloading a file
     *
     * The event listener method receives a
     * Composer\Plugin\PreFileDownloadEvent instance.
     *
     * @var string
     */
    public const PRE_FILE_DOWNLOAD = 'pre-file-download';

    /**
     * The POST_FILE_DOWNLOAD event occurs after downloading a package dist file
     *
     * The event listener method receives a
     * Composer\Plugin\PostFileDownloadEvent instance.
     *
     * @var string
     */
    public const POST_FILE_DOWNLOAD = 'post-file-download';

    /**
     * The PRE_COMMAND_RUN event occurs before a command is executed and lets you modify the input arguments/options
     *
     * The event listener method receives a
     * Composer\Plugin\PreCommandRunEvent instance.
     *
     * @var string
     */
    public const PRE_COMMAND_RUN = 'pre-command-run';

    /**
     * The PRE_POOL_CREATE event occurs before the Pool of packages is created, and lets
     * you filter the list of packages which is going to enter the Solver
     *
     * The event listener method receives a
     * Composer\Plugin\PrePoolCreateEvent instance.
     *
     * @var string
     */
    public const PRE_POOL_CREATE = 'pre-pool-create';
}
