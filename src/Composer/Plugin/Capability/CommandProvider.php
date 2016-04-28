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

namespace Composer\Plugin\Capability;

/**
 * Commands Provider Interface
 *
 * @author Jérémy Derussé <jeremy@derusse.com>
 */
interface CommandProvider extends Capability
{
    /**
     * Retreives an array of commands
     *
     * @return Composer\Command\BaseCommand[]
     */
    public function getCommands();
}
