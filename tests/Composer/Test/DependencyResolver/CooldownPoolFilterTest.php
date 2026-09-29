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

namespace Composer\Test\DependencyResolver;

use Composer\DependencyResolver\Pool;
use Composer\Policy\CooldownPolicyConfig;
use Composer\Policy\IgnorePackageRule;
use Composer\Policy\ListPolicyConfig;
use Composer\DependencyResolver\CooldownPoolFilter;
use Composer\DependencyResolver\Request;
use Composer\Package\AliasPackage;
use Composer\Package\Link;
use Composer\Package\Package;
use Composer\Package\RootPackage;
use Composer\Semver\Constraint\Constraint;
use Composer\Semver\Constraint\MatchAllConstraint;
use Composer\Test\TestCase;
use DateTimeImmutable;

class CooldownPoolFilterTest extends TestCase
{
    public function testFilterNewPackages(): void
    {
        $config = new CooldownPolicyConfig(true, ListPolicyConfig::AUDIT_IGNORE, [], 7 * 24 * 3600); // 7 days
        $now = new DateTimeImmutable('2026-01-15 12:00:00');
        $filter = new CooldownPoolFilter($config, $now);

        $oldPackage = new Package('vendor/pkg', '1.0.0.0', '1.0.0');
        $oldPackage->setReleaseDate(new DateTimeImmutable('2026-01-01 12:00:00'));

        $newPackage = new Package('vendor/pkg', '2.0.0.0', '2.0.0');
        $newPackage->setReleaseDate(new DateTimeImmutable('2026-01-14 12:00:00')); // 1 day old

        $pool = new Pool([$oldPackage, $newPackage]);
        $filteredPool = $filter->filter($pool, new Request());

        $this->assertSame([$oldPackage], $filteredPool->getPackages());
        $this->assertTrue($filteredPool->isCooldownRemovedPackageVersion('vendor/pkg', new Constraint('==', '2.0.0.0')));
    }

    public function testAvailableInReportsTotalDaysForLongWaitsSpanningMultipleMonths(): void
    {
        // 60-day cooldown; released 1 day ago -> available in 59 days
        $config = new CooldownPolicyConfig(true, ListPolicyConfig::AUDIT_IGNORE, [], 60 * 24 * 3600);
        $now = new DateTimeImmutable('2026-01-15 12:00:00');
        $filter = new CooldownPoolFilter($config, $now);

        $package = new Package('vendor/pkg', '2.0.0.0', '2.0.0');
        $package->setReleaseDate(new DateTimeImmutable('2026-01-14 12:00:00'));

        $pool = new Pool([$package]);
        $filteredPool = $filter->filter($pool, new Request());

        $info = $filteredPool->getCooldownInfoForPackageVersion('vendor/pkg', new Constraint('==', '2.0.0.0'));
        self::assertNotNull($info);
        self::assertSame('59 days', $info['availableIn']);
    }

    public function testPublishedDateTakesPrecedenceOverReleaseDate(): void
    {
        $config = new CooldownPolicyConfig(true, ListPolicyConfig::AUDIT_IGNORE, [], 7 * 24 * 3600); // 7 days
        $now = new DateTimeImmutable('2026-01-15 12:00:00');
        $filter = new CooldownPoolFilter($config, $now);

        // Author-controlled `time` claims the version is old enough, but the
        // server-set published date shows it is actually brand new -> withheld
        $package = new Package('vendor/pkg', '2.0.0.0', '2.0.0');
        $package->setReleaseDate(new DateTimeImmutable('2025-01-01 12:00:00'));
        $package->setPublishedDate(new DateTimeImmutable('2026-01-14 12:00:00'));

        $pool = new Pool([$package]);
        $filteredPool = $filter->filter($pool, new Request());

        self::assertEmpty($filteredPool->getPackages());
        $info = $filteredPool->getCooldownInfoForPackageVersion('vendor/pkg', new Constraint('==', '2.0.0.0'));
        self::assertNotNull($info);
        self::assertSame('published-time', $info['source']);
    }

