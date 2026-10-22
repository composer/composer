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

namespace Composer\Filter\PackageFilter;

use Composer\Package\BasePackage;

/**
 * Package versions in their original order, with the reasons each rejected one was rejected for
 *
 * @internal
 * @final
 */
class PackageFilterResult
{
    /** @var list<BasePackage> */
    private $packages;
    /** @var array<int, non-empty-list<RejectionReason>> package index => reasons */
    private $reasons;
    /** @var array<int, int>|null package object id => package index */
    private $indexes = null;

    /**
     * @param list<BasePackage> $packages
     * @param array<int, non-empty-list<RejectionReason>> $reasons package index => reasons
     */
    public function __construct(array $packages, array $reasons = [])
    {
        $this->packages = $packages;
        $this->reasons = $reasons;
    }

    /**
     * @return list<BasePackage> all packages, rejected or not
     */
    public function getPackages(): array
    {
        return $this->packages;
    }

    /**
     * @return list<BasePackage>
     */
    public function getAccepted(): array
    {
        $accepted = [];
        foreach ($this->packages as $index => $package) {
            if (!isset($this->reasons[$index])) {
                $accepted[] = $package;
            }
        }

        return $accepted;
    }

    /**
     * @param RejectionReason::*|null $type only return packages rejected for this reason, among others
     * @return list<BasePackage>
     */
    public function getRejected(?string $type = null): array
    {
        $rejected = [];
        foreach ($this->reasons as $index => $reasons) {
            if ($type === null || self::hasType($reasons, $type)) {
                $rejected[] = $this->packages[$index];
            }
        }

        return $rejected;
    }

    /**
     * @param RejectionReason::* $type
     * @return list<array{package: BasePackage, reason: RejectionReason}> the packages rejected for this reason, among others
     */
    public function getRejections(string $type): array
    {
        $rejections = [];
        foreach ($this->reasons as $index => $reasons) {
            foreach ($reasons as $reason) {
                if ($reason->getType() === $type) {
                    $rejections[] = ['package' => $this->packages[$index], 'reason' => $reason];
                    break;
                }
            }
        }

        return $rejections;
    }

    /**
     * The packages that would be accepted if the given rejection reasons did not apply, e.g. those only the cooldown keeps out
     *
     * @param RejectionReason::* ...$types
     * @return list<BasePackage>
     */
    public function getRejectedOnlyBy(string ...$types): array
    {
        $rejected = [];
        foreach ($this->reasons as $index => $reasons) {
            foreach ($reasons as $reason) {
                if (!in_array($reason->getType(), $types, true)) {
                    continue 2;
                }
            }
            $rejected[] = $this->packages[$index];
        }

        return $rejected;
    }

    /**
     * @return list<RejectionReason> empty if the package was accepted
     */
    public function getReasons(BasePackage $package): array
    {
        $index = $this->getIndex($package);

        return $index !== null ? $this->reasons[$index] ?? [] : [];
    }

    /**
     * @param RejectionReason::* $type
     */
    public function getReason(BasePackage $package, string $type): ?RejectionReason
    {
        foreach ($this->getReasons($package) as $reason) {
            if ($reason->getType() === $type) {
                return $reason;
            }
        }

        return null;
    }

    /**
     * @param array<int, RejectionReason> $reasons package index => reason to add
     */
    public function withReasons(array $reasons): self
    {
        $result = clone $this;
        foreach ($reasons as $index => $reason) {
            $result->reasons[$index][] = $reason;
        }
        ksort($result->reasons);

        return $result;
    }

    /**
     * Keeps the packages in the given order, e.g. after sorting them by preference
     *
     * @param callable(BasePackage, BasePackage): int $comparator
     */
    public function sorted(callable $comparator): self
    {
        $order = array_keys($this->packages);
        usort($order, function (int $a, int $b) use ($comparator): int {
            return $comparator($this->packages[$a], $this->packages[$b]);
        });

        $packages = [];
        $reasons = [];
        foreach ($order as $newIndex => $oldIndex) {
            $packages[] = $this->packages[$oldIndex];
            if (isset($this->reasons[$oldIndex])) {
                $reasons[$newIndex] = $this->reasons[$oldIndex];
            }
        }

        return new self($packages, $reasons);
    }

    /**
     * @param non-empty-list<RejectionReason> $reasons
     */
    private static function hasType(array $reasons, string $type): bool
    {
        foreach ($reasons as $reason) {
            if ($reason->getType() === $type) {
                return true;
            }
        }

        return false;
    }

    private function getIndex(BasePackage $package): ?int
    {
        if ($this->indexes === null) {
            $this->indexes = [];
            foreach ($this->packages as $index => $candidate) {
                $this->indexes[spl_object_id($candidate)] = $index;
            }
        }

        return $this->indexes[spl_object_id($package)] ?? null;
    }
}
