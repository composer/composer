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

namespace Composer\Test\Plugin\Mock;

class WrongCapability implements \Composer\Plugin\Capability\Capability
{
    public function __construct()
    {
        throw new \LogicException('Invalid capabilities must not be constructed.');
    }
}
