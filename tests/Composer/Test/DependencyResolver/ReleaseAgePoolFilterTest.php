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

use Composer\Advisory\PartialSecurityAdvisory;
use Composer\Advisory\SecurityAdvisory;
use Composer\DependencyResolver\Pool;
use Composer\DependencyResolver\ReleaseAgeConfig;
use Composer\DependencyResolver\ReleaseAgePoolFilter;
use Composer\DependencyResolver\Request;
use Composer\Package\Package;
use Composer\Package\RootPackage;
use Composer\Semver\Constraint\Constraint;
use Composer\Semver\Constraint\MultiConstraint;
use Composer\Semver\VersionParser;
use Composer\Test\TestCase;
use DateTimeImmutable;

class ReleaseAgePoolFilterTest extends TestCase
{
    public function testFilterNewPackages(): void
    {
        $config = new ReleaseAgeConfig(7 * 24 * 3600, []); // 7 days
        $now = new DateTimeImmutable('2026-01-15 12:00:00');
        $filter = new ReleaseAgePoolFilter($config, $now);

        $oldPackage = new Package('vendor/pkg', '1.0.0.0', '1.0.0');
        $oldPackage->setReleaseDate(new DateTimeImmutable('2026-01-01 12:00:00'));

        $newPackage = new Package('vendor/pkg', '2.0.0.0', '2.0.0');
        $newPackage->setReleaseDate(new DateTimeImmutable('2026-01-14 12:00:00')); // 1 day old

        $pool = new Pool([$oldPackage, $newPackage]);
        $filteredPool = $filter->filter($pool, [], new Request());

        $this->assertSame([$oldPackage], $filteredPool->getPackages());
        $this->assertTrue($filteredPool->isReleaseAgeRemovedPackageVersion('vendor/pkg', new Constraint('==', '2.0.0.0')));
    }

    public function testExceptedPackagesAreNotFiltered(): void
    {
        $config = new ReleaseAgeConfig(7 * 24 * 3600, [
            ['package' => 'internal/*', 'reason' => 'Internal packages'],
        ]);
        $now = new DateTimeImmutable('2026-01-15 12:00:00');
        $filter = new ReleaseAgePoolFilter($config, $now);

        $newPackage = new Package('internal/pkg', '1.0.0.0', '1.0.0');
        $newPackage->setReleaseDate(new DateTimeImmutable('2026-01-14 12:00:00'));

        $pool = new Pool([$newPackage]);
        $filteredPool = $filter->filter($pool, [], new Request());

        $this->assertSame([$newPackage], $filteredPool->getPackages());
        $this->assertFalse($filteredPool->isReleaseAgeRemovedPackageVersion('internal/pkg', new Constraint('==', '1.0.0.0')));
    }

    public function testDevVersionsAreNotFiltered(): void
    {
        $config = new ReleaseAgeConfig(7 * 24 * 3600, []);
        $now = new DateTimeImmutable('2026-01-15 12:00:00');
        $filter = new ReleaseAgePoolFilter($config, $now);

        $devPackage = new Package('vendor/pkg', 'dev-main', 'dev-main');
        $devPackage->setReleaseDate(new DateTimeImmutable('2026-01-14 12:00:00'));

        $pool = new Pool([$devPackage]);
        $filteredPool = $filter->filter($pool, [], new Request());

        $this->assertSame([$devPackage], $filteredPool->getPackages());
    }

    public function testPackagesWithoutReleaseDateAreNotFiltered(): void
    {
        $config = new ReleaseAgeConfig(7 * 24 * 3600, []);
        $now = new DateTimeImmutable('2026-01-15 12:00:00');
        $filter = new ReleaseAgePoolFilter($config, $now);

        $package = new Package('vendor/pkg', '1.0.0.0', '1.0.0');
        // No release date set

        $pool = new Pool([$package]);
        $filteredPool = $filter->filter($pool, [], new Request());

        $this->assertSame([$package], $filteredPool->getPackages());
    }

