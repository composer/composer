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
use Composer\Util\Platform;

class ClearCacheCommandTest extends TestCase
{
    public function tearDown(): void
    {
        // --no-cache triggers the env to change so make sure the env is cleaned up after these tests run
        Platform::clearEnv('COMPOSER_CACHE_DIR');
    }

    public function testClearCacheCommandSuccess(): void
    {
        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => 'clear-cache']);

        $appTester->assertCommandIsSuccessful();

        $output = $appTester->getDisplay(true);

        self::assertStringContainsString('All caches cleared.', $output);
    }

    public function testClearCacheCommandWithOptionGarbageCollection(): void
    {
        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => 'clear-cache', '--gc' => true]);

        $appTester->assertCommandIsSuccessful();

        $output = $appTester->getDisplay(true);

        self::assertStringContainsString('All caches garbage-collected.', $output);
    }

    public function testClearCacheCommandWithOptionNoCache(): void
    {
        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => 'clear-cache', '--no-cache' => true]);

        $appTester->assertCommandIsSuccessful();

        $output = $appTester->getDisplay(true);

        self::assertStringContainsString('Cache is not enabled', $output);
    }
}
