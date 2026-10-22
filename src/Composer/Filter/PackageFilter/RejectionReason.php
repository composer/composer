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

use Composer\Advisory\PartialSecurityAdvisory;
use Composer\Advisory\SecurityAdvisory;
use Composer\FilterList\FilterListEntry;
use Composer\Package\Link;
use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * Why a package version was rejected, and how to render that to the user
 *
 * @internal
 * @final
 * @phpstan-type RejectionType self::ABANDONED|self::ADVISORIES|self::FILTER_LIST|self::COOLDOWN|self::PLATFORM|self::STABILITY|self::CONSTRAINT|self::REPOSITORY_PRIORITY
 */
class RejectionReason
{
    public const ABANDONED = 'abandoned';
    public const ADVISORIES = 'advisories';
    public const FILTER_LIST = 'filter-list';
    public const COOLDOWN = 'cooldown';
    public const PLATFORM = 'platform';
    public const STABILITY = 'stability';
    public const CONSTRAINT = 'constraint';
    public const REPOSITORY_PRIORITY = 'repository-priority';

    /** The rejections caused by dependency policies, in the order the policies are applied */
    public const POLICIES = [self::ABANDONED, self::ADVISORIES, self::FILTER_LIST, self::COOLDOWN];

    public const COOLDOWN_REMEDY = 'To exempt the package from the cooldown policy, add it to the "policy.cooldown.ignore" config, or run the command with --no-cooldown for a one-off bypass.';

    /** @var RejectionType */
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
    /** @var list<array{link: Link, problem: string}> */
    private $platformRequirements = [];
    /** @var ?string */
    private $canonicalRepositoryName = null;

    /**
     * @param RejectionType $type
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
     * @param array<array{link: Link, problem: string}> $unsatisfied the platform requirements that are not met, with why
     */
    public static function platform(string $packageName, string $prettyVersion, array $unsatisfied): self
    {
        $reason = new self(self::PLATFORM, $packageName, $prettyVersion);
        $reason->platformRequirements = array_values($unsatisfied);

        return $reason;
    }

    public static function stability(string $packageName, string $prettyVersion): self
    {
        return new self(self::STABILITY, $packageName, $prettyVersion);
    }

    public static function constraint(string $packageName, string $prettyVersion): self
    {
        return new self(self::CONSTRAINT, $packageName, $prettyVersion);
    }

