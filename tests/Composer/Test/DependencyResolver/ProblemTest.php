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
use Composer\DependencyResolver\Problem;
use Composer\DependencyResolver\Request;
use Composer\Repository\ArrayRepository;
use Composer\Repository\RepositorySet;
use Composer\Semver\Constraint\Constraint;
use Composer\Semver\Constraint\MultiConstraint;
use Composer\Test\TestCase;

class ProblemTest extends TestCase
{
    public function testGetMissingPackageReasonWithCombinedSecurityAndReleaseAge(): void
    {
        // Create packages that will be in the RepositorySet but not in the Pool
        $securityPackage = self::getPackage('vendor/pkg', '1.0.0');
        $releaseAgePackage = self::getPackage('vendor/pkg', '2.0.0');

        // Create a SecurityAdvisory with an old reportedAt date so bypass window has expired
        // This makes the scenario realistic: 2.0.0 is NOT a security fix (outside bypass window)
        // but still too new for the release-age cutoff
        $securityAdvisory = new SecurityAdvisory(
            'vendor/pkg',
            'PKSA-test-1234',
            new Constraint('<', '2.0.0.0'),
            'Test security advisory',
            [['name' => 'test-source', 'remoteId' => 'TEST-001']],
            new \DateTimeImmutable('2025-12-01T00:00:00+00:00')
        );

        // Create Pool with both security and release-age removed versions tracked
        $pool = new Pool(
            [],  // packages - empty since versions are filtered
            [],  // unacceptableFixedOrLockedPackages
            [    // removedVersions
                'vendor/pkg' => [
                    '1.0.0.0' => '1.0.0',
                    '2.0.0.0' => '2.0.0',
                ],
            ],
            [],  // removedVersionsByPackage
            [    // securityRemovedVersions
                'vendor/pkg' => [
                    '1.0.0.0' => [$securityAdvisory],
                ],
            ],
            [],  // abandonedRemovedVersions
            [    // releaseAgeRemovedVersions
                'vendor/pkg' => [
                    '2.0.0.0' => [
                        'prettyVersion' => '2.0.0',
                        'releaseDate' => '2026-01-10T12:00:00+00:00',
                        'availableIn' => '5 days',
                    ],
                ],
            ]
        );

        // Create RepositorySet with the packages (simulates what was available before filtering)
        $repositorySet = new RepositorySet();
        $repositorySet->addRepository(new ArrayRepository([$securityPackage, $releaseAgePackage]));

        // Create a constraint that matches both versions
        $constraint = new MultiConstraint([
            new Constraint('>=', '1.0.0.0'),
            new Constraint('<', '3.0.0.0'),
        ], true);

        $result = Problem::getMissingPackageReason(
            $repositorySet,
            new Request(),
            $pool,
            false,
            'vendor/pkg',
            $constraint
        );

        $message = implode('', $result);

        $this->assertStringContainsString('vendor/pkg[1.0.0]', $message, 'Security-affected version 1.0.0 should be listed');
        $this->assertStringContainsString('affected by security advisories', $message, 'Security message should be present');
        $this->assertStringContainsString('PKSA-test-1234', $message, 'Advisory ID should be present');
        $this->assertStringContainsString('Additionally', $message, 'Combined message should use "Additionally"');
        $this->assertStringContainsString('vendor/pkg[2.0.0]', $message, 'Release-age-affected version 2.0.0 should be listed');
        $this->assertStringContainsString('minimum-release-age requirement', $message, 'Release age message should be present');
        $this->assertStringContainsString('5 days', $message, 'Available in time should be present');
    }