    public function testDisabledConfigDoesNotFilter(): void
    {
        $config = new ReleaseAgeConfig(null, []);
        $filter = new ReleaseAgePoolFilter($config);

        $newPackage = new Package('vendor/pkg', '1.0.0.0', '1.0.0');
        $newPackage->setReleaseDate(new DateTimeImmutable('now'));

        $pool = new Pool([$newPackage]);
        $filteredPool = $filter->filter($pool, [], new Request());

        $this->assertSame([$newPackage], $filteredPool->getPackages());
    }

    public function testZeroConfigDoesNotFilter(): void
    {
        $config = new ReleaseAgeConfig(0, []);
        $filter = new ReleaseAgePoolFilter($config);

        $newPackage = new Package('vendor/pkg', '1.0.0.0', '1.0.0');
        $newPackage->setReleaseDate(new DateTimeImmutable('now'));

        $pool = new Pool([$newPackage]);
        $filteredPool = $filter->filter($pool, [], new Request());

        $this->assertSame([$newPackage], $filteredPool->getPackages());
    }

    public function testReleaseAgeInfoIsStored(): void
    {
        $config = new ReleaseAgeConfig(7 * 24 * 3600, []); // 7 days
        $now = new DateTimeImmutable('2026-01-15 12:00:00');
        $filter = new ReleaseAgePoolFilter($config, $now);

        $newPackage = new Package('vendor/pkg', '2.0.0.0', '2.0.0');
        $newPackage->setReleaseDate(new DateTimeImmutable('2026-01-14 12:00:00'));

        $pool = new Pool([$newPackage]);
        $filteredPool = $filter->filter($pool, [], new Request());

        $this->assertEmpty($filteredPool->getPackages());

        $releaseAgeInfo = $filteredPool->getReleaseAgeInfoForPackageVersion('vendor/pkg', new Constraint('==', '2.0.0.0'));
        $this->assertNotNull($releaseAgeInfo);
        $this->assertSame('2.0.0', $releaseAgeInfo['prettyVersion']);
        $this->assertArrayHasKey('releaseDate', $releaseAgeInfo);
        $this->assertArrayHasKey('availableIn', $releaseAgeInfo);
    }

    public function testOldEnoughPackagesAreKept(): void
    {
        $config = new ReleaseAgeConfig(24 * 3600, []); // 1 day
        $now = new DateTimeImmutable('2026-01-15 12:00:00');
        $filter = new ReleaseAgePoolFilter($config, $now);

        $oldEnoughPackage = new Package('vendor/pkg', '1.0.0.0', '1.0.0');
        $oldEnoughPackage->setReleaseDate(new DateTimeImmutable('2026-01-13 12:00:00')); // 2 days old

        $pool = new Pool([$oldEnoughPackage]);
        $filteredPool = $filter->filter($pool, [], new Request());

        $this->assertSame([$oldEnoughPackage], $filteredPool->getPackages());
    }

    public function testExactlyAtCutoffIsFiltered(): void
    {
        $config = new ReleaseAgeConfig(24 * 3600, []); // 1 day
        $now = new DateTimeImmutable('2026-01-15 12:00:00');
        $filter = new ReleaseAgePoolFilter($config, $now);

        // Package released exactly 24 hours ago should still be filtered (>= not >)
        $exactCutoffPackage = new Package('vendor/pkg', '1.0.0.0', '1.0.0');
        $exactCutoffPackage->setReleaseDate(new DateTimeImmutable('2026-01-14 12:00:00'));

        $pool = new Pool([$exactCutoffPackage]);
        $filteredPool = $filter->filter($pool, [], new Request());

        // Package at exact cutoff should be kept (it's exactly old enough)
        $this->assertSame([$exactCutoffPackage], $filteredPool->getPackages());
    }

