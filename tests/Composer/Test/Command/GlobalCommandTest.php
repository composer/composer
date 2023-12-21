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

namespace Composer\Test\Command;

use Composer\Test\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Exception\RuntimeException;

class GlobalCommandTest extends TestCase
{
    public function testExceptionRunningWithNoSubcommand(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Not enough arguments (missing: "command-name").');

        $appTester = $this->getApplicationTester();
        $this->assertEquals(Command::FAILURE, $appTester->run(['command' => 'global']));
    }

    public function testExceptionRunningWithIncompleteSubcommand(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Not enough arguments (missing: "packages").');

        $appTester = $this->getApplicationTester();
        $this->assertEquals(Command::FAILURE, $appTester->run(['command' => 'global', 'command-name' => 'remove'] ));
    }
}
