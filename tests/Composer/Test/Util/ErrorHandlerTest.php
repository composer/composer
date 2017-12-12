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
      * @expectedException ErrorException
      * @expectedExceptionMessage Undefined index: baz
      */
    public function testErrorHandlerCaptureNotice()
    {
        ErrorHandler::register();

        $array = array('foo' => 'bar');
        $array['baz'];
    }

     /**
      * Test ErrorHandler handles warnings
      * @expectedException ErrorException
      * @expectedExceptionMessage array_merge
      */
    public function testErrorHandlerCaptureWarning()
    {
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