    public function testSecurityFixBypassesReleaseAgeRequirement(): void
    {
        $config = new ReleaseAgeConfig(7 * 24 * 3600, []); // 7 days
        $now = new DateTimeImmutable('2026-01-15 12:00:00');
        $filter = new ReleaseAgePoolFilter($config, $now);

        // Create a security advisory reported on Jan 10
        $versionParser = new VersionParser();
        $advisory = new SecurityAdvisory(
            'vendor/pkg',
            'ADVISORY-1',
            $versionParser->parseConstraints('<2.0.0'), // affects versions < 2.0.0
            'Security vulnerability in vendor/pkg',
            [['name' => 'packagist', 'remoteId' => 'ADVISORY-1']],
            new DateTimeImmutable('2026-01-10 12:00:00'), // reported Jan 10
            'CVE-2026-0001'
        );

        $filter->setSecurityAdvisories([
            'vendor/pkg' => [$advisory],
        ]);

        // Package 2.0.0 released on Jan 12 (after advisory, not affected)
        // This should bypass release age requirement as it's a security fix
        $securityFixPackage = new Package('vendor/pkg', '2.0.0.0', '2.0.0');
        $securityFixPackage->setReleaseDate(new DateTimeImmutable('2026-01-12 12:00:00')); // 3 days old, would normally be filtered

        $pool = new Pool([$securityFixPackage]);
        $filteredPool = $filter->filter($pool, [], new Request());

        // Should NOT be filtered because it's a security fix
        $this->assertSame([$securityFixPackage], $filteredPool->getPackages());
    }

    public function testVulnerableVersionDoesNotBypassReleaseAgeRequirement(): void
    {
        $config = new ReleaseAgeConfig(7 * 24 * 3600, []); // 7 days
        $now = new DateTimeImmutable('2026-01-15 12:00:00');
        $filter = new ReleaseAgePoolFilter($config, $now);

        $versionParser = new VersionParser();
        $advisory = new SecurityAdvisory(
            'vendor/pkg',
            'ADVISORY-1',
            $versionParser->parseConstraints('<2.0.0'), // affects versions < 2.0.0
            'Security vulnerability in vendor/pkg',
            [['name' => 'packagist', 'remoteId' => 'ADVISORY-1']],
            new DateTimeImmutable('2026-01-10 12:00:00'),
            'CVE-2026-0001'
        );

        $filter->setSecurityAdvisories([
            'vendor/pkg' => [$advisory],
        ]);

        // Package 1.9.0 is still affected by the advisory
        $vulnerablePackage = new Package('vendor/pkg', '1.9.0.0', '1.9.0');
        $vulnerablePackage->setReleaseDate(new DateTimeImmutable('2026-01-12 12:00:00'));

        $pool = new Pool([$vulnerablePackage]);
        $filteredPool = $filter->filter($pool, [], new Request());

        // Should be filtered because it's still vulnerable (not a security fix)
        $this->assertEmpty($filteredPool->getPackages());
    }

    public function testPackageReleasedBeforeAdvisoryDoesNotBypass(): void
    {
        $config = new ReleaseAgeConfig(7 * 24 * 3600, []); // 7 days
        $now = new DateTimeImmutable('2026-01-15 12:00:00');
        $filter = new ReleaseAgePoolFilter($config, $now);

        $versionParser = new VersionParser();
        $advisory = new SecurityAdvisory(
            'vendor/pkg',
            'ADVISORY-1',
            $versionParser->parseConstraints('<2.0.0'),
            'Security vulnerability',
            [['name' => 'packagist', 'remoteId' => 'ADVISORY-1']],
            new DateTimeImmutable('2026-01-12 12:00:00'), // reported Jan 12
            'CVE-2026-0001'
        );

        $filter->setSecurityAdvisories([
            'vendor/pkg' => [$advisory],
        ]);

        // Package released BEFORE the advisory was reported (Jan 10)
        // Not a security fix - it existed before the vulnerability was known
        $preAdvisoryPackage = new Package('vendor/pkg', '2.0.0.0', '2.0.0');
        $preAdvisoryPackage->setReleaseDate(new DateTimeImmutable('2026-01-10 12:00:00'));

        $pool = new Pool([$preAdvisoryPackage]);
        $filteredPool = $filter->filter($pool, [], new Request());

        // Should be filtered because it was released before the advisory
        $this->assertEmpty($filteredPool->getPackages());
    }