    public function testFallsBackToReleaseDateWhenNoPublishedDate(): void
    {
        $config = new CooldownPolicyConfig(true, ListPolicyConfig::AUDIT_IGNORE, [], 7 * 24 * 3600); // 7 days
        $now = new DateTimeImmutable('2026-01-15 12:00:00');
        $filter = new CooldownPoolFilter($config, $now);

        $package = new Package('vendor/pkg', '2.0.0.0', '2.0.0');
        $package->setReleaseDate(new DateTimeImmutable('2026-01-14 12:00:00')); // no published date

        $pool = new Pool([$package]);
        $filteredPool = $filter->filter($pool, new Request());

        self::assertEmpty($filteredPool->getPackages());
        $info = $filteredPool->getCooldownInfoForPackageVersion('vendor/pkg', new Constraint('==', '2.0.0.0'));
        self::assertNotNull($info);
        self::assertSame('time', $info['source']);
    }

    public function testIgnoredPackagesAreNotFiltered(): void
    {
        $config = new CooldownPolicyConfig(true, ListPolicyConfig::AUDIT_IGNORE, [
            'internal/*' => [new IgnorePackageRule('internal/*', new MatchAllConstraint(), 'Internal packages')],
        ], 7 * 24 * 3600);
        $now = new DateTimeImmutable('2026-01-15 12:00:00');
        $filter = new CooldownPoolFilter($config, $now);

        $newPackage = new Package('internal/pkg', '1.0.0.0', '1.0.0');
        $newPackage->setReleaseDate(new DateTimeImmutable('2026-01-14 12:00:00'));

        $pool = new Pool([$newPackage]);
        $filteredPool = $filter->filter($pool, new Request());

        $this->assertSame([$newPackage], $filteredPool->getPackages());
        $this->assertFalse($filteredPool->isCooldownRemovedPackageVersion('internal/pkg', new Constraint('==', '1.0.0.0')));
    }

    public function testDevVersionsAreNotFiltered(): void
    {
        $config = new CooldownPolicyConfig(true, ListPolicyConfig::AUDIT_IGNORE, [], 7 * 24 * 3600);
        $now = new DateTimeImmutable('2026-01-15 12:00:00');
        $filter = new CooldownPoolFilter($config, $now);

        $devPackage = new Package('vendor/pkg', 'dev-main', 'dev-main');
        $devPackage->setReleaseDate(new DateTimeImmutable('2026-01-14 12:00:00'));

        $pool = new Pool([$devPackage]);
        $filteredPool = $filter->filter($pool, new Request());

        $this->assertSame([$devPackage], $filteredPool->getPackages());
    }

    public function testPackagesWithoutReleaseDateAreNotFiltered(): void
    {
        $config = new CooldownPolicyConfig(true, ListPolicyConfig::AUDIT_IGNORE, [], 7 * 24 * 3600);
        $now = new DateTimeImmutable('2026-01-15 12:00:00');
        $filter = new CooldownPoolFilter($config, $now);

        $package = new Package('vendor/pkg', '1.0.0.0', '1.0.0');
        // No release date set

        $pool = new Pool([$package]);
        $filteredPool = $filter->filter($pool, new Request());

        $this->assertSame([$package], $filteredPool->getPackages());
    }

    public function testDisabledConfigDoesNotFilter(): void
    {
        $config = new CooldownPolicyConfig(true, ListPolicyConfig::AUDIT_IGNORE, [], null);
        $filter = new CooldownPoolFilter($config);

        $newPackage = new Package('vendor/pkg', '1.0.0.0', '1.0.0');
        $newPackage->setReleaseDate(new DateTimeImmutable('now'));

        $pool = new Pool([$newPackage]);
        $filteredPool = $filter->filter($pool, new Request());

        $this->assertSame([$newPackage], $filteredPool->getPackages());
    }

    public function testZeroConfigDoesNotFilter(): void
    {
        $config = new CooldownPolicyConfig(true, ListPolicyConfig::AUDIT_IGNORE, [], 0);
        $filter = new CooldownPoolFilter($config);

        $newPackage = new Package('vendor/pkg', '1.0.0.0', '1.0.0');
        $newPackage->setReleaseDate(new DateTimeImmutable('now'));

        $pool = new Pool([$newPackage]);
        $filteredPool = $filter->filter($pool, new Request());

        $this->assertSame([$newPackage], $filteredPool->getPackages());
    }

