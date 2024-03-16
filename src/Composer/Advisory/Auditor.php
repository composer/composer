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

namespace Composer\Advisory;

use Composer\IO\ConsoleIO;
use Composer\IO\IOInterface;
use Composer\Json\JsonFile;
use Composer\Package\CompletePackageInterface;
use Composer\Package\PackageInterface;
use Composer\Repository\RepositorySet;
use Composer\Util\PackageInfo;
use Composer\Util\Platform;
use InvalidArgumentException;
use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * @internal
 */
class Auditor
{
    public const FORMAT_TABLE = 'table';

    public const FORMAT_PLAIN = 'plain';

    public const FORMAT_JSON = 'json';

    public const FORMAT_SUMMARY = 'summary';

    public const FORMATS = [
        self::FORMAT_TABLE,
        self::FORMAT_PLAIN,
        self::FORMAT_JSON,
        self::FORMAT_SUMMARY,
    ];

    public const ABANDONED_IGNORE = 'ignore';
    public const ABANDONED_REPORT = 'report';
    public const ABANDONED_FAIL = 'fail';

    /**
     * @param PackageInterface[] $packages
     * @param self::FORMAT_* $format The format that will be used to output audit results.
     * @param bool $warningOnly If true, outputs a warning. If false, outputs an error.
     * @param string[] $ignoreList List of advisory IDs, remote IDs or CVE IDs that reported but not listed as vulnerabilities.
     * @param self::ABANDONED_* $abandoned
     *
     * @return int Amount of packages with vulnerabilities found
     * @throws InvalidArgumentException If no packages are passed in
     */
    public function audit(IOInterface $io, RepositorySet $repoSet, array $packages, string $format, bool $warningOnly = true, array $ignoreList = [], string $abandoned = self::ABANDONED_FAIL): int
    {
        $allAdvisories = $repoSet->getMatchingSecurityAdvisories($packages, $format === self::FORMAT_SUMMARY);
        // we need the CVE & remote IDs set to filter ignores correctly so if we have any matches using the optimized codepath above
        // and ignores are set then we need to query again the full data to make sure it can be filtered
        if (count($allAdvisories) > 0 && $ignoreList !== [] && $format === self::FORMAT_SUMMARY) {
            $allAdvisories = $repoSet->getMatchingSecurityAdvisories($packages, false);
        }
        ['advisories' => $advisories, 'ignoredAdvisories' => $ignoredAdvisories] = $this->processAdvisories($allAdvisories, $ignoreList);

        $abandonedCount = 0;
        $affectedPackagesCount = 0;
        if ($abandoned === self::ABANDONED_IGNORE) {
            $abandonedPackages = [];
        } else {
            $abandonedPackages = $this->filterAbandonedPackages($packages);
            if ($abandoned === self::ABANDONED_FAIL) {
                $abandonedCount = count($abandonedPackages);
            }
        }

        if (self::FORMAT_JSON === $format) {
            $json = ['advisories' => $advisories];
            if ($ignoredAdvisories !== []) {
                $json['ignored-advisories'] = $ignoredAdvisories;
            }
            $json['abandoned'] = array_reduce($abandonedPackages, static function(array $carry, CompletePackageInterface $package): array {
                $carry[$package->getPrettyName()] = $package->getReplacementPackage();
                return $carry;
            }, []);

            $io->write(JsonFile::encode($json));

            return count($advisories) + $abandonedCount;
        }

        $errorOrWarn = $warningOnly ? 'warning' : 'error';
        if (count($advisories) > 0 || count($ignoredAdvisories) > 0) {
            $passes = [
                [$ignoredAdvisories, "<info>Found %d ignored security vulnerability advisor%s affecting %d package%s%s</info>"],
                // this has to run last to allow $affectedPackagesCount in the return statement to be correct
                [$advisories, "<$errorOrWarn>Found %d security vulnerability advisor%s affecting %d package%s%s</$errorOrWarn>"],
            ];
            foreach ($passes as [$advisoriesToOutput, $message]) {
                [$affectedPackagesCount, $totalAdvisoryCount] = $this->countAdvisories($advisoriesToOutput);
                if ($affectedPackagesCount > 0) {
                    $plurality = $totalAdvisoryCount === 1 ? 'y' : 'ies';
                    $pkgPlurality = $affectedPackagesCount === 1 ? '' : 's';
                    $punctuation = $format === 'summary' ? '.' : ':';
                    $io->writeError(sprintf($message, $totalAdvisoryCount, $plurality, $affectedPackagesCount, $pkgPlurality, $punctuation));
                    $this->outputAdvisories($io, $advisoriesToOutput, $format);
                }
            }

            if ($format === self::FORMAT_SUMMARY) {
                $io->writeError('Run "composer audit" for a full list of advisories.');
            }
        } else {
            $io->writeError('<info>No security vulnerability advisories found.</info>');
        }

        if (count($abandonedPackages) > 0 && $format !== self::FORMAT_SUMMARY) {
            $this->outputAbandonedPackages($io, $abandonedPackages, $format);
        }

        return $affectedPackagesCount + $abandonedCount;
    }

