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
use Composer\TestCase;

/**
 * ErrorHandler test case
 */
class ErrorHandlerTest extends TestCase
{
    /**
     * Test ErrorHandler handles notices
     */
    public function testErrorHandlerCaptureNotice()
    {
        $this->setExpectedException('\ErrorException', 'Undefined index: baz');

        ErrorHandler::register();

        $array = array('foo' => 'bar');
        $array['baz'];
    }

    /**
     * Test ErrorHandler handles warnings
     */
    public function testErrorHandlerCaptureWarning()
    {
        $this->setExpectedException('\ErrorException', 'array_merge');

        ErrorHandler::register();

        array_merge(array(), 'string');
    }

    /**
     * Test ErrorHandler handles warnings
     */
    public function testErrorHandlerRespectsAtOperator()
    {
        ErrorHandler::register();

        @trigger_error('test', E_USER_NOTICE);
    }
}