    public function testCooldownInfoIsStored(): void
    {
        $config = new CooldownPolicyConfig(true, ListPolicyConfig::AUDIT_IGNORE, [], 7 * 24 * 3600); // 7 days
        $now = new DateTimeImmutable('2026-01-15 12:00:00');
        $filter = new CooldownPoolFilter($config, $now);

        $newPackage = new Package('vendor/pkg', '2.0.0.0', '2.0.0');
        $newPackage->setReleaseDate(new DateTimeImmutable('2026-01-14 12:00:00'));

        $pool = new Pool([$newPackage]);
        $filteredPool = $filter->filter($pool, new Request());

        $this->assertEmpty($filteredPool->getPackages());

        $releaseAgeInfo = $filteredPool->getCooldownInfoForPackageVersion('vendor/pkg', new Constraint('==', '2.0.0.0'));
        $this->assertNotNull($releaseAgeInfo);
        $this->assertSame('2.0.0', $releaseAgeInfo['prettyVersion']);
        $this->assertArrayHasKey('releaseDate', $releaseAgeInfo);
        $this->assertArrayHasKey('availableIn', $releaseAgeInfo);
    }

    public function testOldEnoughPackagesAreKept(): void
    {
        $config = new CooldownPolicyConfig(true, ListPolicyConfig::AUDIT_IGNORE, [], 24 * 3600); // 1 day
        $now = new DateTimeImmutable('2026-01-15 12:00:00');
        $filter = new CooldownPoolFilter($config, $now);

        $oldEnoughPackage = new Package('vendor/pkg', '1.0.0.0', '1.0.0');
        $oldEnoughPackage->setReleaseDate(new DateTimeImmutable('2026-01-13 12:00:00')); // 2 days old

        $pool = new Pool([$oldEnoughPackage]);
        $filteredPool = $filter->filter($pool, new Request());

        $this->assertSame([$oldEnoughPackage], $filteredPool->getPackages());
    }

    public function testExactlyAtCutoffIsNotFiltered(): void
    {
        $config = new CooldownPolicyConfig(true, ListPolicyConfig::AUDIT_IGNORE, [], 24 * 3600); // 1 day
        $now = new DateTimeImmutable('2026-01-15 12:00:00');
        $filter = new CooldownPoolFilter($config, $now);

        // Package released exactly 24 hours ago is old enough and must be kept
        $exactCutoffPackage = new Package('vendor/pkg', '1.0.0.0', '1.0.0');
        $exactCutoffPackage->setReleaseDate(new DateTimeImmutable('2026-01-14 12:00:00'));

        $pool = new Pool([$exactCutoffPackage]);
        $filteredPool = $filter->filter($pool, new Request());

        // Package at exact cutoff should be kept (it's exactly old enough)
        $this->assertSame([$exactCutoffPackage], $filteredPool->getPackages());
    }

    public function testRootPackageNotFiltered(): void
    {
        $config = new CooldownPolicyConfig(true, ListPolicyConfig::AUDIT_IGNORE, [], 7 * 24 * 3600); // 7 days
        $now = new DateTimeImmutable('2026-01-15 12:00:00');
        $filter = new CooldownPoolFilter($config, $now);

        // Create a root package that would normally be filtered (too new)
        $rootPackage = new RootPackage('my/project', '1.0.0.0', '1.0.0');
        $rootPackage->setReleaseDate(new DateTimeImmutable('2026-01-14 12:00:00')); // 1 day old

        $pool = new Pool([$rootPackage]);
        $filteredPool = $filter->filter($pool, new Request());

        // Root packages should never be filtered
        $this->assertSame([$rootPackage], $filteredPool->getPackages());
    }

    public function testPlatformPackagesNotFiltered(): void
    {
        $config = new CooldownPolicyConfig(true, ListPolicyConfig::AUDIT_IGNORE, [], 7 * 24 * 3600); // 7 days
        $now = new DateTimeImmutable('2026-01-15 12:00:00');
        $filter = new CooldownPoolFilter($config, $now);

        // Create platform packages that would normally be filtered
        $phpPackage = new Package('php', '8.3.0.0', '8.3.0');
        $phpPackage->setReleaseDate(new DateTimeImmutable('2026-01-14 12:00:00'));

        $extPackage = new Package('ext-json', '8.3.0.0', '8.3.0');
        $extPackage->setReleaseDate(new DateTimeImmutable('2026-01-14 12:00:00'));

        $libPackage = new Package('lib-openssl', '3.0.0.0', '3.0.0');
        $libPackage->setReleaseDate(new DateTimeImmutable('2026-01-14 12:00:00'));

        $pool = new Pool([$phpPackage, $extPackage, $libPackage]);
        $filteredPool = $filter->filter($pool, new Request());

        // Platform packages should never be filtered
        $this->assertCount(3, $filteredPool->getPackages());
        $this->assertContains($phpPackage, $filteredPool->getPackages());
        $this->assertContains($extPackage, $filteredPool->getPackages());
        $this->assertContains($libPackage, $filteredPool->getPackages());
    }

