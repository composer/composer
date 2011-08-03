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

namespace Composer\Test\DependencyResolver;

use Composer\DependencyResolver\Literal;
use Composer\Package\MemoryPackage;

class LiteralTest extends \PHPUnit_Framework_TestCase
{
    public function testLiteral()
    {
        $literal = new Literal(new MemoryPackage('foo', '1'), true);
        $this->markTestIncomplete('Eh?');
    }
}
