<?php

namespace Composer\Util;

use Composer\IO\ConsoleIO;
use Composer\IO\IOInterface;
use Composer\Package\BasePackage;
use Composer\Semver\Semver;
use InvalidArgumentException;

class Auditor
{
    public const API_URL = 'https://packagist.org/api/security-advisories/';

    public const FORMAT_TABLE = 'table';

    public const FORMAT_PLAIN = 'plain';

    public const FORMATS = [
        self::FORMAT_TABLE,
        self::FORMAT_PLAIN,
    ];

    private $httpDownloader;

    private $format;

    /**
     * @param HttpDownloader $httpDownloader
     * @param string $format The format that will be used to output audit results.
     */
    public function __construct(HttpDownloader $httpDownloader, string $format = self::FORMAT_TABLE)
    {
        $this->httpDownloader = $httpDownloader;
        $this->setFormat($format);
    }

    /**
     * Get the format that will be used to output audit results.
     *
     * @return string
     */
    public function getFormat(): string
    {
        return $this->format;
    }

    /**
     * Set the format that will be used to output audit results.
     *
     * @param string $format
     * @return self
     */
    public function setFormat(string $format): self
    {
        if (!in_array($format, [self::FORMAT_TABLE, self::FORMAT_PLAIN])) {
            throw new InvalidArgumentException('Invalid format.');
        }
        $this->format = $format;
        return $this;
    }

    /**
     * @param IOInterface $io
     * @param BasePackage[] $packages
     * @param bool $warningOnly If true, outputs a warning. If false, outputs an error.
     * @return int
     * @throws InvalidArgumentException If no packages are passed in
     */
    public function audit(IOInterface $io, array $packages, bool $warningOnly = true): int
    {
        $advisories = $this->getAdvisories($packages);
        $format = $warningOnly ? 'warning' : 'error';
        if (count($advisories) > 0) {
            $numAdvisories = $this->countAdvisories($advisories);
            $plurality = $numAdvisories === 1 ? 'y' : 'ies';
            $io->writeError("<$format>Found $numAdvisories security vulnerability advisor$plurality:</$format>");
            $this->outputAdvisories($io, $advisories);
            return 1;
        }
        $io->writeError('<info>No security vulnerability advisories found</info>');
        return 0;
    }

    /**
     * Get advisories from packagist.org
     *
     * @param BasePackage[] $packages
     * @param ?int $updatedSince Timestamp
     * @param bool $filterByVersion Filter by the package versions if true
     * @return string[][][]
     * @throws InvalidArgumentException If no packages and no updatedSince timestamp are passed in
     */
    public function getAdvisories(array $packages = [], int $updatedSince = null, bool $filterByVersion = true): array
    {
        if (count($packages) === 0 && $updatedSince === null) {
            throw new InvalidArgumentException(
                'At least one package or an $updatedSince timestamp must be passed in.'
            );
        }

        if (count($packages) === 0 && $filterByVersion) {
            return [];
        }

        // Add updatedSince query to URL if passed in
        $url = self::API_URL;
        if ($updatedSince !== null) {
            $url .= "?updatedSince=$updatedSince";
        }

        // Get advisories from API
        $response = $this->httpDownloader->get($url, $this->createPostOptions($packages));
        $advisories = $response->decodeJson()['advisories'];

        if (count($packages) > 0 && $filterByVersion) {
            return $this->filterAdvisories($advisories, $packages);
        }

        return $advisories;
    }

    /**
     * @param BasePackage[] $packages
     * @return string[]
     * @phpstan-return array<string, array<string, array<int, string>|int|string>>
     */
    private function createPostOptions(array $packages): array
    {
        $options = [
            'http' => [
                'method' => 'POST',
                'header' => ['Content-type: application/x-www-form-urlencoded'],
                'timeout' => 3,
            ],
        ];
        if (count($packages) > 0) {
            $content = ['packages' => []];
            foreach ($packages as $package) {
                $content['packages'][] = $package->getName();
            }
            $options['http']['content'] = http_build_query($content);
        }
        return $options;
    }

    /**
     * @param string[][][] $advisories
     * @param BasePackage[] $packages
     * @return string[][][]
     */
    private function filterAdvisories(array $advisories, array $packages): array
    {
        $filteredAdvisories = [];
        foreach ($packages as $package) {
            if (array_key_exists($package->getName(), $advisories)) {
                foreach ($advisories[$package->getName()] as $advisory) {
                    if (Semver::satisfies($package->getVersion(), $advisory['affectedVersions'])) {
                        $filteredAdvisories[$package->getName()][] = $advisory;
                    }
                }
            }
        }
        return $filteredAdvisories;
    }

    /**
     * @param string[][][] $advisories
     * @return integer
     */
    private function countAdvisories(array $advisories): int
    {
        $count = 0;
        foreach ($advisories as $packageAdvisories) {
            $count += count($packageAdvisories);
        }
        return $count;
    }

    /**
     * @param IOInterface $io
     * @param string[][][] $advisories
     * @return void
     */
    private function outputAdvisories(IOInterface $io, array $advisories): void
    {
        if ($this->format === self::FORMAT_TABLE) {
            if (!($io instanceof ConsoleIO)) {
                throw new InvalidArgumentException('Cannot use table format with ' . get_class($io));
            }
            $this->outputAvisoriesTable($io, $advisories);
        } else {
            $this->outputAdvisoriesPlain($io, $advisories);
        }
    }

    /**
     * @param ConsoleIO $io
     * @param string[][][] $advisories
     * @return void
     */
    private function outputAvisoriesTable(ConsoleIO $io, array $advisories): void
    {
        foreach ($advisories as $package => $packageAdvisories) {
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
                        $package,
                        $advisory['cve'] ?: 'NO CVE',
                        $advisory['title'],
                        $advisory['link'],
                        $advisory['affectedVersions'],
                        $advisory['reportedAt'],
                    ])
                    ->setColumnWidth(1, 80)
                    ->setColumnMaxWidth(1, 80)
                    ->render();
            }
        }
    }

    /**
     * @param IOInterface $io
     * @param string[][][] $advisories
     * @return void
     */
    private function outputAdvisoriesPlain(IOInterface $io, array $advisories): void
    {
        $error = [];
        $firstAdvisory = true;
        foreach ($advisories as $package => $packageAdvisories) {
            foreach ($packageAdvisories as $advisory) {
                if (!$firstAdvisory) {
                    $error[] = '--------';
                }
                $cve = $advisory['cve'] ?: 'NO CVE';
                $error[] = "Package: $package";
                $error[] = "CVE: $cve";
                $error[] = "Title: {$advisory['title']}";
                $error[] = "URL: {$advisory['link']}";
                $error[] = "Affected versions: {$advisory['affectedVersions']}";
                $error[] = "Reported at: {$advisory['reportedAt']}";
                $firstAdvisory = false;
            }
        }
        $io->writeError($error);
    }
}