    public function testGetMissingPackageReasonWithSecurityOnly(): void
    {
        $securityPackage = self::getPackage('vendor/pkg', '1.0.0');

        $securityAdvisory = new PartialSecurityAdvisory(
            'vendor/pkg',
            'PKSA-security-only',
            new Constraint('<', '2.0.0.0')
        );

        $pool = new Pool(
            [],
            [],
            ['vendor/pkg' => ['1.0.0.0' => '1.0.0']],
            [],
            ['vendor/pkg' => ['1.0.0.0' => [$securityAdvisory]]],
            [],
            []
        );

        $repositorySet = new RepositorySet();
        $repositorySet->addRepository(new ArrayRepository([$securityPackage]));

        $constraint = new Constraint('>=', '1.0.0.0');

        $result = Problem::getMissingPackageReason(
            $repositorySet,
            new Request(),
            $pool,
            false,
            'vendor/pkg',
            $constraint
        );

        $message = implode('', $result);

        $this->assertStringContainsString('affected by security advisories', $message);
        $this->assertStringContainsString('PKSA-security-only', $message);
        $this->assertStringNotContainsString('Additionally', $message, 'Should not use combined format for security-only');
        $this->assertStringNotContainsString('minimum-release-age', $message, 'Should not mention release age');
    }

    public function testGetMissingPackageReasonWithReleaseAgeOnly(): void
    {
        $releaseAgePackage = self::getPackage('vendor/pkg', '2.0.0');

        $pool = new Pool(
            [],
            [],
            ['vendor/pkg' => ['2.0.0.0' => '2.0.0']],
            [],
            [],
            [],
            [
                'vendor/pkg' => [
                    '2.0.0.0' => [
                        'prettyVersion' => '2.0.0',
                        'releaseDate' => '2026-01-10T12:00:00+00:00',
                        'availableIn' => '3 days',
                    ],
                ],
            ]
        );

        $repositorySet = new RepositorySet();
        $repositorySet->addRepository(new ArrayRepository([$releaseAgePackage]));

        $constraint = new Constraint('>=', '2.0.0.0');

        $result = Problem::getMissingPackageReason(
            $repositorySet,
            new Request(),
            $pool,
            false,
            'vendor/pkg',
            $constraint
        );

        $message = implode('', $result);

        $this->assertStringContainsString('minimum-release-age requirement', $message);
        $this->assertStringContainsString('3 days', $message);
        $this->assertStringNotContainsString('Additionally', $message, 'Should not use combined format for release-age-only');
        $this->assertStringNotContainsString('security advisories', $message, 'Should not mention security advisories');
    }

    public function testGetMissingPackageReasonShowsEarliestAvailableVersion(): void
    {
        // Create packages - older version should show in the "available in" message
        $olderPackage = self::getPackage('vendor/pkg', '1.0.0');
        $newerPackage = self::getPackage('vendor/pkg', '2.0.0');

        $pool = new Pool(
            [],
            [],
            ['vendor/pkg' => ['1.0.0.0' => '1.0.0', '2.0.0.0' => '2.0.0']],
            [],
            [],
            [],
            [
                'vendor/pkg' => [
                    // Intentionally put newer version first to test that we find the earliest
                    '2.0.0.0' => [
                        'prettyVersion' => '2.0.0',
                        'releaseDate' => '2026-01-14T12:00:00+00:00',  // Newer - released later
                        'availableIn' => '10 days',
                    ],
                    '1.0.0.0' => [
                        'prettyVersion' => '1.0.0',
                        'releaseDate' => '2026-01-10T12:00:00+00:00',  // Older - will be available soonest
                        'availableIn' => '3 days',
                    ],
                ],
            ]
        );

        $repositorySet = new RepositorySet();
        $repositorySet->addRepository(new ArrayRepository([$olderPackage, $newerPackage]));

        // Constraint that matches both versions
        $constraint = new MultiConstraint([
            new Constraint('>=', '1.0.0.0'),
            new Constraint('<', '3.0.0.0'),
        ], true);

        $result = Problem::getMissingPackageReason(
            $repositorySet,
            new Request(),
            $pool,
            false,
            'vendor/pkg',
            $constraint
        );

        $message = implode('', $result);

        // Should show "3 days" from the older version (soonest available), not "10 days" from the newer version
        $this->assertStringContainsString('3 days', $message, 'Should show availability time from the oldest (soonest available) version');
        $this->assertStringNotContainsString('10 days', $message, 'Should not show availability time from the newer version');
    }
}
