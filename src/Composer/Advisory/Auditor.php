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
use Composer\Package\PackageInterface;
use Composer\Repository\RepositorySet;
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

    /**
     * @param PackageInterface[] $packages
     * @param self::FORMAT_* $format The format that will be used to output audit results.
     * @param bool $warningOnly If true, outputs a warning. If false, outputs an error.
     * @return int Amount of packages with vulnerabilities found
     * @throws InvalidArgumentException If no packages are passed in
     */
    public function audit(IOInterface $io, RepositorySet $repoSet, array $packages, string $format, bool $warningOnly = true): int
    {
        $advisories = $repoSet->getMatchingSecurityAdvisories($packages, $format === self::FORMAT_SUMMARY);
        if (self::FORMAT_JSON === $format) {
            $io->write(JsonFile::encode(['advisories' => $advisories]));

            return count($advisories);
        }

        $errorOrWarn = $warningOnly ? 'warning' : 'error';
        if (count($advisories) > 0) {
            [$affectedPackages, $totalAdvisories] = $this->countAdvisories($advisories);
            $plurality = $totalAdvisories === 1 ? 'y' : 'ies';
            $pkgPlurality = $affectedPackages === 1 ? '' : 's';
            $punctuation = $format === 'summary' ? '.' : ':';
            $io->writeError("<$errorOrWarn>Found $totalAdvisories security vulnerability advisor{$plurality} affecting $affectedPackages package{$pkgPlurality}{$punctuation}</$errorOrWarn>");
            $this->outputAdvisories($io, $advisories, $format);

            return $affectedPackages;
        }

        $io->writeError('<info>No security vulnerability advisories found</info>');

        return 0;
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
                // We've already output the number of advisories in audit()
                $io->writeError('Run composer audit for a full list of advisories.');

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
                $io->getTable()
                    ->setHorizontal()
                    ->setHeaders([
                        'Package',
                        'CVE',
                        'Title',
                        'URL',
                        'Affected versions',
                        'Reported at',
                    ])
                    ->addRow([
                        $advisory->packageName,
                        $this->getCVE($advisory),
                        $advisory->title,
                        $this->getURL($advisory),
                        $advisory->affectedVersions->getPrettyString(),
                        $advisory->reportedAt->format(DATE_ATOM),
                    ])
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
                $error[] = "CVE: ".$this->getCVE($advisory);
                $error[] = "Title: ".OutputFormatter::escape($advisory->title);
                $error[] = "URL: ".$this->getURL($advisory);
                $error[] = "Affected versions: ".OutputFormatter::escape($advisory->affectedVersions->getPrettyString());
                $error[] = "Reported at: ".$advisory->reportedAt->format(DATE_ATOM);
                $firstAdvisory = false;
            }
        }
        $io->writeError($error);
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
