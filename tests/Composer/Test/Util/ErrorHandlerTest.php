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
        if (PHP_VERSION_ID >= 80000) {
            $this->setExpectedException('\ErrorException', 'Undefined array key "baz"');
        } else {
            $this->setExpectedException('\ErrorException', 'Undefined index: baz');
        }

        $array = array('foo' => 'bar');
        // @phpstan-ignore-next-line
        $array['baz'];
    }

    /**
     * Test ErrorHandler handles warnings
     */
    public function testErrorHandlerCaptureWarning()
    {
        if (PHP_VERSION_ID >= 80000) {
            $this->setExpectedException('TypeError', 'array_merge');
        } else {
            $this->setExpectedException('ErrorException', 'array_merge');
        }

        // @phpstan-ignore-next-line
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
