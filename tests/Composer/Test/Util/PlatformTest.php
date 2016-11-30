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

namespace Composer\Test\Util;

use Composer\Util\Platform;

/**
 * PlatformTest
 *
 * @author Niels Keurentjes <niels.keurentjes@omines.com>
 */
class PlatformTest extends \PHPUnit_Framework_TestCase
{
    public function testExpandPath()
    {
        putenv('TESTENV=/home/test');
        $this->assertEquals('/home/test/myPath', Platform::expandPath('%TESTENV%/myPath'));
        $this->assertEquals('/home/test/myPath', Platform::expandPath('$TESTENV/myPath'));
        $this->assertEquals((getenv('HOME') ?: getenv('USERPROFILE')) . '/test', Platform::expandPath('~/test'));
    }
    
    public function testIsWindows()
    {
        // Compare 2 common tests for Windows to the built-in Windows test
        $this->assertEquals(('\\' === DIRECTORY_SEPARATOR), Platform::isWindows());
        $this->assertEquals(defined('PHP_WINDOWS_VERSION_MAJOR'), Platform::isWindows());
    }
}