    /**
     * @param array<PackageInterface> $packages
     * @return array<CompletePackageInterface>
     */
    private function filterAbandonedPackages(array $packages): array
    {
        return array_filter($packages, static function (PackageInterface $pkg) {
            return $pkg instanceof CompletePackageInterface && $pkg->isAbandoned();
        });
    }

    /**
     * @phpstan-param array<string, array<PartialSecurityAdvisory|SecurityAdvisory>> $allAdvisories
     * @param array<string>|array<string,string> $ignoreList List of advisory IDs, remote IDs or CVE IDs that reported but not listed as vulnerabilities.
     * @phpstan-return array{advisories: array<string, array<PartialSecurityAdvisory|SecurityAdvisory>>, ignoredAdvisories: array<string, array<PartialSecurityAdvisory|SecurityAdvisory>>}
     */
    private function processAdvisories(array $allAdvisories, array $ignoreList): array
    {
        if ($ignoreList === []) {
            return ['advisories' => $allAdvisories, 'ignoredAdvisories' => []];
        }

        if (\count($ignoreList) > 0 && !\array_is_list($ignoreList)) {
            $ignoredIds = array_keys($ignoreList);
        } else {
            $ignoredIds = $ignoreList;
        }

        $advisories = [];
        $ignored = [];
        $ignoreReason = null;

        foreach ($allAdvisories as $package => $pkgAdvisories) {
            foreach ($pkgAdvisories as $advisory) {
                $isActive = true;

                if (in_array($advisory->advisoryId, $ignoredIds, true)) {
                    $isActive = false;
                    $ignoreReason = $ignoreList[$advisory->advisoryId] ?? null;
                }

                if ($advisory instanceof SecurityAdvisory) {
                    if (in_array($advisory->cve, $ignoredIds, true)) {
                        $isActive = false;
                        $ignoreReason = $ignoreList[$advisory->cve] ?? null;
                    }

                    foreach ($advisory->sources as $source) {
                        if (in_array($source['remoteId'], $ignoredIds, true)) {
                            $isActive = false;
                            $ignoreReason = $ignoreList[$source['remoteId']] ?? null;
                            break;
                        }
                    }
                }

                if ($isActive) {
                    $advisories[$package][] = $advisory;
                    continue;
                }

                // Partial security advisories only used in summary mode
                // and in that case we do not need to cast the object.
                if ($advisory instanceof SecurityAdvisory) {
                    $advisory = $advisory->toIgnoredAdvisory($ignoreReason);
                }

                $ignored[$package][] = $advisory;
            }
        }

        return ['advisories' => $advisories, 'ignoredAdvisories' => $ignored];
    }

    /**
     * @param array<string, array<PartialSecurityAdvisory>> $advisories
     * @return array{int, int} Count of affected packages and total count of advisories
     */
    private function countAdvisories(array $advisories): array
    {
        $count = 0;
        foreach ($advisories as $packageAdvisories) {
            $count += count($packageAdvisories);
        }

        return [count($advisories), $count];
    }

    /**
     * @param array<string, array<SecurityAdvisory>> $advisories
     * @param self::FORMAT_* $format The format that will be used to output audit results.
     */
    private function outputAdvisories(IOInterface $io, array $advisories, string $format): void
    {
        switch ($format) {
            case self::FORMAT_TABLE:
                if (!($io instanceof ConsoleIO)) {
                    throw new InvalidArgumentException('Cannot use table format with ' . get_class($io));
                }
                $this->outputAdvisoriesTable($io, $advisories);

                return;
            case self::FORMAT_PLAIN:
                $this->outputAdvisoriesPlain($io, $advisories);

                return;
            case self::FORMAT_SUMMARY:

                return;
            default:
                throw new InvalidArgumentException('Invalid format "'.$format.'".');
        }
    }

