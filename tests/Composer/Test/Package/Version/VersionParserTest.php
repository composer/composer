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

namespace Composer\Test\Package\Version;

use Composer\Package\Version\VersionParser;
use Composer\Test\TestCase;

class VersionParserTest extends TestCase
{
    /**
     * @dataProvider provideParseNameVersionPairsData
     *
     * @param string[]                     $pairs
     * @param array<array<string, string>> $result
     */
    public function testParseNameVersionPairs(array $pairs, array $result): void
    {
        $versionParser = new VersionParser();

        $this->assertSame($result, $versionParser->parseNameVersionPairs($pairs));
    }

    public static function provideParseNameVersionPairsData(): array
    {
        return [
            [['php:^7.0'], [['name' => 'php', 'version' => '^7.0']]],
            [['php', '^7.0'], [['name' => 'php', 'version' => '^7.0']]],
            [['php', 'ext-apcu'], [['name' => 'php'], ['name' => 'ext-apcu']]],
            [['foo/*', 'bar*', 'acme/baz', '*@dev'], [['name' => 'foo/*'], ['name' => 'bar*'], ['name' => 'acme/baz', 'version' => '*@dev']]],
            [['php', '*'], [['name' => 'php', 'version' => '*']]],
        ];
    }

    /**
     * @dataProvider provideIsUpgradeTests
     */
    public function testIsUpgrade(string $from, string $to, bool $expected): void
    {
        $this->assertSame($expected, VersionParser::isUpgrade($from, $to));
    }

    public static function provideIsUpgradeTests(): array
    {
        return [
            ['0.9.0.0', '1.0.0.0', true],
            ['1.0.0.0', '0.9.0.0', false],
            ['1.0.0.0', VersionParser::DEFAULT_BRANCH_ALIAS, true],
            [VersionParser::DEFAULT_BRANCH_ALIAS, VersionParser::DEFAULT_BRANCH_ALIAS, true],
            [VersionParser::DEFAULT_BRANCH_ALIAS, '1.0.0.0', false],
            ['1.0.0.0', 'dev-foo', true],
            ['dev-foo', 'dev-foo', true],
            ['dev-foo', '1.0.0.0', true],
        ];
    }
}
