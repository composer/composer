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

namespace Composer\Test\IO;

use Composer\IO\NullIO;
use Composer\Test\TestCase;

class NullIOTest extends TestCase
{
    public function testIsInteractive(): void
    {
        $io = new NullIO();

        $this->assertFalse($io->isInteractive());
    }

    public function testhasAuthentication(): void
    {
        $io = new NullIO();

        $this->assertFalse($io->hasAuthentication('foo'));
    }

    public function testAskAndHideAnswer(): void
    {
        $io = new NullIO();

        $this->assertNull($io->askAndHideAnswer('foo'));
    }

    public function testgetAuthentications(): void
    {
        $io = new NullIO();

        $this->assertIsArray($io->getAuthentications()); // @phpstan-ignore-line
        $this->assertEmpty($io->getAuthentications());
        $this->assertEquals(array('username' => null, 'password' => null), $io->getAuthentication('foo'));
    }

    public function testAsk(): void
    {
        $io = new NullIO();

        $this->assertEquals('foo', $io->ask('bar', 'foo'));
    }

    public function testAskConfirmation(): void
    {
        $io = new NullIO();

        $this->assertEquals(false, $io->askConfirmation('bar', false));
    }

    public function testAskAndValidate(): void
    {
        $io = new NullIO();

        $this->assertEquals('foo', $io->askAndValidate('question', function ($x): bool {
            return true;
        }, null, 'foo'));
    }

    public function testSelect(): void
    {
        $io = new NullIO();

        $this->assertEquals('1', $io->select('question', array('item1', 'item2'), '1', 2, 'foo', true));
    }
}
