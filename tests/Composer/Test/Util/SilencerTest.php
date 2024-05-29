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

use Composer\Util\Silencer;
use Composer\Test\TestCase;

/**
 * SilencerTest
 *
 * @author Niels Keurentjes <niels.keurentjes@omines.com>
 */
class SilencerTest extends TestCase
{
    /**
     * Test succeeds when no warnings are emitted externally, and original level is restored.
     */
    public function testSilencer(): void
    {
        $before = error_reporting();

        // Check warnings are suppressed correctly
        Silencer::suppress();
        @trigger_error('Test', E_USER_WARNING);
        Silencer::restore();

        // Check all parameters and return values are passed correctly in a silenced call.
        $result = Silencer::call(static function ($a, $b, $c) {
            @trigger_error('Test', E_USER_WARNING);

            return $a * $b * $c;
        }, 2, 3, 4);
        self::assertEquals(24, $result);

        // Check the error reporting setting was restored correctly
        self::assertEquals($before, error_reporting());
    }

    /**
     * Test whether exception from silent callbacks are correctly forwarded.
     */
    public function testSilencedException(): void
    {
        $verification = microtime();
        self::expectException('RuntimeException');
        self::expectExceptionMessage($verification);
        Silencer::call(static function () use ($verification): void {
            throw new \RuntimeException($verification);
        });
    }
}
