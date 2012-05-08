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

namespace Composer\Command;

use Symfony\Component\Console\Command\Command as BaseCommand;
use Composer\Console\Application as ComposerApplication;

/**
 * Base class for Composer commands
 *
 * @author Ryan Weaver <ryan@knplabs.com>
 * @author Konstantin Kudryashov <ever.zet@gmail.com>
 */
abstract class Command extends BaseCommand
{
    /**
     * @var \Composer\Composer
     */
    private $composer;

    /**
     * @var \Composer\IO\IOInterface
     */
    private $io;

    /**
     * @param   bool                $required
     * @return  \Composer\Composer
     */
    public function getComposer($required = true)
    {
        if (null === $this->composer) {
            $application = $this->getApplication();
            if ($application instanceof ComposerApplication) {
                /* @var $application    ComposerApplication */
                $this->composer = $application->getComposer();
            }
        }
        return $this->composer;
    }

    /**
     * @param   \Composer\Composer  $composer
     */
    public function setComposer(\Composer\Composer $composer)
    {
        $this->composer = $composer;
    }

    /**
     * @return \Composer\IO\ConsoleIO
     */
    public function getIO()
    {
        if (null === $this->io) {
            $application = $this->getApplication();
            if ($application instanceof ComposerApplication) {
                /* @var $application    ComposerApplication */
                $this->io = $application->getIO();
            }
        }
        return $this->io;
    }

    /**
     * @param   \Composer\IO\IOInterface    $io
     */
    public function setIO(\Composer\IO\IOInterface $io)
    {
        $this->io = $io;
    }
}
