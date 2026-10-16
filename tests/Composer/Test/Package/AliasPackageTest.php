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

namespace Composer\Test\Package;

use Composer\Package\AliasPackage;
use Composer\Package\Link;
use Composer\Package\Package;
use Composer\Semver\Constraint\Constraint;
use Composer\Test\TestCase;

class AliasPackageTest extends TestCase
{
    public function testFeatureRequiresAreInheritedFromTheAliasedPackage(): void
    {
        $aliasOf = new Package('a/a', '1.0.0.0', '1.0.0');
        $aliasOf->setFeatureRequires(['b/b' => ['logging']]);

        $alias = new AliasPackage($aliasOf, '2.0.0.0', '2.0.0');

        self::assertSame(['b/b' => ['logging']], $alias->getFeatureRequires());
    }

    public function testFeatureDescriptionIsKeptOnTheAlias(): void
    {
        $aliasOf = new Package('a/a', '1.0.0.0', '1.0.0');
        $aliasOf->setFeatures([
            'logging' => [
                'description' => 'Adds logging',
                'require' => ['b/b' => new Link('a/a', 'b/b', new Constraint('=', '1.0.0.0'), Link::TYPE_REQUIRE, '1.0.0')],
            ],
        ]);

        $alias = new AliasPackage($aliasOf, '2.0.0.0', '2.0.0');

        $feature = $alias->getFeatures()['logging'];
        self::assertSame('Adds logging', $feature['description'] ?? null);
        self::assertSame('1.0.0', ($feature['require'] ?? [])['b/b']->getPrettyConstraint());
    }

    public function testFeatureWithoutRequireIsKeptOnTheAlias(): void
    {
        $aliasOf = new Package('a/a', '1.0.0.0', '1.0.0');
        $aliasOf->setFeatures(['empty' => ['description' => 'Nothing to install']]);

        $alias = new AliasPackage($aliasOf, '2.0.0.0', '2.0.0');

        $feature = $alias->getFeatures()['empty'];
        self::assertSame([], $feature['require'] ?? null);
        self::assertSame('Nothing to install', $feature['description'] ?? null);
    }

    public function testFeatureSelfVersionRequireIsResolvedToTheAliasVersion(): void
    {
        $aliasOf = new Package('a/a', '1.0.0.0', '1.0.0');
        $aliasOf->setFeatures([
            'extras' => [
                'require' => ['a/extra' => new Link('a/a', 'a/extra', new Constraint('=', '1.0.0.0'), Link::TYPE_REQUIRE, 'self.version')],
            ],
        ]);

        $alias = new AliasPackage($aliasOf, '2.0.0.0', '2.0.0');

        $link = ($alias->getFeatures()['extras']['require'] ?? [])['a/extra'];
        self::assertSame('2.0.0', $link->getPrettyConstraint());
        self::assertTrue($link->getConstraint()->matches(new Constraint('==', '2.0.0.0')));
        // the aliased package must keep its own resolution
        $originalLink = ($aliasOf->getFeatures()['extras']['require'] ?? [])['a/extra'];
        self::assertSame('self.version', $originalLink->getPrettyConstraint());
    }

    public function testSetFeaturesOnAnAliasResolvesSelfVersion(): void
    {
        $aliasOf = new Package('a/a', '1.0.0.0', '1.0.0');
        $alias = new AliasPackage($aliasOf, '2.0.0.0', '2.0.0');

        $alias->setFeatures([
            'extras' => [
                'require' => ['a/extra' => new Link('a/a', 'a/extra', new Constraint('=', '1.0.0.0'), Link::TYPE_REQUIRE, 'self.version')],
            ],
        ]);

        $link = ($alias->getFeatures()['extras']['require'] ?? [])['a/extra'];
        self::assertSame('2.0.0', $link->getPrettyConstraint());
    }

    public function testFeaturesDefaultToEmpty(): void
    {
        $alias = new AliasPackage(new Package('a/a', '1.0.0.0', '1.0.0'), '2.0.0.0', '2.0.0');

        self::assertSame([], $alias->getFeatures());
        self::assertSame([], $alias->getFeatureRequires());
    }
}
