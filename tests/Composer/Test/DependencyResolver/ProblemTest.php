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
use Composer\DependencyResolver\GenericRule;
use Composer\DependencyResolver\Pool;
use Composer\DependencyResolver\Problem;
use Composer\DependencyResolver\Request;
use Composer\DependencyResolver\Rule;
use Composer\FilterList\FilterListEntry;
use Composer\Repository\ArrayRepository;
use Composer\Repository\RepositorySet;
use Composer\Semver\Constraint\Constraint;
use Composer\Semver\Constraint\MatchAllConstraint;
use Composer\Semver\Constraint\MultiConstraint;
use Composer\Test\TestCase;

class ProblemTest extends TestCase
{
    public function testGetMissingLockedPackageReasonForFilterListRemovedPackage(): void
    {
        $package = self::getPackage('vendor/malware', '1.0.0');
        $entry = new FilterListEntry(
            'vendor/malware',
            new MatchAllConstraint(),
            'malware',
            'https://example.org/malware/vendor-malware',
            'looks suspicious',
            'PKG-1'
        );
        $pool = new Pool([], [], [], [], [], [], [
            'vendor/malware' => ['1.0.0.0' => [$entry]],
        ]);

        [$prefix, $suffix] = Problem::getMissingLockedPackageReason($pool, $package);

        self::assertSame('- Package vendor/malware 1.0.0 (in the lock file) ', $prefix);

        self::assertStringContainsString('flagged as malware', $suffix);
        self::assertStringContainsString('https://example.org/malware/vendor-malware', $suffix);
        self::assertStringContainsString('reason: looks suspicious', $suffix);
        self::assertStringContainsString('"policy.malware.ignore"', $suffix);
        self::assertStringContainsString('"policy.malware.block"', $suffix);
    }

    public function testGetMissingPackageReasonForCooldownRemovedPackage(): void
    {
        $package = self::getPackage('vendor/pkg', '2.0.0');

        $pool = new Pool(
            [],  // packages - empty since the version was withheld
            [],  // unacceptableFixedOrLockedPackages
            ['vendor/pkg' => ['2.0.0.0' => '2.0.0']],  // removedVersions
            [],  // removedVersionsByPackage
            [],  // securityRemovedVersions
            [],  // abandonedRemovedVersions
            [],  // filterListRemovedVersions
            [    // cooldownRemovedVersions
                'vendor/pkg' => [
                    '2.0.0.0' => [
                        'name' => 'vendor/pkg',
                        'prettyVersion' => '2.0.0',
                        'releaseDate' => '2026-01-10T12:00:00+00:00',
                        'availableIn' => '5 days',
                        'source' => 'published-time',
                    ],
                ],
            ]
        );

        $repositorySet = new RepositorySet();
        $repositorySet->addRepository(new ArrayRepository([$package]));

        $constraint = new MultiConstraint([
            new Constraint('>=', '1.0.0.0'),
            new Constraint('<', '3.0.0.0'),
        ], true);

        $message = implode('', Problem::getMissingPackageReason(
            $repositorySet,
            new Request(),
            $pool,
            false,
            'vendor/pkg',
            $constraint
        ));

        self::assertStringContainsString('vendor/pkg[2.0.0]', $message);
        self::assertStringContainsString('still in the cooldown period', $message);
        self::assertStringContainsString('"policy.cooldown"', $message);
        self::assertStringContainsString('available in 5 days', $message);
        self::assertStringContainsString('"policy.cooldown.ignore"', $message);
        self::assertStringContainsString('"policy.cooldown.block"', $message);
        // authoritative publication timestamp -> no fallback caveat
        self::assertStringNotContainsString('package-supplied time field', $message);
    }

    public function testGetMissingPackageReasonForCooldownRemovedPackageWithTimeFallback(): void
    {
        $package = self::getPackage('vendor/pkg', '2.0.0');

        $pool = new Pool(
            [],
            [],
            ['vendor/pkg' => ['2.0.0.0' => '2.0.0']],
            [],
            [],
            [],
            [],
            [
                'vendor/pkg' => [
                    '2.0.0.0' => [
                        'name' => 'vendor/pkg',
                        'prettyVersion' => '2.0.0',
                        'releaseDate' => '2026-01-10T12:00:00+00:00',
                        'availableIn' => '5 days',
                        'source' => 'time',
                    ],
                ],
            ]
        );

        $repositorySet = new RepositorySet();
        $repositorySet->addRepository(new ArrayRepository([$package]));

        $message = implode('', Problem::getMissingPackageReason(
            $repositorySet,
            new Request(),
            $pool,
            false,
            'vendor/pkg',
            new MultiConstraint([new Constraint('>=', '1.0.0.0'), new Constraint('<', '3.0.0.0')], true)
        ));

        self::assertStringContainsString('still in the cooldown period', $message);
        // fell back to the author-controlled `time` field -> caveat is shown
        self::assertStringContainsString('package-supplied time field', $message);
    }

    public function testGetMissingPackageReasonPointsAtCooldownWithheldFixNextToAdvisories(): void
    {
        $vulnerable = self::getPackage('vendor/pkg', '1.0.0');
        $fix = self::getPackage('vendor/pkg', '1.0.1');
        $advisory = new PartialSecurityAdvisory('vendor/pkg', 'PKSA-1234-abcd-1234', new Constraint('<', '1.0.1.0'));

        $pool = new Pool(
            [],
            [],
            ['vendor/pkg' => ['1.0.0.0' => '1.0.0', '1.0.1.0' => '1.0.1']],
            [],
            ['vendor/pkg' => ['1.0.0.0' => [$advisory]]],
            [],
            [],
            [
                'vendor/pkg' => [
                    '1.0.1.0' => [
                        'name' => 'vendor/pkg',
                        'prettyVersion' => '1.0.1',
                        'releaseDate' => '2026-01-10T12:00:00+00:00',
                        'availableIn' => '5 days',
                        'source' => 'published-time',
                    ],
                ],
            ]
        );

        $repositorySet = new RepositorySet();
        $repositorySet->addRepository(new ArrayRepository([$vulnerable, $fix]));

        $constraint = new MultiConstraint([
            new Constraint('>=', '1.0.0.0'),
            new Constraint('<', '2.0.0.0'),
        ], true);

        $message = implode('', Problem::getMissingPackageReason(
            $repositorySet,
            new Request(),
            $pool,
            false,
            'vendor/pkg',
            $constraint
        ));

        self::assertStringContainsString('affected by security advisories', $message);
        self::assertStringContainsString('PKSA-1234-abcd-1234', $message);
        self::assertStringContainsString('Version 1.0.1 matching the constraint is still in the cooldown period configured in "policy.cooldown" (available in 5 days).', $message);
        self::assertStringContainsString('"policy.cooldown.ignore"', $message);
        self::assertStringContainsString('COMPOSER_POLICY_COOLDOWN_PERIOD=0', $message);
    }
}
