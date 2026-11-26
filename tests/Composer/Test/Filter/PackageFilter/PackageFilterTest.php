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

namespace Composer\Test\Filter\PackageFilter;

use Composer\Filter\PackageFilter\PackageFilter;
use Composer\Filter\PackageFilter\RejectionReason;
use Composer\Filter\PackageFilter\RejectionRuleInterface;
use Composer\Package\BasePackage;
use Composer\Test\TestCase;

class PackageFilterTest extends TestCase
{
    public function testLaterRulesOnlySeeAcceptedPackages(): void
    {
        $old = self::getPackage('foo/bar', '1.0.0');
        $new = self::getPackage('foo/bar', '2.0.0');

        $abandoned = new RejectingRule(['2.0.0'], static function (BasePackage $package): RejectionReason {
            return RejectionReason::abandoned($package->getName(), $package->getPrettyVersion());
        });
        $cooldown = new RejectingRule(['1.0.0', '2.0.0'], [self::class, 'cooldown']);

        $result = (new PackageFilter([$abandoned, $cooldown]))->filter([$old, $new]);

        self::assertSame([['1.0.0', '2.0.0']], $abandoned->seen);
        self::assertSame([['1.0.0']], $cooldown->seen);
        self::assertSame([], $result->getAccepted());
        self::assertSame([$old, $new], $result->getRejected());
        self::assertSame([RejectionReason::ABANDONED], self::types($result->getReasons($new)));
        self::assertSame([RejectionReason::COOLDOWN], self::types($result->getReasons($old)));
    }

    public function testAllReasonsAreCollectedOnDemand(): void
    {
        $old = self::getPackage('foo/bar', '1.0.0');
        $new = self::getPackage('foo/bar', '2.0.0');

        $abandoned = new RejectingRule(['2.0.0'], static function (BasePackage $package): RejectionReason {
            return RejectionReason::abandoned($package->getName(), $package->getPrettyVersion());
        });
        $cooldown = new RejectingRule(['2.0.0'], [self::class, 'cooldown']);

        $result = (new PackageFilter([$abandoned, $cooldown]))->filter([$old, $new], true);

        self::assertSame([['1.0.0', '2.0.0']], $cooldown->seen);
        self::assertSame([$old], $result->getAccepted());
        self::assertSame([RejectionReason::ABANDONED, RejectionReason::COOLDOWN], self::types($result->getReasons($new)));
        self::assertNotNull($result->getReason($new, RejectionReason::COOLDOWN));
        self::assertNull($result->getReason($old, RejectionReason::COOLDOWN));
    }

    public function testRejectedOnlyByListsPackagesNoOtherReasonKeepsOut(): void
    {
        $cooldownOnly = self::getPackage('foo/bar', '1.0.0');
        $both = self::getPackage('foo/bar', '2.0.0');
        $accepted = self::getPackage('foo/bar', '0.9.0');

        $abandoned = new RejectingRule(['2.0.0'], static function (BasePackage $package): RejectionReason {
            return RejectionReason::abandoned($package->getName(), $package->getPrettyVersion());
        });
        $cooldown = new RejectingRule(['1.0.0', '2.0.0'], [self::class, 'cooldown']);

        $result = (new PackageFilter([$abandoned, $cooldown]))->filter([$accepted, $cooldownOnly, $both], true);

        self::assertSame([$cooldownOnly], $result->getRejectedOnlyBy(RejectionReason::COOLDOWN));
        self::assertSame([$cooldownOnly, $both], $result->getRejectedOnlyBy(RejectionReason::COOLDOWN, RejectionReason::ABANDONED));
        self::assertSame([$cooldownOnly, $both], $result->getRejected(RejectionReason::COOLDOWN));
        self::assertSame([$both], $result->getRejected(RejectionReason::ABANDONED));
    }

    public function testSortingKeepsTheReasonsWithTheirPackage(): void
    {
        $old = self::getPackage('foo/bar', '1.0.0');
        $new = self::getPackage('foo/bar', '2.0.0');

        $result = (new PackageFilter([new RejectingRule(['1.0.0'], [self::class, 'cooldown'])]))->filter([$old, $new]);
        $sorted = $result->sorted(static function (BasePackage $a, BasePackage $b): int {
            return version_compare($b->getVersion(), $a->getVersion());
        });

        self::assertSame([$new, $old], $sorted->getPackages());
        self::assertSame([$new], $sorted->getAccepted());
        self::assertSame([RejectionReason::COOLDOWN], self::types($sorted->getReasons($old)));
    }

    public static function cooldown(BasePackage $package): RejectionReason
    {
        return RejectionReason::cooldown($package->getName(), $package->getPrettyVersion(), '2026-01-01T00:00:00+00:00', '1 day', 'published-time');
    }

    /**
     * @param list<RejectionReason> $reasons
     * @return list<string>
     */
    private static function types(array $reasons): array
    {
        return array_map(static function (RejectionReason $reason): string {
            return $reason->getType();
        }, $reasons);
    }
}

class RejectingRule implements RejectionRuleInterface
{
    /** @var list<list<string>> versions passed to each getRejections() call */
    public $seen = [];
    /** @var list<string> */
    private $versions;
    /** @var callable(BasePackage): RejectionReason */
    private $createReason;

    /**
     * @param list<string> $versions
     * @param callable(BasePackage): RejectionReason $createReason
     */
    public function __construct(array $versions, callable $createReason)
    {
        $this->versions = $versions;
        $this->createReason = $createReason;
    }

    public function getRejections(array $packages): array
    {
        $this->seen[] = array_values(array_map(static function (BasePackage $package): string {
            return $package->getPrettyVersion();
        }, $packages));

        $rejections = [];
        foreach ($packages as $key => $package) {
            if (in_array($package->getPrettyVersion(), $this->versions, true)) {
                $rejections[$key] = ($this->createReason)($package);
            }
        }

        return $rejections;
    }
}
