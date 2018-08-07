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

namespace Composer\Test\Json;

use Composer\Json\JsonFormatter;
use PHPUnit\Framework\TestCase;

class JsonFormatterTest extends TestCase
{
    /**
     * Test if \u0119 will get correctly formatted (unescaped)
     * https://github.com/composer/composer/issues/2613
     */
    public function testUnicodeWithPrependedSlash()
    {
        if (!extension_loaded('mbstring')) {
            $this->markTestSkipped('Test requires the mbstring extension');
        }
        $backslash = chr(92);
        $data = '"' . $backslash . $backslash . $backslash . 'u0119"';
        $expected = '"' . $backslash . $backslash . 'ę"';
        $this->assertEquals($expected, JsonFormatter::format($data, true, true));
    }

    /**
     * Surrogate pairs are intentionally skipped and not unescaped
     * https://github.com/composer/composer/issues/7510
     */
    public function testUtf16SurrogatePair()
    {
        if (!extension_loaded('mbstring')) {
            $this->markTestSkipped('Test requires the mbstring extension');
        }

        $escaped = '"\ud83d\ude00"';
        $this->assertEquals($escaped, JsonFormatter::format($escaped, true, true));
    }
}
