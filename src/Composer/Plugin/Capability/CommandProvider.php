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

namespace Composer\Plugin\Capability;

/**
 * Commands Provider Interface
 *
 * This capability will receive an array with 'composer' and 'io' keys as
 * constructor argument. Those contain Composer\Composer and Composer\IO\IOInterface
 * instances. It also contains a 'plugin' key containing the plugin instance that
 * created the capability.
 *
 * @author Jérémy Derussé <jeremy@derusse.com>
 */
interface CommandProvider extends Capability
{
    /**
     * Retrieves an array of commands
     *
     * @return \Composer\Command\BaseCommand[]
     */
    public function getCommands();
}
