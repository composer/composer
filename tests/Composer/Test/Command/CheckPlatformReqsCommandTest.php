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

class CheckPlatformReqsCommandTest extends TestCase
{
    /**
     * @dataProvider flagGenerator
     * @param array<mixed> $flag
     */
    public function testPlatformReqsAreSatisfied(array $flag): void
    {
        $appTester = $this->getApplicationTester();
        $appTester->run(array_merge(['command' => 'check-platform-reqs'], $flag));

        $appTester->assertCommandIsSuccessful();
    }

    public function flagGenerator(): \Generator
    {
        yield 'Disables checking of require-dev packages requirements.' => [
            ['--no-dev' => true]
        ];

        yield 'Whether to disable plugins.' => [
            ['--no-plugins' => true]
        ];

        yield 'Checks requirements only from the lock file, not from installed packages.' => [
            ['--lock' => true]
        ];
    }
}
