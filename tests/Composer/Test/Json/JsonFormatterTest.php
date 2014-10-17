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

class JsonFormatterTest extends \PHPUnit_Framework_TestCase
{
    /**
     * Test if \u0119 (196+153) will get correctly formatted
     * See ticket #2613
     */
    public function testUnicodeWithPrependedSlash()
    {
        if (!extension_loaded('mbstring')) {
            $this->markTestSkipped('Test requires the mbstring extension');
        }

        $data = '"' . chr(92) . chr(92) . chr(92) . 'u0119"';
        $encodedData = JsonFormatter::format($data, true, true);
        $expected = '34+92+92+196+153+34';
        $this->assertEquals($expected, $this->getCharacterCodes($encodedData));
    }

    /**
     * Convert string to character codes split by a plus sign
     * @param  string $string
     * @return string
     */
    protected function getCharacterCodes($string)
    {
        $codes = array();
        for ($i = 0; $i < strlen($string); $i++) {
            $codes[] = ord($string[$i]);
        }

        return implode('+', $codes);
    }
}
