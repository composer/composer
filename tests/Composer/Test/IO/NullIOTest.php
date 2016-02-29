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

namespace Composer\Test\IO;

use Composer\IO\NullIO;
use Composer\TestCase;

class NullIOTest extends TestCase
{
    public function testIsInteractive()
    {
        $io = new NullIO();

        $this->assertFalse($io->isInteractive());
    }

    public function testhasAuthentication()
    {
        $io = new NullIO();

        $this->assertFalse($io->hasAuthentication('foo'));
    }

    public function testAskAndHideAnswer()
    {
        $io = new NullIO();

        $this->assertNull($io->askAndHideAnswer('foo'));
    }

    public function testgetAuthentications()
    {
        $io = new NullIO();

        $this->assertInternalType('array', $io->getAuthentications());
        $this->assertEmpty($io->getAuthentications());
        $this->assertEquals(array('username' => null, 'password' => null), $io->getAuthentication('foo'));
    }

    public function testAsk()
    {
        $io = new NullIO();

        $this->assertEquals('foo', $io->ask('bar', 'foo'));
    }

    public function testAskConfirmation()
    {
        $io = new NullIO();

        $this->assertEquals('foo', $io->askConfirmation('bar', 'foo'));
    }

    public function testAskAndValidate()
    {
        $io = new NullIO();

        $this->assertEquals('foo', $io->askAndValidate('question', 'validator', false, 'foo'));
    }

    public function testSelect()
    {
        $io = new NullIO();

        $this->assertEquals('1', $io->select('question', array('item1', 'item2'), '1', 2, 'foo', true));
    }
}