    public function testPackageReleasedAfterBypassWindowDoesNotBypass(): void
    {
        $config = new ReleaseAgeConfig(7 * 24 * 3600, []); // 7 days minimum age, 14 days bypass window
        $now = new DateTimeImmutable('2026-02-15 12:00:00');
        $filter = new ReleaseAgePoolFilter($config, $now);

        $versionParser = new VersionParser();
        $advisory = new SecurityAdvisory(
            'vendor/pkg',
            'ADVISORY-1',
            $versionParser->parseConstraints('<2.0.0'),
            'Security vulnerability',
            [['name' => 'packagist', 'remoteId' => 'ADVISORY-1']],
            new DateTimeImmutable('2026-01-01 12:00:00'), // reported Jan 1
            'CVE-2026-0001'
        );

        $filter->setSecurityAdvisories([
            'vendor/pkg' => [$advisory],
        ]);

        // Package released on Feb 10 - way after the bypass window (14 days from Jan 1 = Jan 15)
        $latePackage = new Package('vendor/pkg', '2.1.0.0', '2.1.0');
        $latePackage->setReleaseDate(new DateTimeImmutable('2026-02-10 12:00:00'));

        $pool = new Pool([$latePackage]);
        $filteredPool = $filter->filter($pool, [], new Request());

        // Should be filtered because it was released after the bypass window
        $this->assertEmpty($filteredPool->getPackages());
    }

    public function testSecurityFixWithinBypassWindow(): void
    {
        $config = new ReleaseAgeConfig(7 * 24 * 3600, []); // 7 days minimum age, 14 days bypass window
        $now = new DateTimeImmutable('2026-01-20 12:00:00');
        $filter = new ReleaseAgePoolFilter($config, $now);

        $versionParser = new VersionParser();
        $advisory = new SecurityAdvisory(
            'vendor/pkg',
            'ADVISORY-1',
            $versionParser->parseConstraints('<2.0.0'),
            'Security vulnerability',
            [['name' => 'packagist', 'remoteId' => 'ADVISORY-1']],
            new DateTimeImmutable('2026-01-05 12:00:00'), // reported Jan 5
            'CVE-2026-0001'
        );

        $filter->setSecurityAdvisories([
            'vendor/pkg' => [$advisory],
        ]);

        // Package released on Jan 18 - within bypass window (14 days from Jan 5 = Jan 19)
        $securityFixPackage = new Package('vendor/pkg', '2.0.0.0', '2.0.0');
        $securityFixPackage->setReleaseDate(new DateTimeImmutable('2026-01-18 12:00:00')); // 2 days old

        $pool = new Pool([$securityFixPackage]);
        $filteredPool = $filter->filter($pool, [], new Request());

        // Should NOT be filtered because it's within the bypass window
        $this->assertSame([$securityFixPackage], $filteredPool->getPackages());
    }

    public function testPartialSecurityAdvisoryRecentVersionBypassesReleaseAge(): void
    {
        $config = new ReleaseAgeConfig(7 * 24 * 3600, []); // 7 days minimum age, 14 days bypass window
        $now = new DateTimeImmutable('2026-01-15 12:00:00');
        $filter = new ReleaseAgePoolFilter($config, $now);

        $versionParser = new VersionParser();
        // PartialSecurityAdvisory has no reportedAt date
        $advisory = new PartialSecurityAdvisory(
            'vendor/pkg',
            'ADVISORY-1',
            $versionParser->parseConstraints('<2.0.0') // affects versions < 2.0.0
        );

        $filter->setSecurityAdvisories([
            'vendor/pkg' => [$advisory],
        ]);

        // Package 2.0.0 released 2 days ago - within 2x bypass window from now (14 days)
        // Not affected by advisory, should bypass release age requirement
        $securityFixPackage = new Package('vendor/pkg', '2.0.0.0', '2.0.0');
        $securityFixPackage->setReleaseDate(new DateTimeImmutable('2026-01-13 12:00:00'));

        $pool = new Pool([$securityFixPackage]);
        $filteredPool = $filter->filter($pool, [], new Request());

        // Should NOT be filtered because it's a recent security fix
        $this->assertSame([$securityFixPackage], $filteredPool->getPackages());
    }

