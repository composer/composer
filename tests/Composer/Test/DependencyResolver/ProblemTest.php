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
use Composer\DependencyResolver\PolicyRemovalReason;
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
            'PKG-1',
            'aikido'
        );
        $pool = new Pool([], [], [], [], [
            'vendor/malware' => ['1.0.0.0' => PolicyRemovalReason::filterList('vendor/malware', '1.0.0', [$entry])],
        ]);

        [$prefix, $suffix] = Problem::getMissingLockedPackageReason($pool, $package);

        self::assertSame('- Package vendor/malware 1.0.0 (in the lock file) ', $prefix);

        self::assertStringContainsString('flagged as malware reported by aikido', $suffix);
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
            [    // policyRemovedVersions
                'vendor/pkg' => [
                    '2.0.0.0' => PolicyRemovalReason::cooldown('vendor/pkg', '2.0.0', '2026-01-10T12:00:00+00:00', '5 days', 'published-time'),
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
            [
                'vendor/pkg' => [
                    '2.0.0.0' => PolicyRemovalReason::cooldown('vendor/pkg', '2.0.0', '2026-01-10T12:00:00+00:00', '5 days', 'time'),
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
            [],
            [],
            [
                'vendor/pkg' => [
                    '1.0.0.0' => PolicyRemovalReason::advisories('vendor/pkg', '1.0.0', [$advisory]),
                    '1.0.1.0' => PolicyRemovalReason::cooldown('vendor/pkg', '1.0.1', '2026-01-10T12:00:00+00:00', '5 days', 'published-time'),
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

        // each version gets its own line with the policy that removed it, and the remedies follow once
        self::assertStringContainsString("found vendor/pkg[1.0.0, 1.0.1] but these were not loaded, because:\n      - 1.0.0: affected by security advisories (\"", $message);
        self::assertStringContainsString('PKSA-1234-abcd-1234', $message);
        self::assertStringContainsString("\n      - 1.0.1: still in the cooldown period configured in \"policy.cooldown\" (available in 5 days)\n      Go to https://packagist.org/security-advisories/ to find advisory details. To ignore the advisories, add their IDs to the \"policy.advisories.ignore-id\" config or add the package to \"policy.advisories.ignore\". To exempt the package from the cooldown policy, add it to the \"policy.cooldown.ignore\" config, or run the command with COMPOSER_POLICY_COOLDOWN_PERIOD=0 for a one-off bypass. To turn a policy off entirely, you can set \"policy.advisories.block\" or \"policy.cooldown.block\" to false.", $message);
    }

    public function testGetMissingPackageReasonKeepsOneSentenceWhenOnlyTheCooldownWithheldVersions(): void
    {
        $pool = new Pool([], [], [], [], [
            'vendor/pkg' => [
                '1.0.1.0' => PolicyRemovalReason::cooldown('vendor/pkg', '1.0.1', '2026-01-10T12:00:00+00:00', '1 day', 'published-time'),
                '1.0.2.0' => PolicyRemovalReason::cooldown('vendor/pkg', '1.0.2', '2026-01-12T12:00:00+00:00', '3 days', 'published-time'),
            ],
        ]);

        $repositorySet = new RepositorySet();
        $repositorySet->addRepository(new ArrayRepository([self::getPackage('vendor/pkg', '1.0.1'), self::getPackage('vendor/pkg', '1.0.2')]));

        $message = implode('', Problem::getMissingPackageReason(
            $repositorySet,
            new Request(),
            $pool,
            false,
            'vendor/pkg',
            new Constraint('>=', '1.0.0.0')
        ));

        // the sentence points at the version that becomes available soonest
        self::assertStringContainsString('found vendor/pkg[1.0.1, 1.0.2] but these were not loaded, because they are still in the cooldown period configured in "policy.cooldown" (available in 1 day).', $message);
        self::assertStringNotContainsString("\n      - ", $message);
    }

    public function testGetMissingPackageReasonListsFilterListAndCooldownRemovalsSeparately(): void
    {
        $flagged = self::getPackage('vendor/pkg', '1.0.0');
        $withheld = self::getPackage('vendor/pkg', '1.0.1');
        $entry = new FilterListEntry(
            'vendor/pkg',
            new MatchAllConstraint(),
            'malware',
            'https://example.org/malware/vendor-pkg',
            'looks suspicious',
            'PKG-1'
        );

        $pool = new Pool(
            [],
            [],
            [],
            [],
            [
                'vendor/pkg' => [
                    '1.0.0.0' => PolicyRemovalReason::filterList('vendor/pkg', '1.0.0', [$entry]),
                    '1.0.1.0' => PolicyRemovalReason::cooldown('vendor/pkg', '1.0.1', '2026-01-10T12:00:00+00:00', '5 days', 'time'),
                ],
            ]
        );

        $repositorySet = new RepositorySet();
        $repositorySet->addRepository(new ArrayRepository([$flagged, $withheld]));

        $message = implode('', Problem::getMissingPackageReason(
            $repositorySet,
            new Request(),
            $pool,
            false,
            'vendor/pkg',
            new MultiConstraint([new Constraint('>=', '1.0.0.0'), new Constraint('<', '2.0.0.0')], true)
        ));

        self::assertStringContainsString("found vendor/pkg[1.0.0, 1.0.1] but these were not loaded, because:\n      - 1.0.0: flagged as malware", $message);
        self::assertStringContainsString('reason: looks suspicious', $message);
        self::assertStringContainsString("\n      - 1.0.1: still in the cooldown period configured in \"policy.cooldown\" (available in 5 days, based on the package-supplied time field as the repository provides no published-time)\n      To ignore filters for this package, add the package to the \"policy.malware.ignore\" config. To exempt the package from the cooldown policy, add it to the \"policy.cooldown.ignore\" config, or run the command with COMPOSER_POLICY_COOLDOWN_PERIOD=0 for a one-off bypass. To turn a policy off entirely, you can set \"policy.malware.block\" or \"policy.cooldown.block\" to false.", $message);
    }
}
