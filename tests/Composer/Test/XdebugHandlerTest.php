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
use Composer\Util\IniHelper;

/**
 * @author John Stevenson <john-stevenson@blueyonder.co.uk>
 *
 * We use PHP_BINARY which only became available in PHP 5.4 *
 * @requires PHP 5.4
 */
class XdebugHandlerTest extends \PHPUnit_Framework_TestCase
{
    public static $env = array();

    public function testRestartWhenLoaded()
    {
        $loaded = true;

        $xdebug = new XdebugHandlerMock($loaded);
        $xdebug->check();
        $this->assertTrue($xdebug->restarted);
        $this->assertInternalType('string', getenv(IniHelper::ENV_ORIGINAL));
    }

    public function testNoRestartWhenNotLoaded()
    {
        $loaded = false;

        $xdebug = new XdebugHandlerMock($loaded);
        $xdebug->check();
        $this->assertFalse($xdebug->restarted);
        $this->assertFalse(getenv(IniHelper::ENV_ORIGINAL));
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

    public function testEnvVersionWhenLoaded()
    {
        $loaded = true;

        $xdebug = new XdebugHandlerMock($loaded);
        $xdebug->check();
        $this->assertEquals($xdebug->testVersion, getenv(XdebugHandlerMock::ENV_VERSION));

        // Mimic successful restart
        $loaded = false;
        $xdebug = new XdebugHandlerMock($loaded);
        $xdebug->check();
        $this->assertEquals($xdebug->testVersion, getenv(XdebugHandlerMock::ENV_VERSION));
    }

    public function testEnvVersionWhenNotLoaded()
    {
        $loaded = false;

        $xdebug = new XdebugHandlerMock($loaded);
        $xdebug->check();
        $this->assertEquals(false, getenv(XdebugHandlerMock::ENV_VERSION));
    }

    public function testEnvVersionWhenRestartFails()
    {
        $loaded = true;

        $xdebug = new XdebugHandlerMock($loaded);
        $xdebug->check();

        // Mimic failed restart
        $xdebug = new XdebugHandlerMock($loaded);
        $xdebug->check();
        $this->assertEquals(false, getenv(XdebugHandlerMock::ENV_VERSION));
    }

    public static function setUpBeforeClass()
    {
        // Save current state
        $names = array(
            XdebugHandlerMock::ENV_ALLOW,
            XdebugHandlerMock::ENV_VERSION,
            'PHP_INI_SCAN_DIR',
            IniHelper::ENV_ORIGINAL,
        );

        foreach ($names as $name) {
            self::$env[$name] = getenv($name);
        }
    }

    public static function tearDownAfterClass()
    {
        // Restore original state
        foreach (self::$env as $name => $value) {
            if (false !== $value) {
                putenv($name.'='.$value);
            } else {
                putenv($name);
            }
        }
    }

    protected function setUp()
    {
        // Ensure env is unset
        putenv(XdebugHandlerMock::ENV_ALLOW);
        putenv(XdebugHandlerMock::ENV_VERSION);
        putenv('PHP_INI_SCAN_DIR');
        putenv(IniHelper::ENV_ORIGINAL);
    }
}
