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

namespace Composer\Test\Package\Version;

use Composer\Package\Version\VersionParser;
use PHPUnit\Framework\TestCase;

class VersionParserTest extends TestCase
{
    /**
     * @dataProvider getParseNameVersionPairsData
     */
    public function testParseNameVersionPairs($pairs, $result)
    {
        $versionParser = new VersionParser();

        $this->assertSame($result, $versionParser->parseNameVersionPairs($pairs));
    }

    public function getParseNameVersionPairsData()
    {
        return array(
            array(array('php:^7.0'), array(array('name' => 'php', 'version' => '^7.0'))),
            array(array('php', '^7.0'), array(array('name' => 'php', 'version' => '^7.0'))),
            array(array('php', 'ext-apcu'), array(array('name' => 'php'), array('name' => 'ext-apcu'))),
        );
    }

    /**
     * @dataProvider getIsUpgradeTests
     */
    public function testIsUpgrade($from, $to, $expected)
    {
        $this->assertSame($expected, VersionParser::isUpgrade($from, $to));
    }

    public function getIsUpgradeTests()
    {
        return array(
            array('0.9.0.0', '1.0.0.0', true),
            array('1.0.0.0', '0.9.0.0', false),
            array('1.0.0.0', '9999999-dev', true),
            array('9999999-dev', '9999999-dev', true),
            array('9999999-dev', '1.0.0.0', false),
            array('1.0.0.0', 'dev-foo', true),
            array('dev-foo', 'dev-foo', true),
            array('dev-foo', '1.0.0.0', true),
        );
    }
}
