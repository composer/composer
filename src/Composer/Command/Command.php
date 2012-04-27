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

/**
 * Base class for Composer commands
 *
 * @author Ryan Weaver <ryan@knplabs.com>
 * @author Konstantin Kudryashov <ever.zet@gmail.com>
 */
abstract class Command extends BaseCommand
{
    /**
     * @return \Composer\Composer
     */
    protected function getComposer($required = true)
    {
        return $this->getApplication()->getComposer($required);
    }

    /**
     * @return \Composer\IO\ConsoleIO
     */
    protected function getIO()
    {
        return $this->getApplication()->getIO();
    }
}
