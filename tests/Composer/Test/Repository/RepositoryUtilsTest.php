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

namespace Composer\Test\Repository;

use Composer\Package\PackageInterface;
use Composer\Repository\RepositoryUtils;
use Composer\Test\TestCase;
use Generator;

class RepositoryUtilsTest extends TestCase
{
    /**
     * @dataProvider provideFilterRequireTests
     * @param PackageInterface[] $pkgs
     * @param string[] $expected
     */
    public function testFilterRequiredPackages(array $pkgs, PackageInterface $requirer, array $expected, bool $includeRequireDev = false): void
    {
        $expected = array_map(static function (string $name) use ($pkgs): PackageInterface {
            return $pkgs[$name];
        }, $expected);

        self::assertSame($expected, RepositoryUtils::filterRequiredPackages($pkgs, $requirer, $includeRequireDev));
    }

    /**
     * @return array<PackageInterface>
     */
    private static function getPackages(): array
    {
        $packageA = self::getPackage('required/a');
        $packageB = self::getPackage('required/b');
        self::configureLinks($packageB, ['require' => ['required/c' => '*']]);
        $packageC = self::getPackage('required/c');
        $packageCAlias = self::getAliasPackage($packageC, '2.0.0');

        $packageCircular = self::getPackage('required/circular');
        self::configureLinks($packageCircular, ['require' => ['required/circular-b' => '*']]);
        $packageCircularB = self::getPackage('required/circular-b');
        self::configureLinks($packageCircularB, ['require' => ['required/circular' => '*']]);

        return [
            self::getPackage('dummy/pkg'),
            self::getPackage('dummy/pkg2', '2.0.0'),
            'a' => $packageA,
            'b' => $packageB,
            'c' => $packageC,
            'c-alias' => $packageCAlias,
            'circular' => $packageCircular,
            'circular-b' => $packageCircularB,
        ];
    }

    public static function provideFilterRequireTests(): Generator
    {
        $pkgs = self::getPackages();

        $requirer = self::getPackage('requirer/pkg');
        yield 'no require' => [$pkgs, $requirer, []];

        $requirer = self::getPackage('requirer/pkg');
        self::configureLinks($requirer, ['require-dev' => ['required/a' => '*']]);
        yield 'require-dev has no effect' => [$pkgs, $requirer, []];

        $requirer = self::getPackage('requirer/pkg');
        self::configureLinks($requirer, ['require-dev' => ['required/a' => '*']]);
        yield 'require-dev works if called with it enabled' => [$pkgs, $requirer, ['a'], true];

        $requirer = self::getPackage('requirer/pkg');
        self::configureLinks($requirer, ['require' => ['required/a' => '*']]);
        yield 'simple require' => [$pkgs, $requirer, ['a']];

        $requirer = self::getPackage('requirer/pkg');
        self::configureLinks($requirer, ['require' => ['required/a' => 'dev-lala']]);
        yield 'require constraint is irrelevant' => [$pkgs, $requirer, ['a']];

        $requirer = self::getPackage('requirer/pkg');
        self::configureLinks($requirer, ['require' => ['required/b' => '*']]);
        yield 'require transitive deps and aliases are included' => [$pkgs, $requirer, ['b', 'c', 'c-alias']];

        $requirer = self::getPackage('requirer/pkg');
        self::configureLinks($requirer, ['require' => ['required/circular' => '*']]);
        yield 'circular deps are no problem' => [$pkgs, $requirer, ['circular', 'circular-b']];
    }
}
