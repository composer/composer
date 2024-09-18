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

        self::assertFalse($io->isInteractive());
    }

    public function testHasAuthentication(): void
    {
        $io = new NullIO();

        self::assertFalse($io->hasAuthentication('foo'));
    }

    public function testAskAndHideAnswer(): void
    {
        $io = new NullIO();

        self::assertNull($io->askAndHideAnswer('foo'));
    }

    public function testGetAuthentications(): void
    {
        $io = new NullIO();

        self::assertIsArray($io->getAuthentications());
        self::assertEmpty($io->getAuthentications());
        self::assertEquals(['username' => null, 'password' => null], $io->getAuthentication('foo'));
    }

    public function testAsk(): void
    {
        $io = new NullIO();

        self::assertEquals('foo', $io->ask('bar', 'foo'));
    }

    public function testAskConfirmation(): void
    {
        $io = new NullIO();

        self::assertFalse($io->askConfirmation('bar', false));
    }

    public function testAskAndValidate(): void
    {
        $io = new NullIO();

        self::assertEquals('foo', $io->askAndValidate('question', static function ($x): bool {
            return true;
        }, null, 'foo'));
    }

    public function testSelect(): void
    {
        $io = new NullIO();

        self::assertEquals('1', $io->select('question', ['item1', 'item2'], '1', 2, 'foo', true));
    }
}
