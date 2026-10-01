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

namespace Composer\DependencyResolver;

use Composer\Advisory\PartialSecurityAdvisory;
use Composer\Advisory\SecurityAdvisory;
use Composer\FilterList\FilterListEntry;
use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * Why a dependency policy removed a package version from the pool, and how to render that to the user
 *
 * @internal
 * @final
 */
class PolicyRemovalReason
{
    public const ABANDONED = 'abandoned';
    public const ADVISORIES = 'advisories';
    public const FILTER_LIST = 'filter-list';
    public const COOLDOWN = 'cooldown';

    public const COOLDOWN_REMEDY = 'To exempt the package from the cooldown policy, add it to the "policy.cooldown.ignore" config, or run the command with COMPOSER_POLICY_COOLDOWN_PERIOD=0 for a one-off bypass.';

    /** @var self::* */
    private $type;
    /** @var string */
    private $packageName;
    /** @var string */
    private $prettyVersion;
    /** @var list<SecurityAdvisory|PartialSecurityAdvisory> */
    private $advisories = [];
    /** @var list<FilterListEntry> */
    private $filterListEntries = [];
    /** @var array{releaseDate: string, availableIn: string, source: string}|null */
    private $cooldown = null;

    /**
     * @param self::* $type
     */
    private function __construct(string $type, string $packageName, string $prettyVersion)
    {
        $this->type = $type;
        $this->packageName = $packageName;
        $this->prettyVersion = $prettyVersion;
    }

    public static function abandoned(string $packageName, string $prettyVersion): self
    {
        return new self(self::ABANDONED, $packageName, $prettyVersion);
    }

    /**
     * @param array<SecurityAdvisory|PartialSecurityAdvisory> $advisories
     */
    public static function advisories(string $packageName, string $prettyVersion, array $advisories): self
    {
        $reason = new self(self::ADVISORIES, $packageName, $prettyVersion);
        $reason->advisories = array_values($advisories);

        return $reason;
    }

    /**
     * @param array<FilterListEntry> $entries
     */
    public static function filterList(string $packageName, string $prettyVersion, array $entries): self
    {
        $reason = new self(self::FILTER_LIST, $packageName, $prettyVersion);
        $reason->filterListEntries = array_values($entries);

        return $reason;
    }

    /**
     * @param string $source 'published-time' when the repository vouched for the date, 'time' when it came from the package
     */
    public static function cooldown(string $packageName, string $prettyVersion, string $releaseDate, string $availableIn, string $source): self
    {
        $reason = new self(self::COOLDOWN, $packageName, $prettyVersion);
        $reason->cooldown = ['releaseDate' => $releaseDate, 'availableIn' => $availableIn, 'source' => $source];

        return $reason;
    }

    /**
     * Merges the reasons of several versions removed by the same policy, so one sentence can explain them all
     *
     * @param non-empty-array<self> $reasons
     */
    public static function combine(array $reasons): self
    {
        $first = reset($reasons);
        $combined = new self($first->type, $first->packageName, $first->prettyVersion);
        $seen = [];
        foreach ($reasons as $reason) {
            if ($reason->type !== $first->type) {
                throw new \LogicException('Cannot combine reasons of different policies: '.$first->type.' and '.$reason->type);
            }

            foreach ($reason->advisories as $advisory) {
                if (!isset($seen[$advisory->advisoryId])) {
                    $seen[$advisory->advisoryId] = true;
                    $combined->advisories[] = $advisory;
                }
            }

            foreach ($reason->filterListEntries as $entry) {
                if (!isset($seen[spl_object_id($entry)])) {
                    $seen[spl_object_id($entry)] = true;
                    $combined->filterListEntries[] = $entry;
                }
            }

            // point at the version that becomes available soonest
            if ($reason->cooldown !== null && ($combined->cooldown === null || new \DateTimeImmutable($reason->cooldown['releaseDate']) < new \DateTimeImmutable($combined->cooldown['releaseDate']))) {
                $combined->cooldown = $reason->cooldown;
                $combined->prettyVersion = $reason->prettyVersion;
            }
        }

        return $combined;
    }

    /**
     * @return self::*
     */
    public function getType(): string
    {
        return $this->type;
    }

    public function getPackageName(): string
    {
        return $this->packageName;
    }

    public function getPrettyVersion(): string
    {
        return $this->prettyVersion;
    }

    /**
     * @return list<SecurityAdvisory|PartialSecurityAdvisory>
     */
    public function getAdvisories(): array
    {
        return $this->advisories;
    }

    /**
     * @return array{releaseDate: string, availableIn: string, source: string}|null
     */
    public function getCooldownInfo(): ?array
    {
        return $this->cooldown;
    }