    public function testPartialSecurityAdvisoryOldVersionDoesNotBypass(): void
    {
        $config = new ReleaseAgeConfig(7 * 24 * 3600, []); // 7 days minimum age, 14 days bypass window
        $now = new DateTimeImmutable('2026-02-15 12:00:00');
        $filter = new ReleaseAgePoolFilter($config, $now);

        $versionParser = new VersionParser();
        // PartialSecurityAdvisory has no reportedAt date
        $advisory = new PartialSecurityAdvisory(
            'vendor/pkg',
            'ADVISORY-1',
            $versionParser->parseConstraints('<2.0.0')
        );

        $filter->setSecurityAdvisories([
            'vendor/pkg' => [$advisory],
        ]);

        // Package 2.0.0 released on Jan 1 - more than 14 days ago (outside bypass window from now)
        // Even though not affected by advisory, it's too old to get the bypass
        $oldPackage = new Package('vendor/pkg', '2.0.0.0', '2.0.0');
        $oldPackage->setReleaseDate(new DateTimeImmutable('2026-01-01 12:00:00'));

        $pool = new Pool([$oldPackage]);
        $filteredPool = $filter->filter($pool, [], new Request());

        // Package is old enough to pass the normal release age check (44 days old > 7 days)
        // So it should be kept regardless of security bypass
        $this->assertSame([$oldPackage], $filteredPool->getPackages());
    }

    public function testPartialSecurityAdvisoryVulnerableVersionDoesNotBypass(): void
    {
        $config = new ReleaseAgeConfig(7 * 24 * 3600, []); // 7 days
        $now = new DateTimeImmutable('2026-01-15 12:00:00');
        $filter = new ReleaseAgePoolFilter($config, $now);

        $versionParser = new VersionParser();
        $advisory = new PartialSecurityAdvisory(
            'vendor/pkg',
            'ADVISORY-1',
            $versionParser->parseConstraints('<2.0.0')
        );

        $filter->setSecurityAdvisories([
            'vendor/pkg' => [$advisory],
        ]);

        // Package 1.9.0 is still affected by the advisory
        $vulnerablePackage = new Package('vendor/pkg', '1.9.0.0', '1.9.0');
        $vulnerablePackage->setReleaseDate(new DateTimeImmutable('2026-01-13 12:00:00'));

        $pool = new Pool([$vulnerablePackage]);
        $filteredPool = $filter->filter($pool, [], new Request());

        // Should be filtered because it's still vulnerable
        $this->assertEmpty($filteredPool->getPackages());
    }

    public function testDeadlockScenarioWithPartialSecurityAdvisory(): void
    {
        // This test verifies the fix for the deadlock scenario:
        // - Security advisory blocks all old versions (< 2.0.0)
        // - Minimum release age would block all new versions (>= 2.0.0)
        // - With the fix, version 2.0.0 (security fix) should be allowed

        $config = new ReleaseAgeConfig(7 * 24 * 3600, []); // 7 days
        $now = new DateTimeImmutable('2026-01-15 12:00:00');
        $filter = new ReleaseAgePoolFilter($config, $now);

        $versionParser = new VersionParser();
        // PartialSecurityAdvisory (no reportedAt) - simulates real-world scenario
        $advisory = new PartialSecurityAdvisory(
            'vendor/pkg',
            'ADVISORY-1',
            $versionParser->parseConstraints('<2.0.0') // affects versions < 2.0.0
        );

        $filter->setSecurityAdvisories([
            'vendor/pkg' => [$advisory],
        ]);

        // Version 2.0.0 - the security fix, released 2 days ago (too new for normal release age)
        $securityFixPackage = new Package('vendor/pkg', '2.0.0.0', '2.0.0');
        $securityFixPackage->setReleaseDate(new DateTimeImmutable('2026-01-13 12:00:00'));

        $pool = new Pool([$securityFixPackage]);
        $filteredPool = $filter->filter($pool, [], new Request());

        // Should NOT be filtered - this is the security fix that resolves the deadlock
        $this->assertSame([$securityFixPackage], $filteredPool->getPackages());
        $this->assertFalse($filteredPool->isReleaseAgeRemovedPackageVersion('vendor/pkg', new Constraint('==', '2.0.0.0')));
    }

