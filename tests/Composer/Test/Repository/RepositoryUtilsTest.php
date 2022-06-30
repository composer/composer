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
     * @param PackageInterface $requirer
     * @param string[] $expected
     */
    public function testFilterRequiredPackages(array $pkgs, PackageInterface $requirer, array $expected): void
    {
        $expected = array_map(static function (string $name) use ($pkgs): PackageInterface {
            return $pkgs[$name];
        }, $expected);

        self::assertSame($expected, RepositoryUtils::filterRequiredPackages($pkgs, $requirer));
    }

    /**
     * @return array<PackageInterface>
     */
    private function getPackages(): array
    {
        $packageA = $this->getPackage('required/a');
        $packageB = $this->getPackage('required/b');
        $this->configureLinks($packageB, ['require' => ['required/c' => '*']]);
        $packageC = $this->getPackage('required/c');
        $packageCAlias = $this->getAliasPackage($packageC, '2.0.0');

        $packageCircular = $this->getPackage('required/circular');
        $this->configureLinks($packageCircular, ['require' => ['required/circular-b' => '*']]);
        $packageCircularB = $this->getPackage('required/circular-b');
        $this->configureLinks($packageCircularB, ['require' => ['required/circular' => '*']]);

        return [
            $this->getPackage('dummy/pkg'),
            $this->getPackage('dummy/pkg2', '2.0.0'),
            'a' => $packageA,
            'b' => $packageB,
            'c' => $packageC,
            'c-alias' => $packageCAlias,
            'circular' => $packageCircular,
            'circular-b' => $packageCircularB,
        ];
    }

    public function provideFilterRequireTests(): Generator
    {
        $pkgs = $this->getPackages();

        $requirer = $this->getPackage('requirer/pkg');
        yield 'no require' => [$pkgs, $requirer, []];

        $requirer = $this->getPackage('requirer/pkg');
        $this->configureLinks($requirer, ['require-dev' => ['required/a' => '*']]);
        yield 'require-dev has no effect' => [$pkgs, $requirer, []];

        $requirer = $this->getPackage('requirer/pkg');
        $this->configureLinks($requirer, ['require' => ['required/a' => '*']]);
        yield 'simple require' => [$pkgs, $requirer, ['a']];

        $requirer = $this->getPackage('requirer/pkg');
        $this->configureLinks($requirer, ['require' => ['required/a' => 'dev-lala']]);
        yield 'require constraint is irrelevant' => [$pkgs, $requirer, ['a']];

        $requirer = $this->getPackage('requirer/pkg');
        $this->configureLinks($requirer, ['require' => ['required/b' => '*']]);
        yield 'require transitive deps and aliases are included' => [$pkgs, $requirer, ['b', 'c', 'c-alias']];

        $requirer = $this->getPackage('requirer/pkg');
        $this->configureLinks($requirer, ['require' => ['required/circular' => '*']]);
        yield 'circular deps are no problem' => [$pkgs, $requirer, ['circular', 'circular-b']];
    }
}
