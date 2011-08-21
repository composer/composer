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
    protected $package;

    public function setUp()
    {
        $this->package = new MemoryPackage('foo', '1');
        $this->package->setId(12);
    }

    public function testLiteralWanted()
    {
        $literal = new Literal($this->package, true);

        $this->assertEquals(12, $literal->getId());
        $this->assertEquals('+'.(string) $this->package, (string) $literal);
    }

    public function testLiteralUnwanted()
    {
        $literal = new Literal($this->package, false);

        $this->assertEquals(-12, $literal->getId());
        $this->assertEquals('-'.(string) $this->package, (string) $literal);
    }

    public function testLiteralInverted()
    {
        $literal = new Literal($this->package, false);

        $inverted = $literal->inverted();

        $this->assertInstanceOf('\Composer\DependencyResolver\Literal', $inverted);
        $this->assertTrue($inverted->isWanted());
        $this->assertSame($this->package, $inverted->getPackage());
        $this->assertFalse($literal->equals($inverted));

        $doubleInverted = $inverted->inverted();

        $this->assertInstanceOf('\Composer\DependencyResolver\Literal', $doubleInverted);
        $this->assertFalse($doubleInverted->isWanted());
        $this->assertSame($this->package, $doubleInverted->getPackage());

        $this->assertTrue($literal->equals($doubleInverted));
    }
}