    public function testOnlyOldestSecurityFixBypassesReleaseAge(): void
    {
        $config = new ReleaseAgeConfig(7 * 24 * 3600, []); // 7 days
        $now = new DateTimeImmutable('2026-01-15 12:00:00');
        $filter = new ReleaseAgePoolFilter($config, $now);

        $versionParser = new VersionParser();
        $advisory = new PartialSecurityAdvisory(
            'vendor/pkg',
            'ADVISORY-1',
            $versionParser->parseConstraints('<2.0.0')
        );

        $filter->setSecurityAdvisories([
            'vendor/pkg' => [$advisory],
        ]);

        // Multiple security fix versions - all newer than minimum release age
        $fix1 = new Package('vendor/pkg', '2.0.0.0', '2.0.0');
        $fix1->setReleaseDate(new DateTimeImmutable('2026-01-10 12:00:00')); // Oldest fix

        $fix2 = new Package('vendor/pkg', '2.0.1.0', '2.0.1');
        $fix2->setReleaseDate(new DateTimeImmutable('2026-01-12 12:00:00'));

        $fix3 = new Package('vendor/pkg', '2.1.0.0', '2.1.0');
        $fix3->setReleaseDate(new DateTimeImmutable('2026-01-14 12:00:00')); // Newest fix

        $pool = new Pool([$fix1, $fix2, $fix3]);
        $filteredPool = $filter->filter($pool, [], new Request());

        // Only the oldest security fix should bypass
        $this->assertCount(1, $filteredPool->getPackages());
        $this->assertSame($fix1, $filteredPool->getPackages()[0]);
    }

    public function testOldestSecurityFixPerDisjunctiveConstraintPartBypassesReleaseAge(): void
    {
        $config = new ReleaseAgeConfig(7 * 24 * 3600, []); // 7 days
        $now = new DateTimeImmutable('2026-01-15 12:00:00');
        $filter = new ReleaseAgePoolFilter($config, $now);

        $versionParser = new VersionParser();

        // Two advisories affecting different major version ranges
        $advisory3x = new PartialSecurityAdvisory(
            'vendor/pkg',
            'ADVISORY-3X',
            $versionParser->parseConstraints('>=3.0.0 <3.6.0')
        );
        $advisory4x = new PartialSecurityAdvisory(
            'vendor/pkg',
            'ADVISORY-4X',
            $versionParser->parseConstraints('>=4.0.0 <4.2.0')
        );

        $filter->setSecurityAdvisories([
            'vendor/pkg' => [$advisory3x, $advisory4x],
        ]);

        // Security fixes for 3.x branch
        $fix3a = new Package('vendor/pkg', '3.6.0.0', '3.6.0');
        $fix3a->setReleaseDate(new DateTimeImmutable('2026-01-10 12:00:00')); // Oldest 3.x fix

        $fix3b = new Package('vendor/pkg', '3.6.1.0', '3.6.1');
        $fix3b->setReleaseDate(new DateTimeImmutable('2026-01-12 12:00:00'));

        // Security fixes for 4.x branch
        $fix4a = new Package('vendor/pkg', '4.2.0.0', '4.2.0');
        $fix4a->setReleaseDate(new DateTimeImmutable('2026-01-11 12:00:00')); // Oldest 4.x fix

        $fix4b = new Package('vendor/pkg', '4.2.1.0', '4.2.1');
        $fix4b->setReleaseDate(new DateTimeImmutable('2026-01-13 12:00:00'));

        $pool = new Pool([$fix3a, $fix3b, $fix4a, $fix4b]);

        // Create a disjunctive constraint: ^3.0 || ^4.0
        $constraint = new MultiConstraint([
            $versionParser->parseConstraints('^3.0'),
            $versionParser->parseConstraints('^4.0'),
        ], false); // false = disjunctive (OR)

        $request = new Request();
        $request->requireName('vendor/pkg', $constraint);

        $filteredPool = $filter->filter($pool, [], $request);

        // Should have oldest fix from each constraint part: 3.6.0 and 4.2.0
        $this->assertCount(2, $filteredPool->getPackages());
        $packageVersions = array_map(function ($p) { return $p->getPrettyVersion(); }, $filteredPool->getPackages());
        $this->assertContains('3.6.0', $packageVersions);
        $this->assertContains('4.2.0', $packageVersions);
    }

