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

namespace Composer\Test;

use Composer\Test\Mock\XdebugHandlerMock;

/**
 * @author John Stevenson <john-stevenson@blueyonder.co.uk>
 *
 * We use PHP_BINARY which only became available in PHP 5.4 *
 * @requires PHP 5.4
 */
class XdebugHandlerTest extends \PHPUnit_Framework_TestCase
{
    public static $envAllow;
    public static $envIniScanDir;

    public function testRestartWhenLoaded()
    {
        $loaded = true;

        $xdebug = new XdebugHandlerMock($loaded);
        $xdebug->check();
        $this->assertTrue($xdebug->restarted);
    }

    public function testNoRestartWhenNotLoaded()
    {
        $loaded = false;

        $xdebug = new XdebugHandlerMock($loaded);
        $xdebug->check();
        $this->assertFalse($xdebug->restarted);
    }

    public function testNoRestartWhenLoadedAndAllowed()
    {
        $loaded = true;
        putenv(XdebugHandlerMock::ENV_ALLOW.'=1');

        $xdebug = new XdebugHandlerMock($loaded);
        $xdebug->check();
        $this->assertFalse($xdebug->restarted);
    }

    public function testEnvAllow()
    {
        $loaded = true;

        $xdebug = new XdebugHandlerMock($loaded);
        $xdebug->check();
        $expected = XdebugHandlerMock::RESTART_ID;
        $this->assertEquals($expected, getenv(XdebugHandlerMock::ENV_ALLOW));

        // Mimic restart
        $xdebug = new XdebugHandlerMock($loaded);
        $xdebug->check();
        $this->assertFalse($xdebug->restarted);
        $this->assertFalse(getenv(XdebugHandlerMock::ENV_ALLOW));
    }

    public function testEnvAllowWithScanDir()
    {
        $loaded = true;
        $dir = '/some/where';
        putenv('PHP_INI_SCAN_DIR='.$dir);

        $xdebug = new XdebugHandlerMock($loaded);
        $xdebug->check();
        $expected = XdebugHandlerMock::RESTART_ID.'|'.$dir;
        $this->assertEquals($expected, getenv(XdebugHandlerMock::ENV_ALLOW));

        // Mimic setting scan dir and restart
        putenv('PHP_INI_SCAN_DIR=');
        $xdebug = new XdebugHandlerMock($loaded);
        $xdebug->check();
        $this->assertEquals($dir, getenv('PHP_INI_SCAN_DIR'));
    }

    public function testEnvAllowWithEmptyScanDir()
    {
        $loaded = true;
        putenv('PHP_INI_SCAN_DIR=');

        $xdebug = new XdebugHandlerMock($loaded);
        $xdebug->check();
        $expected = XdebugHandlerMock::RESTART_ID.'|';
        $this->assertEquals($expected, getenv(XdebugHandlerMock::ENV_ALLOW));

        // Unset scan dir and mimic restart
        putenv('PHP_INI_SCAN_DIR');
        $xdebug = new XdebugHandlerMock($loaded);
        $xdebug->check();
        $this->assertEquals('', getenv('PHP_INI_SCAN_DIR'));
    }

    public static function setUpBeforeClass()
    {
        self::$envAllow = getenv(XdebugHandlerMock::ENV_ALLOW);
        self::$envIniScanDir = getenv('PHP_INI_SCAN_DIR');
    }

    public static function tearDownAfterClass()
    {
        if (false !== self::$envAllow) {
            putenv(XdebugHandlerMock::ENV_ALLOW.'='.self::$envAllow);
        } else {
            putenv(XdebugHandlerMock::ENV_ALLOW);
        }

        if (false !== self::$envIniScanDir) {
            putenv('PHP_INI_SCAN_DIR='.self::$envIniScanDir);
        } else {
            putenv('PHP_INI_SCAN_DIR');
        }
    }

    protected function setUp()
    {
        putenv(XdebugHandlerMock::ENV_ALLOW);
        putenv('PHP_INI_SCAN_DIR');
    }
}