    /**
     * The verb that makes the description a sentence, e.g. "they are" + "abandoned"
     */
    public function getVerb(bool $plural): string
    {
        if ($this->type === self::FILTER_LIST) {
            return $plural ? 'were' : 'was';
        }

        return $plural ? 'are' : 'is';
    }

    public function getDescription(): string
    {
        switch ($this->type) {
            case self::ABANDONED:
                return 'abandoned';
            case self::ADVISORIES:
                return 'affected by security advisories ("' . implode('", "', $this->getLinkedAdvisoryIdentifiers()) . '")';
            case self::FILTER_LIST:
                return implode(', ', $this->getFilterListDescriptions());
            case self::COOLDOWN:
                $availableIn = '';
                if ($this->cooldown !== null) {
                    // When the policy had to rely on the author-controlled `time` field, say so, as the protection is weaker
                    $source = $this->cooldown['source'] === 'time' ? ', based on the package-supplied time field as the repository provides no published-time' : '';
                    $availableIn = ' (available in ' . $this->cooldown['availableIn'] . $source . ')';
                }

                return 'still in the cooldown period configured in "policy.cooldown"' . $availableIn;
        }

        // @phpstan-ignore deadCode.unreachable
        throw new \LogicException('Unknown policy: '.$this->type);
    }

    /**
     * How to let the package through while keeping the policy on, or '' if there is no such config
     */
    public function getRemedy(): string
    {
        switch ($this->type) {
            case self::ABANDONED:
                return '';
            case self::ADVISORIES:
                return $this->getAdvisoryDetailsHint() . ' To ignore the advisories, add their IDs to the "policy.advisories.ignore-id" config or add the package to "policy.advisories.ignore".';
            case self::FILTER_LIST:
                return 'To ignore filters for this package, add the package to the ' . implode(' and ', array_map(static function (string $listName): string {
                    return '"policy.' . $listName . '.ignore"';
                }, $this->getFilterListNames())) . ' config.';
            case self::COOLDOWN:
                return self::COOLDOWN_REMEDY;
        }

        // @phpstan-ignore deadCode.unreachable
        throw new \LogicException('Unknown policy: '.$this->type);
    }

    /**
     * The config keys that turn the policy off, e.g. '"policy.advisories.block"'
     */
    public function getOffSwitch(): string
    {
        if ($this->type === self::FILTER_LIST) {
            return implode(' and ', array_map(static function (string $listName): string {
                return '"policy.' . $listName . '.block"';
            }, $this->getFilterListNames()));
        }

        return '"policy.' . $this->type . '.block"';
    }

    /**
     * @return string[] identifiers linked to their advisory page where one is known
     */
    private function getLinkedAdvisoryIdentifiers(): array
    {
        return array_map(static function (PartialSecurityAdvisory $advisory): string {
            if ($advisory instanceof SecurityAdvisory && $advisory->link !== null && $advisory->link !== '') {
                return '<href='.OutputFormatter::escape($advisory->link).'>'.$advisory->advisoryId.'</>';
            }

            if (str_starts_with($advisory->advisoryId, 'PKSA-')) {
                return '<href='.OutputFormatter::escape('https://packagist.org/security-advisories/'.$advisory->advisoryId).'>'.$advisory->advisoryId.'</>';
            }

            return $advisory->advisoryId;
        }, $this->advisories);
    }

    private function getAdvisoryDetailsHint(): string
    {
        foreach ($this->advisories as $advisory) {
            if (!str_starts_with($advisory->advisoryId, 'PKSA-')) {
                return 'Review the advisory details above for more information.';
            }
        }

        return 'Go to https://packagist.org/security-advisories/ to find advisory details.';
    }

    /**
     * @return list<string>
     */
    private function getFilterListNames(): array
    {
        return array_keys($this->getFilterListDescriptions());
    }

    /**
     * @return array<string, string> list name => description
     */
    private function getFilterListDescriptions(): array
    {
        $lists = [];
        foreach ($this->filterListEntries as $entry) {
            $source = (bool) $entry->source ? ' reported by ' . $entry->source : '';
            $url = (bool) $entry->url ? ' (see ' . $entry->url . ')' : '';
            $reason = (bool) $entry->reason ? ' reason: ' . $entry->reason : '';

            $lists[$entry->listName][] = $source . $url . $reason;
        }

        $result = [];
        foreach ($lists as $listName => $listEntries) {
            $action = $listName === 'malware' ? 'flagged as ' : 'filtered by ';
            $result[$listName] = $action . $listName . implode(', ', $listEntries);
        }

        return $result;
    }
}
