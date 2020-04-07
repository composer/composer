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

use Composer\Util\ErrorHandler;
use Composer\Test\TestCase;

/**
 * ErrorHandler test case
 */
class ErrorHandlerTest extends TestCase
{
    public function setUp()
    {
        ErrorHandler::register();
    }

    public function tearDown()
    {
        restore_error_handler();
    }

    /**
     * Test ErrorHandler handles notices
     */
    public function testErrorHandlerCaptureNotice()
    {
        $this->setExpectedException('\ErrorException', 'Undefined index: baz');

        $array = array('foo' => 'bar');
        $array['baz'];
    }

    /**
     * Test ErrorHandler handles warnings
     */
    public function testErrorHandlerCaptureWarning()
    {
        $this->setExpectedException('\ErrorException', 'array_merge');

        array_merge(array(), 'string');
    }

    /**
     * Test ErrorHandler handles warnings
     * @doesNotPerformAssertions
     */
    public function testErrorHandlerRespectsAtOperator()
    {
        @trigger_error('test', E_USER_NOTICE);
    }
}