    /**
     * @param string $canonicalRepositoryName the higher priority repository the package was found in, which hides it in the others
     */
    public static function repositoryPriority(string $packageName, string $prettyVersion, string $canonicalRepositoryName): self
    {
        $reason = new self(self::REPOSITORY_PRIORITY, $packageName, $prettyVersion);
        $reason->canonicalRepositoryName = $canonicalRepositoryName;

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
                throw new \LogicException('Cannot combine reasons of different types: '.$first->type.' and '.$reason->type);
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

            foreach ($reason->platformRequirements as $requirement) {
                $key = $requirement['link']->getTarget().' '.$requirement['link']->getPrettyConstraint().' '.$requirement['problem'];
                if (!isset($seen[$key])) {
                    $seen[$key] = true;
                    $combined->platformRequirements[] = $requirement;
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
     * Explains why policies rejected the versions, as "because ..." followed by the remedies
     *
     * When several policies rejected different versions, one sentence would attribute every reason to
     * every version, so the versions are then listed grouped by their own reason
     *
     * @param non-empty-array<self> $reasons policy rejections, in version order
     * @param bool $decorated whether to link advisories to their details, which only works in console output and not in exception messages
     */
    public static function explainPolicyRejections(array $reasons, bool $plural, bool $decorated = true): string
    {
        $policies = array_unique(array_map(static function (self $reason): string {
            return $reason->getType();
        }, $reasons));
        if (\count($policies) === 1) {
            $reason = self::combine($reasons);
            $remedy = $reason->getRemedy();

            return 'because '.($plural ? 'they ' : 'it ').$reason->getVerb($plural).' '.$reason->getDescription($decorated).'.'.($remedy !== '' ? ' '.$remedy : '').' To turn the feature off entirely, you can set '.$reason->getOffSwitch().' to false.';
        }

        $versionsByReason = [];
        $remedies = [];
        $offSwitches = [];
        foreach ($reasons as $reason) {
            $versionsByReason[$reason->getDescription($decorated)][$reason->getPrettyVersion()] = true;
            if ($reason->getRemedy() !== '') {
                $remedies[$reason->getRemedy()] = true;
            }
            $offSwitches[$reason->getOffSwitch()] = true;
        }

        $lines = [];
        foreach ($versionsByReason as $description => $versions) {
            $lines[] = implode(', ', array_keys($versions)) . ': ' . $description;
        }

        $remedies['To turn a policy off entirely, you can set ' . implode(' or ', array_keys($offSwitches)) . ' to false.'] = true;

        return "because:\n      - " . implode("\n      - ", $lines) . "\n      " . implode(' ', array_keys($remedies));
    }

    /**
     * @return RejectionType
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * Whether a dependency policy caused the rejection, which can then be turned off in the policy config
     */
    public function isPolicy(): bool
    {
        return \in_array($this->type, self::POLICIES, true);
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
     * @return list<array{link: Link, problem: string}>
     */
    public function getPlatformRequirements(): array
    {
        return $this->platformRequirements;
    }

    public function getCanonicalRepositoryName(): ?string
    {
        return $this->canonicalRepositoryName;
    }

    /**
     * The verb that makes the description a sentence, e.g. "they are" + "abandoned"
     */
    public function getVerb(bool $plural): string
    {
        if ($this->type === self::FILTER_LIST) {
            return $plural ? 'were' : 'was';
        }
        if ($this->type === self::PLATFORM) {
            return $plural ? 'require' : 'requires';
        }
        if ($this->type === self::STABILITY || $this->type === self::CONSTRAINT) {
            return $plural ? 'do' : 'does';
        }

        return $plural ? 'are' : 'is';
    }

    /**
     * @param bool $decorated whether to link advisories to their details, which only works in console output and not in exception messages
     */
    public function getDescription(bool $decorated = true): string
    {
        switch ($this->type) {
            case self::ABANDONED:
                return 'abandoned';
            case self::ADVISORIES:
                return 'affected by security advisories ("' . implode('", "', $decorated ? $this->getLinkedAdvisoryIdentifiers() : array_map(static function (PartialSecurityAdvisory $advisory): string {
                    return $advisory->advisoryId;
                }, $this->advisories)) . '")';
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
            case self::PLATFORM:
                return implode(' and ', array_map(static function (array $requirement): string {
                    return $requirement['link']->getTarget().' '.$requirement['link']->getPrettyConstraint().' which '.$requirement['problem'];
                }, $this->platformRequirements));
            case self::STABILITY:
                return 'not match your minimum-stability';
            case self::CONSTRAINT:
                return 'not match the constraint';
            case self::REPOSITORY_PRIORITY:
                return 'from a repository with a lower priority than '.$this->canonicalRepositoryName.', which is canonical for this package';
        }

        // @phpstan-ignore deadCode.unreachable
        throw new \LogicException('Unknown rejection type: '.$this->type);
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
            case self::PLATFORM:
            case self::STABILITY:
            case self::CONSTRAINT:
            case self::REPOSITORY_PRIORITY:
                return '';
        }

        // @phpstan-ignore deadCode.unreachable
        throw new \LogicException('Unknown rejection type: '.$this->type);
    }

    /**
     * The config keys that turn the policy off, e.g. '"policy.advisories.block"'
     */
    public function getOffSwitch(): string
    {
        if (!$this->isPolicy()) {
            throw new \LogicException('Only policy rejections can be turned off, got '.$this->type);
        }

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