    /**
     * @param array<string, array<SecurityAdvisory>> $advisories
     */
    private function outputAdvisoriesTable(ConsoleIO $io, array $advisories): void
    {
        foreach ($advisories as $packageAdvisories) {
            foreach ($packageAdvisories as $advisory) {
                $headers = [
                    'Package',
                    'Severity',
                    'CVE',
                    'Title',
                    'URL',
                    'Affected versions',
                    'Reported at',
                ];
                $row = [
                    $advisory->packageName,
                    $this->getSeverity($advisory),
                    $this->getCVE($advisory),
                    $advisory->title,
                    $this->getURL($advisory),
                    $advisory->affectedVersions->getPrettyString(),
                    $advisory->reportedAt->format(DATE_ATOM),
                ];
                if ($advisory->cve === null) {
                    $headers[] = 'Advisory ID';
                    $row[] = $advisory->advisoryId;
                }
                if ($advisory instanceof IgnoredSecurityAdvisory) {
                    $headers[] = 'Ignore reason';
                    $row[] = $advisory->ignoreReason ?? 'None specified';
                }
                $io->getTable()
                    ->setHorizontal()
                    ->setHeaders($headers)
                    ->addRow($row)
                    ->setColumnWidth(1, 80)
                    ->setColumnMaxWidth(1, 80)
                    ->render();
            }
        }
    }

    /**
     * @param array<string, array<SecurityAdvisory>> $advisories
     */
    private function outputAdvisoriesPlain(IOInterface $io, array $advisories): void
    {
        $error = [];
        $firstAdvisory = true;
        foreach ($advisories as $packageAdvisories) {
            foreach ($packageAdvisories as $advisory) {
                if (!$firstAdvisory) {
                    $error[] = '--------';
                }
                $error[] = "Package: ".$advisory->packageName;
                $error[] = "Severity: ".$this->getSeverity($advisory);
                $error[] = "CVE: ".$this->getCVE($advisory);
                if ($advisory->cve === null) {
                    $error[] = "Advisory ID: ".$advisory->advisoryId;
                }
                $error[] = "Title: ".OutputFormatter::escape($advisory->title);
                $error[] = "URL: ".$this->getURL($advisory);
                $error[] = "Affected versions: ".OutputFormatter::escape($advisory->affectedVersions->getPrettyString());
                $error[] = "Reported at: ".$advisory->reportedAt->format(DATE_ATOM);
                if ($advisory instanceof IgnoredSecurityAdvisory) {
                    $error[] = "Ignore reason: ".($advisory->ignoreReason ?? 'None specified');
                }
                $firstAdvisory = false;
            }
        }
        $io->writeError($error);
    }

    /**
     * @param array<CompletePackageInterface> $packages
     * @param self::FORMAT_PLAIN|self::FORMAT_TABLE $format
     */
    private function outputAbandonedPackages(IOInterface $io, array $packages, string $format): void
    {
        $io->writeError(sprintf('<error>Found %d abandoned package%s:</error>', count($packages), count($packages) > 1 ? 's' : ''));

        if ($format === self::FORMAT_PLAIN) {
            foreach ($packages as $pkg) {
                $replacement = $pkg->getReplacementPackage() !== null
                    ? 'Use '.$pkg->getReplacementPackage().' instead'
                    : 'No replacement was suggested';
                $io->writeError(sprintf(
                    '%s is abandoned. %s.',
                    $this->getPackageNameWithLink($pkg),
                    $replacement
                ));
            }

            return;
        }

        if (!($io instanceof ConsoleIO)) {
            throw new InvalidArgumentException('Cannot use table format with ' . get_class($io));
        }

        $table = $io->getTable()
            ->setHeaders(['Abandoned Package', 'Suggested Replacement'])
            ->setColumnWidth(1, 80)
            ->setColumnMaxWidth(1, 80);

        foreach ($packages as $pkg) {
            $replacement = $pkg->getReplacementPackage() !== null ? $pkg->getReplacementPackage() : 'none';
            $table->addRow([$this->getPackageNameWithLink($pkg), $replacement]);
        }

        $table->render();
    }

    private function getPackageNameWithLink(PackageInterface $package): string
    {
        $packageUrl = PackageInfo::getViewSourceOrHomepageUrl($package);

        return $packageUrl !== null ? '<href=' . OutputFormatter::escape($packageUrl) . '>' . $package->getPrettyName() . '</>' : $package->getPrettyName();
    }

    private function getSeverity(SecurityAdvisory $advisory): string
    {
        if ($advisory->severity === null) {
            return '';
        }

        return $advisory->severity;
    }

    private function getCVE(SecurityAdvisory $advisory): string
    {
        if ($advisory->cve === null) {
            return 'NO CVE';
        }

        return '<href=https://cve.mitre.org/cgi-bin/cvename.cgi?name='.$advisory->cve.'>'.$advisory->cve.'</>';
    }

    private function getURL(SecurityAdvisory $advisory): string
    {
        if ($advisory->link === null) {
            return '';
        }

        return '<href='.OutputFormatter::escape($advisory->link).'>'.OutputFormatter::escape($advisory->link).'</>';
    }

}
