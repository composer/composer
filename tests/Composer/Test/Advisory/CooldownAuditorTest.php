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

namespace Composer\Test\Advisory;

use Composer\Advisory\CooldownAuditor;
use Composer\Policy\CooldownPolicyConfig;
use Composer\Policy\IgnorePackageRule;
use Composer\Policy\ListPolicyConfig;
use Composer\Package\Package;
use Composer\Package\RootPackage;
use Composer\Semver\Constraint\MatchAllConstraint;
use Composer\Test\TestCase;
use DateTimeImmutable;
use DateTimeInterface;

class CooldownAuditorTest extends TestCase
{
    private const NOW = '2026-01-15 12:00:00';

    private function package(string $name, string $version, ?string $releaseDate, ?string $publishedDate = null): Package
    {
        $package = new Package($name, $version . '.0', $version);
        if ($releaseDate !== null) {
            $package->setReleaseDate(new DateTimeImmutable($releaseDate));
        }
        if ($publishedDate !== null) {
            $package->setPublishedDate(new DateTimeImmutable($publishedDate));
        }

        return $package;
    }

    /**
     * @param ListPolicyConfig::AUDIT_* $audit
     * @param array<string, list<IgnorePackageRule>> $ignore
     */
    private function cooldown(string $audit = ListPolicyConfig::AUDIT_REPORT, ?int $age = 604800, array $ignore = []): CooldownPolicyConfig
    {
        // 604800 = 7 days
        return new CooldownPolicyConfig(true, $audit, $ignore, $age);
    }

    private function auditor(): CooldownAuditor
    {
        return new CooldownAuditor(new DateTimeImmutable(self::NOW));
    }

    public function testCollectsOnlyVersionsWithinCooldown(): void
    {
        $old = $this->package('vendor/old', '1.0.0', '2026-01-01 12:00:00'); // 14 days old -> cleared
        $recent = $this->package('vendor/recent', '2.0.0', '2026-01-14 12:00:00'); // 1 day old -> within

        $result = $this->auditor()->collect([$old, $recent], $this->cooldown());

        self::assertArrayNotHasKey('vendor/old', $result);
        self::assertArrayHasKey('vendor/recent', $result);
        self::assertSame('2.0.0', $result['vendor/recent']['prettyVersion']);
        self::assertSame('time', $result['vendor/recent']['source']);
        self::assertNotSame('', $result['vendor/recent']['availableIn']);
    }

    public function testPrefersPublishedDateOverReleaseDate(): void
    {
        // author-controlled release date is old, but the server publication date is recent
        $package = $this->package('vendor/pkg', '1.0.0', '2025-01-01 12:00:00', '2026-01-14 12:00:00');

        $result = $this->auditor()->collect([$package], $this->cooldown());

        self::assertArrayHasKey('vendor/pkg', $result);
        self::assertSame('published-time', $result['vendor/pkg']['source']);
        self::assertSame((new DateTimeImmutable('2026-01-14 12:00:00'))->format(DateTimeInterface::ATOM), $result['vendor/pkg']['releaseDate']);
    }

    public function testSkipsDevPlatformRootAndDatelessPackages(): void
    {
        $dev = $this->package('vendor/dev', 'dev-main', self::NOW);
        $platform = $this->package('php', '8.3.0', self::NOW);
        $dateless = $this->package('vendor/nodate', '1.0.0', null);
        $root = new RootPackage('vendor/root', '1.0.0.0', '1.0.0');
        $root->setReleaseDate(new DateTimeImmutable(self::NOW));

        $result = $this->auditor()->collect([$dev, $platform, $dateless, $root], $this->cooldown());

        self::assertSame([], $result);
    }

    public function testRespectsIgnoreRules(): void
    {
        $recent = $this->package('vendor/recent', '2.0.0', '2026-01-14 12:00:00');
        $ignored = $this->package('vendor/ignored', '2.0.0', '2026-01-14 12:00:00');

        $ignore = IgnorePackageRule::parseIgnoreMap(['vendor/ignored' => 'pinned by us']);
        $result = $this->auditor()->collect([$recent, $ignored], $this->cooldown(ListPolicyConfig::AUDIT_REPORT, 604800, $ignore));

        self::assertArrayHasKey('vendor/recent', $result);
        self::assertArrayNotHasKey('vendor/ignored', $result);
    }

    public function testIgnoreRuleScopedToBlockOnlyDoesNotApplyToAudit(): void
    {
        $package = $this->package('vendor/pkg', '2.0.0', '2026-01-14 12:00:00');

        // on-audit=false means the rule only suppresses blocking, not auditing
        $ignore = ['vendor/pkg' => [new IgnorePackageRule('vendor/pkg', new MatchAllConstraint(), null, true, false)]];
        $result = $this->auditor()->collect([$package], $this->cooldown(ListPolicyConfig::AUDIT_REPORT, 604800, $ignore));

        self::assertArrayHasKey('vendor/pkg', $result);
    }

    public function testReturnsEmptyWhenNoCooldownConfigured(): void
    {
        $recent = $this->package('vendor/recent', '2.0.0', '2026-01-14 12:00:00');

        self::assertSame([], $this->auditor()->collect([$recent], $this->cooldown(ListPolicyConfig::AUDIT_REPORT, null)));
    }
}
