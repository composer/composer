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

use Composer\EventDispatcher\Event;
use Composer\IO\IOInterface;

/**
 * An event when the installer fails.
 *
 * @author Ryan Weaver <ryan@knpuniversity.com>
 */
class InstallerErrorEvent extends Event
{
    private $error;

    private $io;

    private $statusCode = 1;

    public function __construct($eventName, $error, IOInterface $io)
    {
        parent::__construct($eventName);

        if (!$error instanceof \Throwable && !$error instanceof \Exception) {
            throw new \InvalidArgumentException(sprintf('The error passed to InstallerErrorEvent must be an instance of \Throwable or \Exception, "%s" was passed instead.', is_object($error) ? get_class($error) : gettype($error)));
        }

        $this->error = $error;
        $this->io = $io;
    }

    /**
     * @return \Exception|\Throwable
     */
    public function getError()
    {
        return $this->error;
    }

    /**
     * @return IOInterface
     */
    public function getIo()
    {
        return $this->io;
    }

    /**
     * If the status code is set to non-zero, the error will
     * not ultimately be thrown.
     *
     * @param integer $statusCode
     */
    public function setStatusCode($statusCode)
    {
        $this->statusCode = $statusCode;
    }

    /**
     * @return integer
     */
    public function getStatusCode()
    {
        return $this->statusCode;
    }
}