    public function testMultipleMinorVersionBranchesWithDisjunctiveConstraint(): void
    {
        $config = new ReleaseAgeConfig(7 * 24 * 3600, []); // 7 days
        $now = new DateTimeImmutable('2026-01-15 12:00:00');
        $filter = new ReleaseAgePoolFilter($config, $now);

        $versionParser = new VersionParser();

        // Advisories affecting different minor version ranges
        $advisory35 = new PartialSecurityAdvisory(
            'vendor/pkg',
            'ADVISORY-35',
            $versionParser->parseConstraints('>=3.5.0 <3.5.18')
        );
        $advisory36 = new PartialSecurityAdvisory(
            'vendor/pkg',
            'ADVISORY-36',
            $versionParser->parseConstraints('>=3.6.0 <3.6.3')
        );

        $filter->setSecurityAdvisories([
            'vendor/pkg' => [$advisory35, $advisory36],
        ]);

        // Security fixes for 3.5.x branch
        $fix35 = new Package('vendor/pkg', '3.5.18.0', '3.5.18');
        $fix35->setReleaseDate(new DateTimeImmutable('2026-01-10 12:00:00'));

        // Security fixes for 3.6.x branch
        $fix36 = new Package('vendor/pkg', '3.6.3.0', '3.6.3');
        $fix36->setReleaseDate(new DateTimeImmutable('2026-01-11 12:00:00'));

        $pool = new Pool([$fix35, $fix36]);

        // Create a disjunctive constraint: ^3.5.0 || ^3.6.0
        $constraint = new MultiConstraint([
            $versionParser->parseConstraints('^3.5.0'),
            $versionParser->parseConstraints('^3.6.0'),
        ], false);

        $request = new Request();
        $request->requireName('vendor/pkg', $constraint);

        $filteredPool = $filter->filter($pool, [], $request);

        // Should have oldest fix from each constraint part: 3.5.18 and 3.6.3
        $this->assertCount(2, $filteredPool->getPackages());
        $packageVersions = array_map(function ($p) { return $p->getPrettyVersion(); }, $filteredPool->getPackages());
        $this->assertContains('3.5.18', $packageVersions);
        $this->assertContains('3.6.3', $packageVersions);
    }

    public function testSecurityFixBypassWithNoConstraintInRequest(): void
    {
        $config = new ReleaseAgeConfig(7 * 24 * 3600, []); // 7 days
        $now = new DateTimeImmutable('2026-01-15 12:00:00');
        $filter = new ReleaseAgePoolFilter($config, $now);

        $versionParser = new VersionParser();
        $advisory = new PartialSecurityAdvisory(
            'vendor/pkg',
            'ADVISORY-1',
            $versionParser->parseConstraints('<2.0.0')
        );

        $filter->setSecurityAdvisories([
            'vendor/pkg' => [$advisory],
        ]);

        // Multiple security fix versions
        $fix1 = new Package('vendor/pkg', '2.0.0.0', '2.0.0');
        $fix1->setReleaseDate(new DateTimeImmutable('2026-01-10 12:00:00')); // Oldest

        $fix2 = new Package('vendor/pkg', '2.1.0.0', '2.1.0');
        $fix2->setReleaseDate(new DateTimeImmutable('2026-01-12 12:00:00'));

        $fix3 = new Package('vendor/pkg', '3.0.0.0', '3.0.0');
        $fix3->setReleaseDate(new DateTimeImmutable('2026-01-14 12:00:00'));

        $pool = new Pool([$fix1, $fix2, $fix3]);

        // No constraint in request - all fixes treated as one group
        $request = new Request();

        $filteredPool = $filter->filter($pool, [], $request);

        // Only the oldest should bypass (all treated as one group with MatchAllConstraint)
        $this->assertCount(1, $filteredPool->getPackages());
        $this->assertSame($fix1, $filteredPool->getPackages()[0]);
    }

