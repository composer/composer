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

use Composer\Test\Mock\XdebugHandlerMock as XdebugHandler;

/**
 * @author John Stevenson <john-stevenson@blueyonder.co.uk>
 */
class XdebugHandlerTest extends \PHPUnit_Framework_TestCase
{
    public function testRestartWhenLoaded()
    {
        $loaded = true;

        $xdebug = new XdebugHandler($loaded);
        $xdebug->check();
        $this->assertTrue($xdebug->restarted || !defined('PHP_BINARY'));
    }

    public function testNoRestartWhenNotLoaded()
    {
        $loaded = false;

        $xdebug = new XdebugHandler($loaded);
        $xdebug->check();
        $this->assertFalse($xdebug->restarted);
    }

    public function testNoRestartWhenLoadedAndAllowed()
    {
        $loaded = true;
        putenv(XdebugHandler::ENV_ALLOW.'=1');

        $xdebug = new XdebugHandler($loaded);
        $xdebug->check();
        $this->assertFalse($xdebug->restarted);
    }
}
