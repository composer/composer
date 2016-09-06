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
 * @backupGlobals disabled
 * @runTestsInSeparateProcesses
 */
class XdebugHandlerTest extends \PHPUnit_Framework_TestCase
{
    public function testRestartWhenLoaded()
    {
        $loaded = true;

        $xdebug = new XdebugHandlerMock($loaded);
        $xdebug->check();
        $this->assertTrue($xdebug->restarted || !defined('PHP_BINARY'));
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

    public function testForceColorSupport()
    {
        $xdebug = new XdebugHandlerMock();
        $xdebug->output->setDecorated(true);
        $xdebug->check();

        $args = explode(' ', $xdebug->command);
        $this->assertTrue(in_array('--ansi', $args) || !defined('PHP_BINARY'));
    }

    public function testIgnoreColorSupportIfNoAnsi()
    {
        $xdebug = new XdebugHandlerMock();
        $xdebug->output->setDecorated(true);
        $_SERVER['argv'][] = '--no-ansi';
        $xdebug->check();

        $args = explode(' ', $xdebug->command);
        $this->assertTrue(!in_array('--ansi', $args) || !defined('PHP_BINARY'));
    }
}