    public function testLockedPackagesNotFiltered(): void
    {
        $config = new CooldownPolicyConfig(true, ListPolicyConfig::AUDIT_IGNORE, [], 7 * 24 * 3600); // 7 days
        $now = new DateTimeImmutable('2026-01-15 12:00:00');
        $filter = new CooldownPoolFilter($config, $now);

        // Create a package that would normally be filtered
        $lockedPackage = new Package('vendor/pkg', '1.0.0.0', '1.0.0');
        $lockedPackage->setReleaseDate(new DateTimeImmutable('2026-01-14 12:00:00'));

        // Create request with the package as locked
        $request = new Request();
        $request->lockPackage($lockedPackage);

        $pool = new Pool([$lockedPackage]);
        $filteredPool = $filter->filter($pool, $request);

        // Locked packages should never be filtered
        $this->assertSame([$lockedPackage], $filteredPool->getPackages());
    }

    public function testPackageWithMultipleNamesTrackedCorrectly(): void
    {
        $config = new CooldownPolicyConfig(true, ListPolicyConfig::AUDIT_IGNORE, [], 7 * 24 * 3600); // 7 days
        $now = new DateTimeImmutable('2026-01-15 12:00:00');
        $filter = new CooldownPoolFilter($config, $now);

        $newPackage = new Package('vendor/pkg', '2.0.0.0', '2.0.0');
        $newPackage->setReleaseDate(new DateTimeImmutable('2026-01-14 12:00:00'));
        $newPackage->setReplaces([
            'vendor/replaced' => new \Composer\Package\Link(
                'vendor/pkg',
                'vendor/replaced',
                new Constraint('==', '2.0.0.0'),
                \Composer\Package\Link::TYPE_REPLACE,
                '2.0.0'
            ),
        ]);

        $pool = new Pool([$newPackage]);
        $filteredPool = $filter->filter($pool, new Request());

        $this->assertEmpty($filteredPool->getPackages());

        // Replaced names are part of getNames(false), so a requirement on either name explains the cooldown
        $this->assertTrue($filteredPool->isCooldownRemovedPackageVersion('vendor/pkg', new Constraint('==', '2.0.0.0')));
        $this->assertTrue($filteredPool->isCooldownRemovedPackageVersion('vendor/replaced', new Constraint('==', '2.0.0.0')));
    }

    public function testDevAliasIsKeptTogetherWithItsDevTarget(): void
    {
        $config = new CooldownPolicyConfig(true, ListPolicyConfig::AUDIT_IGNORE, [], 7 * 24 * 3600); // 7 days
        $now = new DateTimeImmutable('2026-01-15 12:00:00');
        $filter = new CooldownPoolFilter($config, $now);

        // "dev-main as 1.0.0": the alias reports a stable version while the branch commit is recent
        $branch = new Package('vendor/pkg', 'dev-main', 'dev-main');
        $branch->setReleaseDate(new DateTimeImmutable('2026-01-14 12:00:00'));
        $alias = new AliasPackage($branch, '1.0.0.0', '1.0.0');

        $pool = new Pool([$branch, $alias]);
        $filteredPool = $filter->filter($pool, new Request());

        $this->assertSame([$branch, $alias], $filteredPool->getPackages());
    }

    public function testAliasOfWithheldVersionIsWithheldToo(): void
    {
        $config = new CooldownPolicyConfig(true, ListPolicyConfig::AUDIT_IGNORE, [], 7 * 24 * 3600); // 7 days
        $now = new DateTimeImmutable('2026-01-15 12:00:00');
        $filter = new CooldownPoolFilter($config, $now);

        // "2.0.0 as 2.0.x-dev": the alias is a dev version but requires the withheld base
        $base = new Package('vendor/pkg', '2.0.0.0', '2.0.0');
        $base->setReleaseDate(new DateTimeImmutable('2026-01-14 12:00:00'));
        $alias = new AliasPackage($base, '2.0.9999999.9999999-dev', '2.0.x-dev');

        $pool = new Pool([$base, $alias]);
        $filteredPool = $filter->filter($pool, new Request());

        $this->assertSame([], $filteredPool->getPackages());
        $this->assertTrue($filteredPool->isCooldownRemovedPackageVersion('vendor/pkg', new Constraint('==', '2.0.0.0')));
        $this->assertTrue($filteredPool->isCooldownRemovedPackageVersion('vendor/pkg', new Constraint('==', '2.0.9999999.9999999-dev')));
    }
}
