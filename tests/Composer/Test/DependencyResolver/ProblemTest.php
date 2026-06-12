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
                        'prettyVersion' => '2.0.0',
                        'releaseDate' => '2026-01-10T12:00:00+00:00',
                        'availableIn' => '5 days',
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
        self::assertStringContainsString('cleared the cooldown', $message);
        self::assertStringContainsString('"policy.cooldown"', $message);
        self::assertStringContainsString('available in 5 days', $message);
        self::assertStringContainsString('"policy.cooldown.ignore"', $message);
        self::assertStringContainsString('"policy.cooldown.block"', $message);
    }
}
