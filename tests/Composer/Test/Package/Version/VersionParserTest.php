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

class VersionParserTest extends \PHPUnit_Framework_TestCase
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
}