    public function testRootPackageNotFiltered(): void
    {
        $config = new ReleaseAgeConfig(7 * 24 * 3600, []); // 7 days
        $now = new DateTimeImmutable('2026-01-15 12:00:00');
        $filter = new ReleaseAgePoolFilter($config, $now);

        // Create a root package that would normally be filtered (too new)
        $rootPackage = new RootPackage('my/project', '1.0.0.0', '1.0.0');
        $rootPackage->setReleaseDate(new DateTimeImmutable('2026-01-14 12:00:00')); // 1 day old

        $pool = new Pool([$rootPackage]);
        $filteredPool = $filter->filter($pool, [], new Request());

        // Root packages should never be filtered
        $this->assertSame([$rootPackage], $filteredPool->getPackages());
    }

    public function testPlatformPackagesNotFiltered(): void
    {
        $config = new ReleaseAgeConfig(7 * 24 * 3600, []); // 7 days
        $now = new DateTimeImmutable('2026-01-15 12:00:00');
        $filter = new ReleaseAgePoolFilter($config, $now);

        // Create platform packages that would normally be filtered
        $phpPackage = new Package('php', '8.3.0.0', '8.3.0');
        $phpPackage->setReleaseDate(new DateTimeImmutable('2026-01-14 12:00:00'));

        $extPackage = new Package('ext-json', '8.3.0.0', '8.3.0');
        $extPackage->setReleaseDate(new DateTimeImmutable('2026-01-14 12:00:00'));

        $libPackage = new Package('lib-openssl', '3.0.0.0', '3.0.0');
        $libPackage->setReleaseDate(new DateTimeImmutable('2026-01-14 12:00:00'));

        $pool = new Pool([$phpPackage, $extPackage, $libPackage]);
        $filteredPool = $filter->filter($pool, [], new Request());

        // Platform packages should never be filtered
        $this->assertCount(3, $filteredPool->getPackages());
        $this->assertContains($phpPackage, $filteredPool->getPackages());
        $this->assertContains($extPackage, $filteredPool->getPackages());
        $this->assertContains($libPackage, $filteredPool->getPackages());
    }

    public function testLockedPackagesNotFiltered(): void
    {
        $config = new ReleaseAgeConfig(7 * 24 * 3600, []); // 7 days
        $now = new DateTimeImmutable('2026-01-15 12:00:00');
        $filter = new ReleaseAgePoolFilter($config, $now);

        // Create a package that would normally be filtered
        $lockedPackage = new Package('vendor/pkg', '1.0.0.0', '1.0.0');
        $lockedPackage->setReleaseDate(new DateTimeImmutable('2026-01-14 12:00:00'));

        // Create request with the package as locked
        $request = new Request();
        $request->lockPackage($lockedPackage);

        $pool = new Pool([$lockedPackage]);
        $filteredPool = $filter->filter($pool, [], $request);

        // Locked packages should never be filtered
        $this->assertSame([$lockedPackage], $filteredPool->getPackages());
    }

    public function testPackageWithMultipleNamesTrackedCorrectly(): void
    {
        $config = new ReleaseAgeConfig(7 * 24 * 3600, []); // 7 days
        $now = new DateTimeImmutable('2026-01-15 12:00:00');
        $filter = new ReleaseAgePoolFilter($config, $now);

        // Create a package with provides (will have multiple names)
        $newPackage = new Package('vendor/pkg', '2.0.0.0', '2.0.0');
        $newPackage->setReleaseDate(new DateTimeImmutable('2026-01-14 12:00:00'));
        $newPackage->setProvides([
            'vendor/pkg-alias' => new \Composer\Package\Link(
                'vendor/pkg',
                'vendor/pkg-alias',
                new Constraint('==', '2.0.0.0'),
                \Composer\Package\Link::TYPE_PROVIDE,
                '2.0.0'
            ),
        ]);

        $pool = new Pool([$newPackage]);
        $filteredPool = $filter->filter($pool, [], new Request());

        // Package should be filtered
        $this->assertEmpty($filteredPool->getPackages());

        // Both the main name and the provided name should be tracked
        $this->assertTrue($filteredPool->isReleaseAgeRemovedPackageVersion('vendor/pkg', new Constraint('==', '2.0.0.0')));
        // Note: getNames(false) returns the package's own names, not provides
        // The provides are tracked separately, so we only check the main name here
    }
}
