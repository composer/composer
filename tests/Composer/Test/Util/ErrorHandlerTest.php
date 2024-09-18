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

use Composer\Util\ErrorHandler;
use Composer\Test\TestCase;

/**
 * ErrorHandler test case
 */
class ErrorHandlerTest extends TestCase
{
    public function setUp(): void
    {
        ErrorHandler::register();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        restore_error_handler();
    }

    /**
     * Test ErrorHandler handles notices
     */
    public function testErrorHandlerCaptureNotice(): void
    {
        if (\PHP_VERSION_ID >= 80000) {
            self::expectException('\ErrorException');
            self::expectExceptionMessage('Undefined array key "baz"');
        } else {
            self::expectException('\ErrorException');
            self::expectExceptionMessage('Undefined index: baz');
        }

        $array = ['foo' => 'bar'];
        // @phpstan-ignore offsetAccess.notFound, expr.resultUnused
        $array['baz'];
    }

    /**
     * Test ErrorHandler handles warnings
     */
    public function testErrorHandlerCaptureWarning(): void
    {
        if (\PHP_VERSION_ID >= 80000) {
            self::expectException('TypeError');
            self::expectExceptionMessage('array_merge');
        } else {
            self::expectException('ErrorException');
            self::expectExceptionMessage('array_merge');
        }

        // @phpstan-ignore function.resultUnused, argument.type
        array_merge([], 'string');
    }

    /**
     * Test ErrorHandler handles warnings
     * @doesNotPerformAssertions
     */
    public function testErrorHandlerRespectsAtOperator(): void
    {
        @trigger_error('test', E_USER_NOTICE);
    }
}
