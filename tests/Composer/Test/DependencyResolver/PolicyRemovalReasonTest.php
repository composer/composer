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
use Composer\DependencyResolver\PolicyRemovalReason;
use Composer\FilterList\FilterListEntry;
use Composer\Semver\Constraint\Constraint;
use Composer\Semver\Constraint\MatchAllConstraint;
use Composer\Test\TestCase;

class PolicyRemovalReasonTest extends TestCase
{
    public function testCombinePicksChronologicallyEarliestCooldownAcrossOffsets(): void
    {
        // Lexically "2026-01-12T00:30..." < "2026-01-12T01:00...", but 1.0.0's instant (23:00Z the day before)
        // is the earlier one, so a string compare would wrongly pick 2.0.0 as the soonest-available version
        $combined = PolicyRemovalReason::combine([
            PolicyRemovalReason::cooldown('vendor/pkg', '1.0.0', '2026-01-12T01:00:00+02:00', '5 days', 'time'),
            PolicyRemovalReason::cooldown('vendor/pkg', '2.0.0', '2026-01-12T00:30:00+00:00', '6 days', 'time'),
        ]);

        self::assertSame('1.0.0', $combined->getPrettyVersion());
        self::assertSame('5 days', $combined->getCooldownInfo()['availableIn'] ?? null);
    }

    public function testCombineMergesAdvisoriesOfAllVersions(): void
    {
        $shared = new PartialSecurityAdvisory('vendor/pkg', 'PKSA-aaaa-aaaa-aaaa', new Constraint('<', '2.0.0.0'));
        $combined = PolicyRemovalReason::combine([
            PolicyRemovalReason::advisories('vendor/pkg', '1.0.0', [$shared, new PartialSecurityAdvisory('vendor/pkg', 'CVE-2026-1', new Constraint('<', '1.1.0.0'))]),
            PolicyRemovalReason::advisories('vendor/pkg', '1.5.0', [$shared]),
        ]);

        self::assertSame(['PKSA-aaaa-aaaa-aaaa', 'CVE-2026-1'], array_map(static function (PartialSecurityAdvisory $advisory): string {
            return $advisory->advisoryId;
        }, $combined->getAdvisories()));
        self::assertStringContainsString('Review the advisory details above', $combined->getRemedy());
    }

    public function testCombineRejectsMixedPolicies(): void
    {
        self::expectException(\LogicException::class);

        PolicyRemovalReason::combine([
            PolicyRemovalReason::abandoned('vendor/pkg', '1.0.0'),
            PolicyRemovalReason::cooldown('vendor/pkg', '2.0.0', '2026-01-12T00:30:00+00:00', '6 days', 'time'),
        ]);
    }

    public function testFilterListRendersEachList(): void
    {
        $reason = PolicyRemovalReason::filterList('vendor/pkg', '1.0.0', [
            new FilterListEntry('vendor/pkg', new MatchAllConstraint(), 'malware', 'https://example.org/m', 'looks suspicious', 'PKG-1', 'aikido'),
            new FilterListEntry('vendor/pkg', new MatchAllConstraint(), 'internal'),
        ]);

        self::assertSame('were', $reason->getVerb(true));
        self::assertSame('flagged as malware reported by aikido (see https://example.org/m) reason: looks suspicious, filtered by internal', $reason->getDescription());
        self::assertSame('To ignore filters for this package, add the package to the "policy.malware.ignore" and "policy.internal.ignore" config.', $reason->getRemedy());
        self::assertSame('"policy.malware.block" and "policy.internal.block"', $reason->getOffSwitch());
    }

    public function testCooldownMentionsWeakerTimeSource(): void
    {
        $verified = PolicyRemovalReason::cooldown('vendor/pkg', '1.0.0', '2026-01-12T00:30:00+00:00', '5 days', 'published-time');
        $unverified = PolicyRemovalReason::cooldown('vendor/pkg', '1.0.0', '2026-01-12T00:30:00+00:00', '5 days', 'time');

        self::assertSame('still in the cooldown period configured in "policy.cooldown" (available in 5 days)', $verified->getDescription());
        self::assertStringContainsString('based on the package-supplied time field', $unverified->getDescription());
        self::assertSame('"policy.cooldown.block"', $verified->getOffSwitch());
    }
}
