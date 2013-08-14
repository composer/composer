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

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\EventDispatcher\Event;
use Composer\Util\RemoteFilesystem;

/**
 * The Prepare Remote Filesystem Event.
 *
 * @author Nils Adermann <naderman@naderman.de>
 */
class PrepareRemoteFilesystemEvent extends Event
{
    /**
     * @var RemoteFilesystem
     */
    private $rfs;

    /**
     * @var string
     */
    private $processedUrl;

    /**
     * Constructor.
     *
     * @param string             $name      The event name
     * @param Composer           $composer  The composer object
     * @param IOInterface        $io        The IOInterface object
     * @param boolean            $devMode   Whether or not we are in dev mode
     * @param OperationInterface $operation The operation object
     */
    public function __construct($name, RemoteFilesystem $rfs, $processedUrl)
    {
        parent::__construct($name);
        $this->rfs = $rfs;
        $this->processedUrl = $processedUrl;
    }

    /**
     * Returns the remote filesystem
     *
     * @return OperationInterface
     */
    public function getRemoteFilesystem()
    {
        return $this->rfs;
    }

    /**
     * Sets the remote filesystem
     */
    public function setRemoteFilesystem(RemoteFilesystem $rfs)
    {
        $this->rfs = $rfs;
    }

    /**
     * Retrieves the processed URL this remote filesystem will be used for
     *
     * @return string
     */
    public function getProcessedUrl()
    {
        return $this->processedUrl;
    }
}
