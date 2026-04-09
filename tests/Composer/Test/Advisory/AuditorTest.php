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

use Composer\Advisory\PartialSecurityAdvisory;
use Composer\Advisory\SecurityAdvisory;
use Composer\FilterList\FilterListConfig;
use Composer\FilterList\FilterListEntry;
use Composer\FilterList\FilterListProvider\FilterListProviderSet;
use Composer\IO\BufferIO;
use Composer\Package\CompletePackage;
use Composer\Package\Package;
use Composer\Package\Version\VersionParser;
use Composer\Repository\ComposerRepository;
use Composer\Repository\RepositorySet;
use Composer\Semver\Constraint\Constraint;
use Composer\Test\TestCase;
use Composer\Advisory\Auditor;
use DateTimeImmutable;
use InvalidArgumentException;

class AuditorTest extends TestCase
{
    public static function auditProvider()
    {
        yield 'Test no advisories returns 0' => [
            'data' => [
                'packages' => [
                    new Package('vendor1/package2', '9.0.0', '9.0.0'),
                    new Package('vendor1/package1', '9.0.0', '9.0.0'),
                    new Package('vendor3/package1', '9.0.0', '9.0.0'),
                ],
                'warningOnly' => true,
            ],
            'expected' => Auditor::STATUS_OK,
            'output' => 'No security vulnerability advisories found.',
        ];

        yield 'Test with advisories returns 1' => [
            'data' => [
                'packages' => [
                    new Package('vendor1/package2', '9.0.0', '9.0.0'),
                    new Package('vendor1/package1', '8.2.1', '8.2.1'),
                    new Package('vendor3/package1', '9.0.0', '9.0.0'),
                ],
                'warningOnly' => true,
            ],
            'expected' => Auditor::STATUS_VULNERABLE,
            'output' => '<warning>Found 2 security vulnerability advisories affecting 1 package:</warning>
Package: vendor1/package1
Severity: high
Advisory ID: ID4
CVE: CVE3
Title: advisory4
URL: https://advisory.example.com/advisory4
Affected versions: >=8,<8.2.2|>=1,<2.5.6
Reported at: 2022-05-25T13:21:00+00:00
--------
Package: vendor1/package1
Severity: medium
Advisory ID: ID5
CVE: '.'
Title: advisory5
URL: https://advisory.example.com/advisory5
Affected versions: >=8,<8.2.2|>=1,<2.5.6
Reported at: 2022-05-25T13:21:00+00:00',
        ];

        $abandonedWithReplacement = new CompletePackage('vendor/abandoned', '1.0.0', '1.0.0');
        $abandonedWithReplacement->setAbandoned('foo/bar');
        $abandonedNoReplacement = new CompletePackage('vendor/abandoned2', '1.0.0', '1.0.0');
        $abandonedNoReplacement->setAbandoned(true);

        yield 'abandoned packages ignored' => [
            'data' => [
                'packages' => [
                    $abandonedWithReplacement,
                    $abandonedNoReplacement,
                ],
                'warningOnly' => false,
                'abandoned' => Auditor::ABANDONED_IGNORE,
            ],
            'expected' => Auditor::STATUS_OK,
            'output' => 'No security vulnerability advisories found.',
        ];

        yield 'abandoned packages individually ignored via full vendor' => [
            'data' => [
                'packages' => [
                    $abandonedWithReplacement,
                    $abandonedNoReplacement,
                ],
                'warningOnly' => false,
                'abandoned' => Auditor::ABANDONED_FAIL,
                'ignore-abandoned' => ['vendor/*' => null],
            ],
            'expected' => Auditor::STATUS_OK,
            'output' => 'No security vulnerability advisories found.',
        ];

        yield 'abandoned packages individually ignored via package name' => [
            'data' => [
                'packages' => [
                    $abandonedWithReplacement,
                    $abandonedNoReplacement,
                ],
                'warningOnly' => false,
                'abandoned' => Auditor::ABANDONED_FAIL,
                'ignore-abandoned' => [$abandonedWithReplacement->getName() => null, $abandonedNoReplacement->getName() => null],
            ],
            'expected' => Auditor::STATUS_OK,
            'output' => 'No security vulnerability advisories found.',
        ];

        yield 'abandoned packages individually ignored not matching package name' => [
            'data' => [
                'packages' => [
                    $abandonedWithReplacement,
                    $abandonedNoReplacement,
                ],
                'warningOnly' => false,
                'abandoned' => Auditor::ABANDONED_FAIL,
                'ignore-abandoned' => ['acme/test' => 'ignoring because yolo'],
            ],
            'expected' => Auditor::STATUS_ABANDONED,
            'output' => 'No security vulnerability advisories found.
Found 2 abandoned packages:
vendor/abandoned is abandoned. Use foo/bar instead.
vendor/abandoned2 is abandoned. No replacement was suggested.',
        ];

        yield 'abandoned packages reported only' => [
            'data' => [
                'packages' => [
                    $abandonedWithReplacement,
                    $abandonedNoReplacement,
                ],
                'warningOnly' => true,
                'abandoned' => Auditor::ABANDONED_REPORT,
            ],
            'expected' => Auditor::STATUS_OK,
            'output' => 'No security vulnerability advisories found.
Found 2 abandoned packages:
vendor/abandoned is abandoned. Use foo/bar instead.
vendor/abandoned2 is abandoned. No replacement was suggested.',
        ];

        yield 'abandoned packages fails' => [
            'data' => [
                'packages' => [
                    $abandonedWithReplacement,
                    $abandonedNoReplacement,
                ],
                'warningOnly' => false,
                'abandoned' => Auditor::ABANDONED_FAIL,
                'format' => Auditor::FORMAT_TABLE,
            ],
            'expected' => Auditor::STATUS_ABANDONED,
            'output' => 'No security vulnerability advisories found.
Found 2 abandoned packages:
+-------------------+----------------------------------------------------------------------------------+
| Abandoned Package | Suggested Replacement                                                            |
+-------------------+----------------------------------------------------------------------------------+
| vendor/abandoned  | foo/bar                                                                          |
| vendor/abandoned2 | none                                                                             |
+-------------------+----------------------------------------------------------------------------------+',
        ];

        yield 'vulnerable and abandoned packages fails' => [
            'data' => [
                'packages' => [
                    new Package('vendor1/package1', '8.2.1', '8.2.1'),
                    $abandonedWithReplacement,
                    $abandonedNoReplacement,
                ],
                'warningOnly' => false,
                'abandoned' => Auditor::ABANDONED_FAIL,
                'format' => Auditor::FORMAT_TABLE,
            ],
            'expected' => Auditor::STATUS_VULNERABLE | Auditor::STATUS_ABANDONED,
            'output' => 'Found 2 security vulnerability advisories affecting 1 package:
+-------------------+----------------------------------------------------------------------------------+
| Package           | vendor1/package1                                                                 |
| Severity          | high                                                                             |
| Advisory ID       | ID4                                                                              |
| CVE               | CVE3                                                                             |
| Title             | advisory4                                                                        |
| URL               | https://advisory.example.com/advisory4                                           |
| Affected versions | >=8,<8.2.2|>=1,<2.5.6                                                            |
| Reported at       | 2022-05-25T13:21:00+00:00                                                        |
+-------------------+----------------------------------------------------------------------------------+
+-------------------+----------------------------------------------------------------------------------+
| Package           | vendor1/package1                                                                 |
| Severity          | medium                                                                           |
| Advisory ID       | ID5                                                                              |
| CVE               |                                                                                  |
| Title             | advisory5                                                                        |
| URL               | https://advisory.example.com/advisory5                                           |
| Affected versions | >=8,<8.2.2|>=1,<2.5.6                                                            |
| Reported at       | 2022-05-25T13:21:00+00:00                                                        |
+-------------------+----------------------------------------------------------------------------------+
Found 2 abandoned packages:
+-------------------+----------------------------------------------------------------------------------+
| Abandoned Package | Suggested Replacement                                                            |
+-------------------+----------------------------------------------------------------------------------+
| vendor/abandoned  | foo/bar                                                                          |
| vendor/abandoned2 | none                                                                             |
+-------------------+----------------------------------------------------------------------------------+',
        ];

        yield 'abandoned packages fails with json format' => [
            'data' => [
                'packages' => [
                    $abandonedWithReplacement,
                    $abandonedNoReplacement,
                ],
                'warningOnly' => false,
                'abandoned' => Auditor::ABANDONED_FAIL,
                'format' => Auditor::FORMAT_JSON,
            ],
            'expected' => Auditor::STATUS_ABANDONED,
            'output' => '{
    "advisories": [],
    "abandoned": {
        "vendor/abandoned": "foo/bar",
        "vendor/abandoned2": null
    },
    "filter": []
}',
        ];
    }

    /**
     * @dataProvider auditProvider
     * @phpstan-param array<string, mixed> $data
     */
    public function testAudit(array $data, int $expected, string $output): void
    {
        if (count($data['packages']) === 0) {
            $this->expectException(InvalidArgumentException::class);
        }
        $auditor = new Auditor();
        $result = $auditor->audit($io = new BufferIO(), $this->getRepoSet(), $data['packages'], $data['format'] ?? Auditor::FORMAT_PLAIN, $data['warningOnly'], [], $data['abandoned'] ?? Auditor::ABANDONED_IGNORE, [], false, $data['ignore-abandoned'] ?? []);
        self::assertSame($expected, $result);
        self::assertSame($output, trim(str_replace("\r", '', $io->getOutput())));
    }

    public function ignoredIdsProvider(): \Generator
    {
        yield 'ignore by CVE' => [
            [
                new Package('vendor1/package1', '3.0.0.0', '3.0.0'),
            ],
            ['CVE1' => null],
            0,
            [
                ['text' => 'Found 1 ignored security vulnerability advisory affecting 1 package:'],
                ['text' => 'Package: vendor1/package1'],
                ['text' => 'Severity: medium'],
                ['text' => 'Advisory ID: ID1'],
                ['text' => 'CVE: CVE1'],
                ['text' => 'Title: advisory1'],
                ['text' => 'URL: https://advisory.example.com/advisory1'],
                ['text' => 'Affected versions: >=3,<3.4.3|>=1,<2.5.6'],
                ['text' => 'Reported at: 2022-05-25T13:21:00+00:00'],
            ],
        ];
        yield 'ignore by CVE with reasoning' => [
            [
                new Package('vendor1/package1', '3.0.0.0', '3.0.0'),
            ],
            ['CVE1' => 'A good reason'],
            0,
            [
                ['text' => 'Found 1 ignored security vulnerability advisory affecting 1 package:'],
                ['text' => 'Package: vendor1/package1'],
                ['text' => 'Severity: medium'],
                ['text' => 'Advisory ID: ID1'],
                ['text' => 'CVE: CVE1'],
                ['text' => 'Title: advisory1'],
                ['text' => 'URL: https://advisory.example.com/advisory1'],
                ['text' => 'Affected versions: >=3,<3.4.3|>=1,<2.5.6'],
                ['text' => 'Reported at: 2022-05-25T13:21:00+00:00'],
                ['text' => 'Ignore reason: A good reason'],
            ],
        ];
        yield 'ignore by advisory id' => [
            [
                new Package('vendor1/package2', '3.0.0.0', '3.0.0'),
            ],
            ['ID2' => null],
            0,
            [
                ['text' => 'Found 1 ignored security vulnerability advisory affecting 1 package:'],
                ['text' => 'Package: vendor1/package2'],
                ['text' => 'Severity: medium'],
                ['text' => 'Advisory ID: ID2'],
                ['text' => 'CVE: '],
                ['text' => 'Title: advisory2'],
                ['text' => 'URL: https://advisory.example.com/advisory2'],
                ['text' => 'Affected versions: >=3,<3.4.3|>=1,<2.5.6'],
                ['text' => 'Reported at: 2022-05-25T13:21:00+00:00'],
            ],
        ];
        yield 'ignore by remote id' => [
            [
                new Package('vendorx/packagex', '3.0.0.0', '3.0.0'),
            ],
            ['RemoteIDx' => null],
            0,
            [
                ['text' => 'Found 1 ignored security vulnerability advisory affecting 1 package:'],
                ['text' => 'Package: vendorx/packagex'],
                ['text' => 'Severity: medium'],
                ['text' => 'Advisory ID: IDx'],
                ['text' => 'CVE: CVE5'],
                ['text' => 'Title: advisory17'],
                ['text' => 'URL: https://advisory.example.com/advisory17'],
                ['text' => 'Affected versions: >=3,<3.4.3|>=1,<2.5.6'],
                ['text' => 'Reported at: 2015-05-25T13:21:00+00:00'],
            ],
        ];
        yield 'ignore by package name' => [
            [
                new Package('vendor1/package1', '3.0.0.0', '3.0.0'),
            ],
            ['vendor1/package1' => null],
            0,
            [
                ['text' => 'Found 1 ignored security vulnerability advisory affecting 1 package:'],
                ['text' => 'Package: vendor1/package1'],
                ['text' => 'Severity: medium'],
                ['text' => 'Advisory ID: ID1'],
                ['text' => 'CVE: CVE1'],
                ['text' => 'Title: advisory1'],
                ['text' => 'URL: https://advisory.example.com/advisory1'],
                ['text' => 'Affected versions: >=3,<3.4.3|>=1,<2.5.6'],
                ['text' => 'Reported at: 2022-05-25T13:21:00+00:00'],
            ],
        ];
        yield 'ignore by package name with reasoning' => [
            [
                new Package('vendor1/package1', '3.0.0.0', '3.0.0'),
            ],
            ['vendor1/package1' => 'Package has known safe usage'],
            0,
            [
                ['text' => 'Found 1 ignored security vulnerability advisory affecting 1 package:'],
                ['text' => 'Package: vendor1/package1'],
                ['text' => 'Severity: medium'],
                ['text' => 'Advisory ID: ID1'],
                ['text' => 'CVE: CVE1'],
                ['text' => 'Title: advisory1'],
                ['text' => 'URL: https://advisory.example.com/advisory1'],
                ['text' => 'Affected versions: >=3,<3.4.3|>=1,<2.5.6'],
                ['text' => 'Reported at: 2022-05-25T13:21:00+00:00'],
                ['text' => 'Ignore reason: Package has known safe usage'],
            ],
        ];
        yield '1 vulnerability, 0 ignored' => [
            [
                new Package('vendor1/package1', '3.0.0.0', '3.0.0'),
            ],
            [],
            1,
            [
                ['text' => 'Found 1 security vulnerability advisory affecting 1 package:'],
                ['text' => 'Package: vendor1/package1'],
                ['text' => 'Severity: medium'],
                ['text' => 'Advisory ID: ID1'],
                ['text' => 'CVE: CVE1'],
                ['text' => 'Title: advisory1'],
                ['text' => 'URL: https://advisory.example.com/advisory1'],
                ['text' => 'Affected versions: >=3,<3.4.3|>=1,<2.5.6'],
                ['text' => 'Reported at: 2022-05-25T13:21:00+00:00'],
            ],
        ];
        yield '1 vulnerability, 3 ignored affecting 2 packages' => [
            [
                new Package('vendor3/package1', '3.0.0.0', '3.0.0'),
                // RemoteIDx
                new Package('vendorx/packagex', '3.0.0.0', '3.0.0'),
                // ID3, ID6
                new Package('vendor2/package1', '3.0.0.0', '3.0.0'),
            ],
            ['RemoteIDx' => null, 'ID3' => null, 'ID6' => null],
            1,
            [
                ['text' => 'Found 3 ignored security vulnerability advisories affecting 2 packages:'],
                ['text' => 'Package: vendor2/package1'],
                ['text' => 'Severity: medium'],
                ['text' => 'Advisory ID: ID3'],
                ['text' => 'CVE: CVE2'],
                ['text' => 'Title: advisory3'],
                ['text' => 'URL: https://advisory.example.com/advisory3'],
                ['text' => 'Affected versions: >=3,<3.4.3|>=1,<2.5.6'],
                ['text' => 'Reported at: 2022-05-25T13:21:00+00:00'],
                ['text' => 'Ignore reason: None specified'],
                ['text' => '--------'],
                ['text' => 'Package: vendor2/package1'],
                ['text' => 'Severity: medium'],
                ['text' => 'Advisory ID: ID6'],
                ['text' => 'CVE: CVE4'],
                ['text' => 'Title: advisory6'],
                ['text' => 'URL: https://advisory.example.com/advisory6'],
                ['text' => 'Affected versions: >=3,<3.4.3|>=1,<2.5.6'],
                ['text' => 'Reported at: 2015-05-25T13:21:00+00:00'],
                ['text' => 'Ignore reason: None specified'],
                ['text' => '--------'],
                ['text' => 'Package: vendorx/packagex'],
                ['text' => 'Severity: medium'],
                ['text' => 'Advisory ID: IDx'],
                ['text' => 'CVE: CVE5'],
                ['text' => 'Title: advisory17'],
                ['text' => 'URL: https://advisory.example.com/advisory17'],
                ['text' => 'Affected versions: >=3,<3.4.3|>=1,<2.5.6'],
                ['text' => 'Reported at: 2015-05-25T13:21:00+00:00'],
                ['text' => 'Ignore reason: None specified'],
                ['text' => 'Found 1 security vulnerability advisory affecting 1 package:'],
                ['text' => 'Package: vendor3/package1'],
                ['text' => 'Severity: medium'],
                ['text' => 'Advisory ID: ID7'],
                ['text' => 'CVE: CVE5'],
                ['text' => 'Title: advisory7'],
                ['text' => 'URL: https://advisory.example.com/advisory7'],
                ['text' => 'Affected versions: >=3,<3.4.3|>=1,<2.5.6'],
                ['text' => 'Reported at: 2015-05-25T13:21:00+00:00'],
            ],
        ];
    }

    /**
     * @dataProvider ignoredIdsProvider
     * @phpstan-param array<Package> $packages
     * @phpstan-param array<string>|array<string,string> $ignoredIds
     * @phpstan-param 0|positive-int $exitCode
     * @phpstan-param list<array{text: string, verbosity?: \Composer\IO\IOInterface::*, regex?: true}|array{ask: string, reply: string}|array{auth: array{string, string, string|null}}> $expectedOutput
     */
    public function testAuditWithIgnore($packages, $ignoredIds, $exitCode, $expectedOutput): void
    {
        $auditor = new Auditor();
        $result = $auditor->audit($io = $this->getIOMock(), $this->getRepoSet(), $packages, Auditor::FORMAT_PLAIN, false, $ignoredIds);
        $io->expects($expectedOutput, true);
        self::assertSame($exitCode, $result);
    }

    public function ignoreSeverityProvider(): \Generator
    {
        yield 'ignore medium' => [
            [
                new Package('vendor1/package1', '2.0.0.0', '2.0.0'),
            ],
            ['medium' => null],
            1,
            [
                ['text' => 'Found 2 ignored security vulnerability advisories affecting 1 package:'],
            ],
        ];
        yield 'ignore high' => [
            [
                new Package('vendor1/package1', '2.0.0.0', '2.0.0'),
            ],
            ['high' => null],
            1,
            [
                ['text' => 'Found 1 ignored security vulnerability advisory affecting 1 package:'],
            ],
        ];
        yield 'ignore high and medium' => [
            [
                new Package('vendor1/package1', '2.0.0.0', '2.0.0'),
            ],
            ['high' => null, 'medium' => null],
            0,
            [
                ['text' => 'Found 3 ignored security vulnerability advisories affecting 1 package:'],
            ],
        ];
    }

    public function testAuditWithIgnoreUnreachable(): void
    {
        $packages = [
            new Package('vendor1/package1', '3.0.0.0', '3.0.0'),
        ];

        $errorMessage = 'The "https://example.org/packages.json" file could not be downloaded: HTTP/1.1 404 Not Found';

        // Create a mock RepositorySet that simulates multiple repositories with the middle one being unreachable
        $repoSet = $this->getMockBuilder(RepositorySet::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getMatchingSecurityAdvisories'])
            ->getMock();

        $repoSet->method('getMatchingSecurityAdvisories')
            ->willReturnCallback(static function ($packages, $allowPartialAdvisories, $ignoreUnreachable) use ($errorMessage) {
                if (!$ignoreUnreachable) {
                    throw new \Composer\Downloader\TransportException($errorMessage, 404);
                }

                // Simulate multiple repositories with the middle one being unreachable
                // First and third repositories have advisories, middle one is unreachable
                return [
                    'advisories' => [
                        'vendor1/package1' => [
                            new SecurityAdvisory(
                                'vendor1/package1',
                                'CVE-2023-12345',
                                new Constraint('=', '3.0.0.0'),
                                'First repo advisory',
                                [['name' => 'test', 'remoteId' => '1']],
                                new DateTimeImmutable('2023-01-01', new \DateTimeZone('UTC')),
                                'CVE-2023-12345',
                                'https://example.com/advisory/1',
                                'medium'
                            ),
                            new SecurityAdvisory(
                                'vendor1/package1',
                                'CVE-2023-67890',
                                new Constraint('=', '3.0.0.0'),
                                'Third repo advisory',
                                [['name' => 'test', 'remoteId' => '3']],
                                new DateTimeImmutable('2023-01-01', new \DateTimeZone('UTC')),
                                'CVE-2023-67890',
                                'https://example.com/advisory/3',
                                'high'
                            ),
                        ],
                    ],
                    'unreachableRepos' => [$errorMessage],
                ];
            });

        $auditor = new Auditor();

        // Test without ignoreUnreachable flag
        try {
            $auditor->audit(new BufferIO(), $repoSet, $packages, Auditor::FORMAT_PLAIN, false);
            self::fail('Expected TransportException was not thrown');
        } catch (\Composer\Downloader\TransportException $e) {
            self::assertStringContainsString('HTTP/1.1 404 Not Found', $e->getMessage());
        }

        // Test with ignoreUnreachable flag
        $io = new BufferIO();
        $result = $auditor->audit($io, $repoSet, $packages, Auditor::FORMAT_PLAIN, false, [], Auditor::ABANDONED_IGNORE, [], true);

        // Should find advisories from the reachable repositories
        self::assertSame(Auditor::STATUS_VULNERABLE, $result);

        $output = $io->getOutput();
        self::assertStringContainsString('The following repositories were unreachable:', $output);
        self::assertStringContainsString('HTTP/1.1 404 Not Found', $output);

        // Verify that advisories from reachable repositories were found
        self::assertStringContainsString('First repo advisory', $output);
        self::assertStringContainsString('Third repo advisory', $output);
        self::assertStringContainsString('CVE-2023-12345', $output);
        self::assertStringContainsString('CVE-2023-67890', $output);

        // Test with JSON format
        $io = new BufferIO();
        $result = $auditor->audit($io, $repoSet, $packages, Auditor::FORMAT_JSON, false, [], Auditor::ABANDONED_IGNORE, [], true);
        self::assertSame(Auditor::STATUS_VULNERABLE, $result);

        $json = json_decode($io->getOutput(), true);
        self::assertArrayHasKey('unreachable-repositories', $json);
        self::assertCount(1, $json['unreachable-repositories']);
        self::assertStringContainsString('HTTP/1.1 404 Not Found', $json['unreachable-repositories'][0]);

        // Verify that advisories from reachable repositories were included in JSON output
        self::assertArrayHasKey('advisories', $json);
        self::assertArrayHasKey('vendor1/package1', $json['advisories']);
        self::assertCount(2, $json['advisories']['vendor1/package1']);

        // Check first advisory
        self::assertSame('CVE-2023-12345', $json['advisories']['vendor1/package1'][0]['cve']);
        self::assertSame('First repo advisory', $json['advisories']['vendor1/package1'][0]['title']);

        // Check second advisory
        self::assertSame('CVE-2023-67890', $json['advisories']['vendor1/package1'][1]['cve']);
        self::assertSame('Third repo advisory', $json['advisories']['vendor1/package1'][1]['title']);
    }

    /**
     * @dataProvider ignoreSeverityProvider
     * @phpstan-param array<Package> $packages
     * @phpstan-param array<string> $ignoredSeverities
     * @phpstan-param 0|positive-int $exitCode
     * @phpstan-param list<array{text: string, verbosity?: \Composer\IO\IOInterface::*, regex?: true}|array{ask: string, reply: string}|array{auth: array{string, string, string|null}}> $expectedOutput
     */
    public function testAuditWithIgnoreSeverity($packages, $ignoredSeverities, $exitCode, $expectedOutput): void
    {
        $auditor = new Auditor();
        $result = $auditor->audit($io = $this->getIOMock(), $this->getRepoSet(), $packages, Auditor::FORMAT_PLAIN, false, [], Auditor::ABANDONED_IGNORE, $ignoredSeverities);
        $io->expects($expectedOutput, true);
        self::assertSame($exitCode, $result);
    }

    public static function filteredProvider(): \Generator
    {
        $matchingEntry = new FilterListEntry(
            'vendor/package',
            new Constraint('>=', '8.0.0.0'),
            'test-list',
            null,
            'internal',
            'ID-test-1'
        );
        $matchingEntryWithDetails = new FilterListEntry(
            'vendor/package',
            new Constraint('>=', '8.0.0.0'),
            'test-list',
            'https://example.com/filtered',
            'internal',
            'ID-test-1'
        );
        $nonMatchingEntry = new FilterListEntry(
            'vendor/package',
            new Constraint('>=', '10.0.0.0'),
            'test-list',
            null,
            'internal',
            'ID-test-1'
        );

        yield 'FILTERED_IGNORE skips filter processing' => [
            'packages' => [new Package('vendor/package', '9.0.0', '9.0.0')],
            'filterEntriesByList' => ['test-list' => [$matchingEntry]],
            'filtered' => Auditor::FILTERED_IGNORE,
            'format' => Auditor::FORMAT_PLAIN,
            'expected' => Auditor::STATUS_OK,
            'output' => 'No security vulnerability advisories found.',
        ];

        yield 'FILTERED_FAIL with no matching entry returns STATUS_OK' => [
            'packages' => [new Package('vendor/package', '9.0.0', '9.0.0')],
            'filterEntriesByList' => ['test-list' => [$nonMatchingEntry]],
            'filtered' => Auditor::FILTERED_FAIL,
            'format' => Auditor::FORMAT_PLAIN,
            'expected' => Auditor::STATUS_OK,
            'output' => 'No security vulnerability advisories found.',
        ];

        yield 'FILTERED_FAIL with matching entry returns STATUS_FILTERED (plain)' => [
            'packages' => [new Package('vendor/package', '9.0.0', '9.0.0')],
            'filterEntriesByList' => ['test-list' => [$matchingEntry]],
            'filtered' => Auditor::FILTERED_FAIL,
            'format' => Auditor::FORMAT_PLAIN,
            'expected' => Auditor::STATUS_FILTERED,
            'output' => 'No security vulnerability advisories found.
Found 1 package matching filters:
vendor/package is on filter list "test-list". Reason: internal.',
        ];

        yield 'FILTERED_FAIL with matching entry shows url and reason (plain)' => [
            'packages' => [new Package('vendor/package', '9.0.0', '9.0.0')],
            'filterEntriesByList' => ['test-list' => [$matchingEntryWithDetails]],
            'filtered' => Auditor::FILTERED_FAIL,
            'format' => Auditor::FORMAT_PLAIN,
            'expected' => Auditor::STATUS_FILTERED,
            'output' => 'No security vulnerability advisories found.
Found 1 package matching filters:
vendor/package is on filter list "test-list". Reason: internal. URL: https://example.com/filtered.',
        ];

        yield 'FILTERED_REPORT with matching entry returns STATUS_OK' => [
            'packages' => [new Package('vendor/package', '9.0.0', '9.0.0')],
            'filterEntriesByList' => ['test-list' => [$matchingEntry]],
            'filtered' => Auditor::FILTERED_REPORT,
            'format' => Auditor::FORMAT_PLAIN,
            'expected' => Auditor::STATUS_OK,
            'output' => 'No security vulnerability advisories found.
Found 1 package matching filters:
vendor/package is on filter list "test-list". Reason: internal.',
        ];

        yield 'FILTERED_FAIL with matching entry shows summary line only (summary format)' => [
            'packages' => [new Package('vendor/package', '9.0.0', '9.0.0')],
            'filterEntriesByList' => ['test-list' => [$matchingEntry]],
            'filtered' => Auditor::FILTERED_FAIL,
            'format' => Auditor::FORMAT_SUMMARY,
            'expected' => Auditor::STATUS_FILTERED,
            'output' => 'No security vulnerability advisories found.
Found 1 package matching filters.',
        ];

        yield 'FILTERED_FAIL with multiple matching packages shows plural form' => [
            'packages' => [
                new Package('vendor/package', '9.0.0.0', '9.0.0'),
                new Package('vendor/other', '1.0.0.0', '10.0.0'),
            ],
            'filterEntriesByList' => [
                'test-list' => [
                    $matchingEntry,
                    new FilterListEntry('vendor/other', new Constraint('>=', '1.0.0.0'), 'test-list', null, 'internal', 'ID-TEST'),
                ],
            ],
            'filtered' => Auditor::FILTERED_FAIL,
            'format' => Auditor::FORMAT_PLAIN,
            'expected' => Auditor::STATUS_FILTERED,
            'output' => 'No security vulnerability advisories found.
Found 2 packages matching filters:
vendor/package is on filter list "test-list". Reason: internal.
vendor/other is on filter list "test-list". Reason: internal.',
        ];
    }

    /**
     * @dataProvider filteredProvider
     * @param array<Package> $packages
     * @param array<string, list<FilterListEntry>> $filterEntriesByList
     * @param Auditor::FILTERED_* $filtered
     * @param 'json'|'plain'|'summary'|'table' $format
     */
    public function testAuditWithFilter(array $packages, array $filterEntriesByList, string $filtered, string $format, int $expected, string $output): void
    {
        $providerSet = $this->getMockBuilder(FilterListProviderSet::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getMatchingFilterLists'])
            ->getMock();

        $providerSet
            ->method('getMatchingFilterLists')
            ->willReturn(['filter' => $filterEntriesByList, 'unreachableRepos' => []]);

        $filterListConfig = new FilterListConfig([], [], false);

        $auditor = new Auditor();
        $result = $auditor->audit(
            $io = new BufferIO(),
            $this->getRepoSet(),
            $packages,
            $format,
            true,
            [],
            Auditor::ABANDONED_IGNORE,
            [],
            false,
            [],
            $filtered,
            $providerSet,
            $filterListConfig
        );

        self::assertSame($expected, $result);
        self::assertSame($output, trim(str_replace("\r", '', $io->getOutput())));
    }

    public function testAuditWithFilterJson(): void
    {
        $matchingEntry = new FilterListEntry(
            'vendor/package',
            new Constraint('>=', '8.0.0.0'),
            'test-list',
            'https://example.com/filtered',
            'Some reason',
            'ID-test'
        );

        $providerSet = $this->getMockBuilder(FilterListProviderSet::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getMatchingFilterLists'])
            ->getMock();

        $providerSet->expects($this->once())
            ->method('getMatchingFilterLists')
            ->willReturn(['filter' => ['test-list' => [$matchingEntry]], 'unreachableRepos' => []]);

        $filterListConfig = new FilterListConfig([], [], false);

        $auditor = new Auditor();
        $result = $auditor->audit(
            $io = new BufferIO(),
            $this->getRepoSet(),
            [new Package('vendor/package', '9.0.0', '9.0.0')],
            Auditor::FORMAT_JSON,
            true,
            [],
            Auditor::ABANDONED_IGNORE,
            [],
            false,
            [],
            Auditor::FILTERED_FAIL,
            $providerSet,
            $filterListConfig
        );

        self::assertSame(Auditor::STATUS_FILTERED, $result);

        $json = json_decode($io->getOutput(), true);
        self::assertIsArray($json);
        self::assertArrayHasKey('filter', $json);
        self::assertArrayHasKey('vendor/package', $json['filter']);
        self::assertCount(1, $json['filter']['vendor/package']);

        $entry = $json['filter']['vendor/package'][0];
        self::assertSame('vendor/package', $entry['packageName']);
        self::assertSame('test-list', $entry['listName']);
        self::assertSame('https://example.com/filtered', $entry['url']);
        self::assertSame('Some reason', $entry['reason']);
        self::assertIsString($entry['constraint']);
    }

    public function testAuditWithFilterAndVulnerabilities(): void
    {
        $matchingEntry = new FilterListEntry(
            'vendor1/package2',
            new Constraint('>=', '8.0.0.0'),
            'test-list',
            null,
            'internal',
            'ID-test'
        );

        $providerSet = $this->getMockBuilder(FilterListProviderSet::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getMatchingFilterLists'])
            ->getMock();

        $providerSet->expects($this->once())
            ->method('getMatchingFilterLists')
            ->willReturn(['filter' => ['test-list' => [$matchingEntry]], 'unreachableRepos' => []]);

        $filterListConfig = new FilterListConfig([], [], false);

        $auditor = new Auditor();
        // vendor1/package1 at 8.2.1 is vulnerable; vendor1/package2 at 9.0.0 matches the filter
        $result = $auditor->audit(
            $io = new BufferIO(),
            $this->getRepoSet(),
            [
                new Package('vendor1/package1', '8.2.1', '8.2.1'),
                new Package('vendor1/package2', '9.0.0', '9.0.0'),
            ],
            Auditor::FORMAT_PLAIN,
            false,
            [],
            Auditor::ABANDONED_IGNORE,
            [],
            false,
            [],
            Auditor::FILTERED_FAIL,
            $providerSet,
            $filterListConfig
        );

        self::assertSame(Auditor::STATUS_VULNERABLE | Auditor::STATUS_FILTERED, $result);
        $output = trim(str_replace("\r", '', $io->getOutput()));
        self::assertStringContainsString('Found 2 security vulnerability advisories affecting 1 package:', $output);
        self::assertStringContainsString('Found 1 package matching filters:', $output);
        self::assertStringContainsString('vendor1/package2 is on filter list "test-list". Reason: internal', $output);
    }

    private function getRepoSet(): RepositorySet
    {
        $repo = $this
            ->getMockBuilder(ComposerRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['hasSecurityAdvisories', 'getSecurityAdvisories'])
            ->getMock();

        $repoSet = new RepositorySet();
        $repoSet->addRepository($repo);

        $repo
            ->method('hasSecurityAdvisories')
            ->willReturn(true);

        $repo
            ->method('getSecurityAdvisories')
            ->willReturnCallback(static function (array $packageConstraintMap, bool $allowPartialAdvisories) {
                $advisories = [];

                $parser = new VersionParser();
                /**
                 * @param array<mixed> $data
                 * @param string $name
                 * @return ($allowPartialAdvisories is false ? SecurityAdvisory|null : PartialSecurityAdvisory|SecurityAdvisory|null)
                 */
                $create = static function (array $data, string $name) use ($parser, $allowPartialAdvisories, $packageConstraintMap): ?PartialSecurityAdvisory {
                    $advisory = PartialSecurityAdvisory::create($name, $data, $parser);
                    if (!$allowPartialAdvisories && !$advisory instanceof SecurityAdvisory) {
                        throw new \RuntimeException('Advisory for '.$name.' could not be loaded as a full advisory from test repo');
                    }
                    if (!$advisory->affectedVersions->matches($packageConstraintMap[$name])) {
                        return null;
                    }

                    return $advisory;
                };

                foreach (self::getMockAdvisories() as $package => $list) {
                    if (!isset($packageConstraintMap[$package])) {
                        continue;
                    }
                    $advisories[$package] = array_filter(array_map(
                        static function ($data) use ($package, $create) {
                            return $create($data, $package);
                        },
                        $list
                    ));
                }

                return ['namesFound' => array_keys($packageConstraintMap), 'advisories' => array_filter($advisories)];
            });

        return $repoSet;
    }

    /**
     * @return array<mixed>
     */
    public static function getMockAdvisories(): array
    {
        $advisories = [
            'vendor1/package1' => [
                [
                    'advisoryId' => 'ID1',
                    'packageName' => 'vendor1/package1',
                    'title' => 'advisory1',
                    'link' => 'https://advisory.example.com/advisory1',
                    'cve' => 'CVE1',
                    'affectedVersions' => '>=3,<3.4.3|>=1,<2.5.6',
                    'sources' => [
                        [
                            'name' => 'source1',
                            'remoteId' => 'RemoteID1',
                        ],
                    ],
                    'reportedAt' => '2022-05-25 13:21:00',
                    'composerRepository' => 'https://packagist.org',
                    'severity' => 'medium',
                ],
                [
                    'advisoryId' => 'ID4',
                    'packageName' => 'vendor1/package1',
                    'title' => 'advisory4',
                    'link' => 'https://advisory.example.com/advisory4',
                    'cve' => 'CVE3',
                    'affectedVersions' => '>=8,<8.2.2|>=1,<2.5.6',
                    'sources' => [
                        [
                            'name' => 'source2',
                            'remoteId' => 'RemoteID4',
                        ],
                    ],
                    'reportedAt' => '2022-05-25 13:21:00',
                    'composerRepository' => 'https://packagist.org',
                    'severity' => 'high',
                ],
                [
                    'advisoryId' => 'ID5',
                    'packageName' => 'vendor1/package1',
                    'title' => 'advisory5',
                    'link' => 'https://advisory.example.com/advisory5',
                    'cve' => '',
                    'affectedVersions' => '>=8,<8.2.2|>=1,<2.5.6',
                    'sources' => [
                        [
                            'name' => 'source1',
                            'remoteId' => 'RemoteID3',
                        ],
                    ],
                    'reportedAt' => '2022-05-25 13:21:00',
                    'composerRepository' => 'https://packagist.org',
                    'severity' => 'medium',
                ],
            ],
            'vendor1/package2' => [
                [
                    'advisoryId' => 'ID2',
                    'packageName' => 'vendor1/package2',
                    'title' => 'advisory2',
                    'link' => 'https://advisory.example.com/advisory2',
                    'cve' => '',
                    'affectedVersions' => '>=3,<3.4.3|>=1,<2.5.6',
                    'sources' => [
                        [
                            'name' => 'source1',
                            'remoteId' => 'RemoteID2',
                        ],
                    ],
                    'reportedAt' => '2022-05-25 13:21:00',
                    'composerRepository' => 'https://packagist.org',
                    'severity' => 'medium',
                ],
            ],
            'vendorx/packagex' => [
                [
                    'advisoryId' => 'IDx',
                    'packageName' => 'vendorx/packagex',
                    'title' => 'advisory17',
                    'link' => 'https://advisory.example.com/advisory17',
                    'cve' => 'CVE5',
                    'affectedVersions' => '>=3,<3.4.3|>=1,<2.5.6',
                    'sources' => [
                        [
                            'name' => 'source2',
                            'remoteId' => 'RemoteIDx',
                        ],
                    ],
                    'reportedAt' => '2015-05-25 13:21:00',
                    'composerRepository' => 'https://packagist.org',
                    'severity' => 'medium',
                ],
            ],
            'vendor2/package1' => [
                [
                    'advisoryId' => 'ID3',
                    'packageName' => 'vendor2/package1',
                    'title' => 'advisory3',
                    'link' => 'https://advisory.example.com/advisory3',
                    'cve' => 'CVE2',
                    'affectedVersions' => '>=3,<3.4.3|>=1,<2.5.6',
                    'sources' => [
                        [
                            'name' => 'source2',
                            'remoteId' => 'RemoteID1',
                        ],
                    ],
                    'reportedAt' => '2022-05-25 13:21:00',
                    'composerRepository' => 'https://packagist.org',
                    'severity' => 'medium',
                ],
                [
                    'advisoryId' => 'ID6',
                    'packageName' => 'vendor2/package1',
                    'title' => 'advisory6',
                    'link' => 'https://advisory.example.com/advisory6',
                    'cve' => 'CVE4',
                    'affectedVersions' => '>=3,<3.4.3|>=1,<2.5.6',
                    'sources' => [
                        [
                            'name' => 'source2',
                            'remoteId' => 'RemoteID3',
                        ],
                    ],
                    'reportedAt' => '2015-05-25 13:21:00',
                    'composerRepository' => 'https://packagist.org',
                    'severity' => 'medium',
                ],
            ],
            'vendory/packagey' => [
                [
                    'advisoryId' => 'IDy',
                    'packageName' => 'vendory/packagey',
                    'title' => 'advisory7',
                    'link' => 'https://advisory.example.com/advisory7',
                    'cve' => 'CVE5',
                    'affectedVersions' => '>=3,<3.4.3|>=1,<2.5.6',
                    'sources' => [
                        [
                            'name' => 'source2',
                            'remoteId' => 'RemoteID4',
                        ],
                    ],
                    'reportedAt' => '2015-05-25 13:21:00',
                    'composerRepository' => 'https://packagist.org',
                    'severity' => 'medium',
                ],
            ],
            'vendor3/package1' => [
                [
                    'advisoryId' => 'ID7',
                    'packageName' => 'vendor3/package1',
                    'title' => 'advisory7',
                    'link' => 'https://advisory.example.com/advisory7',
                    'cve' => 'CVE5',
                    'affectedVersions' => '>=3,<3.4.3|>=1,<2.5.6',
                    'sources' => [
                        [
                            'name' => 'source2',
                            'remoteId' => 'RemoteID4',
                        ],
                    ],
                    'reportedAt' => '2015-05-25 13:21:00',
                    'composerRepository' => 'https://packagist.org',
                    'severity' => 'medium',
                ],
            ],
        ];

        return $advisories;
    }

    /**
     * @dataProvider needsCompleteLoadProvider
     * @param array<string, array<SecurityAdvisory|PartialSecurityAdvisory>> $advisories
     * @param array<string> $ignoreList
     */
    public function testNeedsCompleteAdvisoryLoad(array $advisories, array $ignoreList, bool $expected): void
    {
        $auditor = new Auditor();
        self::assertSame($expected, $auditor->needsCompleteAdvisoryLoad($advisories, $ignoreList));
    }

    /**
     * @return array<array{array<string, array<SecurityAdvisory|PartialSecurityAdvisory>>, array<string, string|null>, bool}>
     */
    public static function needsCompleteLoadProvider(): array
    {
        return [
            'no filter or advisories' => [[], [], false],
            'packagist filters are IDs so work fine with partial advisories' => [[], ['PKSA-foo-bar' => null], false],
            'packagist filters are IDs so work fine with partial advisories/2' => [
                ['vendor1/package1' => [
                    new SecurityAdvisory('foo/bar', '123', new Constraint('=', '1.0.0.0'), 'test', [['name' => 'foo', 'remoteId' => 'remoteID']], new DateTimeImmutable()),
                    new PartialSecurityAdvisory('foo/bar', '1234', new Constraint('=', '1.0.0.0')),
                ]],
                ['PKSA-foo-bar' => 'this is fine 🔥'],
                false,
            ],
            'no advisories no need to load any further' => [[], ['CVE-2025-1234' => null], false],
            'no advisories no need to load any further/2' => [['vendor1/package1' => []], ['CVE-2025-1234' => null], false],
            'CVE filter or other non-packagist ones might need to fully load for safety if partial advisories are present' => [
                ['vendor1/package1' => [
                    new SecurityAdvisory('foo/bar', '123', new Constraint('=', '1.0.0.0'), 'test', [['name' => 'foo', 'remoteId' => 'remoteID']], new DateTimeImmutable()),
                    new PartialSecurityAdvisory('foo/bar', '1234', new Constraint('=', '1.0.0.0')),
                ]],
                ['CVE-2025-1234' => null],
                true,
            ],
            'filter does not trigger load if all advisories are fully loaded' => [
                [
                    'vendor1/package1' => [
                        new SecurityAdvisory('foo/bar', '123', new Constraint('=', '1.0.0.0'), 'test', [['name' => 'foo', 'remoteId' => 'remoteID']], new DateTimeImmutable()),
                    ],
                    'vendor1/package2' => [
                        new SecurityAdvisory('foo/bar', '1234', new Constraint('=', '1.0.0.0'), 'test', [['name' => 'foo', 'remoteId' => 'remoteID']], new DateTimeImmutable()),
                    ],
                ],
                ['CVE-2025-1234' => null],
                false,
            ],
        ];
    }
}
