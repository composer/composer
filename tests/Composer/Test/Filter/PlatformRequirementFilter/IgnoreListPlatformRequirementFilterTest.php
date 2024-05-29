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

namespace Composer\Test\Filter\PlatformRequirementFilter;

use Composer\Filter\PlatformRequirementFilter\IgnoreListPlatformRequirementFilter;
use Composer\Test\TestCase;

final class IgnoreListPlatformRequirementFilterTest extends TestCase
{
    /**
     * @dataProvider dataIsIgnored
     *
     * @param string[] $reqList
     */
    public function testIsIgnored(array $reqList, string $req, bool $expectIgnored): void
    {
        $platformRequirementFilter = new IgnoreListPlatformRequirementFilter($reqList);

        self::assertSame($expectIgnored, $platformRequirementFilter->isIgnored($req));
    }

    /**
     * @return array<string, mixed[]>
     */
    public static function dataIsIgnored(): array
    {
        return [
            'ext-json is ignored if listed' => [['ext-json', 'monolog/monolog'], 'ext-json', true],
            'php is not ignored if not listed' => [['ext-json', 'monolog/monolog'], 'php', false],
            'monolog/monolog is not ignored even if listed' => [['ext-json', 'monolog/monolog'], 'monolog/monolog', false],
            'ext-json is ignored if ext-* is listed' => [['ext-*'], 'ext-json', true],
            'php is ignored if php* is listed' => [['ext-*', 'php*'], 'php', true],
            'ext-json is ignored if * is listed' => [['foo', '*'], 'ext-json', true],
            'php is ignored if * is listed' => [['*', 'foo'], 'php', true],
            'monolog/monolog is not ignored even if * or monolog/* are listed' => [['*', 'monolog/*'], 'monolog/monolog', false],
            'empty list entry does not ignore' => [[''], 'ext-foo', false],
            'empty array does not ignore' => [[], 'ext-foo', false],
            'list entries are not completing each other' => [['ext-', 'foo'], 'ext-foo', false],
        ];
    }

    /**
     * @dataProvider dataIsUpperBoundIgnored
     *
     * @param string[] $reqList
     */
    public function testIsUpperBoundIgnored(array $reqList, string $req, bool $expectIgnored): void
    {
        $platformRequirementFilter = new IgnoreListPlatformRequirementFilter($reqList);

        self::assertSame($expectIgnored, $platformRequirementFilter->isUpperBoundIgnored($req));
    }

    /**
     * @return array<string, mixed[]>
     */
    public static function dataIsUpperBoundIgnored(): array
    {
        return [
            'ext-json is ignored if listed and fully ignored' => [['ext-json', 'monolog/monolog'], 'ext-json', true],
            'ext-json is ignored if listed and upper bound ignored' => [['ext-json+', 'monolog/monolog'], 'ext-json', true],
            'php is not ignored if not listed' => [['ext-json+', 'monolog/monolog'], 'php', false],
            'monolog/monolog is not ignored even if listed' => [['monolog/monolog'], 'monolog/monolog', false],
            'ext-json is ignored if ext-* is listed' => [['ext-*+'], 'ext-json', true],
            'php is ignored if php* is listed' => [['ext-*+', 'php*+'], 'php', true],
            'ext-json is ignored if * is listed' => [['foo', '*+'], 'ext-json', true],
            'php is ignored if * is listed' => [['*+', 'foo'], 'php', true],
            'monolog/monolog is not ignored even if * or monolog/* are listed' => [['*+', 'monolog/*+'], 'monolog/monolog', false],
            'empty list entry does not ignore' => [[''], 'ext-foo', false],
            'empty array does not ignore' => [[], 'ext-foo', false],
            'list entries are not completing each other' => [['ext-', 'foo'], 'ext-foo', false],
        ];
    }
}
