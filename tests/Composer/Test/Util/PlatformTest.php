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

namespace Composer\Test\Util;

use Composer\Util\Platform;
use Composer\Test\TestCase;

/**
 * PlatformTest
 *
 * @author Niels Keurentjes <niels.keurentjes@omines.com>
 */
class PlatformTest extends TestCase
{
    public function testExpandPath(): void
    {
        putenv('TESTENV=/home/test');
        self::assertEquals('/home/test/myPath', Platform::expandPath('%TESTENV%/myPath'));
        self::assertEquals('/home/test/myPath', Platform::expandPath('$TESTENV/myPath'));
        self::assertEquals((getenv('HOME') ?: getenv('USERPROFILE')) . '/test', Platform::expandPath('~/test'));
    }

    public function testIsWindows(): void
    {
        // Compare 2 common tests for Windows to the built-in Windows test
        self::assertEquals(('\\' === DIRECTORY_SEPARATOR), Platform::isWindows());
        self::assertEquals(defined('PHP_WINDOWS_VERSION_MAJOR'), Platform::isWindows());
    }
}
