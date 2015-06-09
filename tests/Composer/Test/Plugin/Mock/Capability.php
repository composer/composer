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

namespace Composer\Test\Plugin\Mock;

class Capability implements \Composer\Plugin\Capability\Capability
{
    public $args;

    public function __construct(array $args)
    {
        $this->args = $args;
    }
}
