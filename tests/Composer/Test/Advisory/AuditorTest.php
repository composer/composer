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
use Composer\IO\BufferIO;
use Composer\Package\CompletePackage;
use Composer\Package\Package;
use Composer\Package\Version\VersionParser;
use Composer\Repository\ComposerRepository;
use Composer\Repository\RepositorySet;
use Composer\Test\TestCase;
use Composer\Advisory\Auditor;
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
            'expected' => Auditor::BIT_OK,
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
            'expected' => Auditor::BIT_VULNERABLE,
            'output' => '<warning>Found 2 security vulnerability advisories affecting 1 package:</warning>
Package: vendor1/package1
Severity: high
CVE: CVE3
Title: advisory4
URL: https://advisory.example.com/advisory4
Affected versions: >=8,<8.2.2|>=1,<2.5.6
Reported at: 2022-05-25T13:21:00+00:00
--------
Package: vendor1/package1
Severity: medium
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
            'expected' => Auditor::BIT_OK,
            'output' => 'No security vulnerability advisories found.',
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
            'expected' => Auditor::BIT_OK,
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
            'expected' => Auditor::BIT_ABANDONED,
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
            'expected' => Auditor::BIT_VULNERABLE | Auditor::BIT_ABANDONED,
            'output' => 'Found 2 security vulnerability advisories affecting 1 package:
+-------------------+----------------------------------------------------------------------------------+
| Package           | vendor1/package1                                                                 |
| Severity          | high                                                                             |
| CVE               | CVE3                                                                             |
| Title             | advisory4                                                                        |
| URL               | https://advisory.example.com/advisory4                                           |
| Affected versions | >=8,<8.2.2|>=1,<2.5.6                                                            |
| Reported at       | 2022-05-25T13:21:00+00:00                                                        |
+-------------------+----------------------------------------------------------------------------------+
+-------------------+----------------------------------------------------------------------------------+
| Package           | vendor1/package1                                                                 |
| Severity          | medium                                                                           |
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
            'expected' => Auditor::BIT_ABANDONED,
            'output' => '{
    "advisories": [],
    "abandoned": {
        "vendor/abandoned": "foo/bar",
        "vendor/abandoned2": null
    }
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
        $result = $auditor->audit($io = new BufferIO(), $this->getRepoSet(), $data['packages'], $data['format'] ?? Auditor::FORMAT_PLAIN, $data['warningOnly'], [], $data['abandoned'] ?? Auditor::ABANDONED_IGNORE);
        self::assertSame($expected, $result);
        self::assertSame($output, trim(str_replace("\r", '', $io->getOutput())));
    }

    public function ignoredIdsProvider(): \Generator
    {
        yield 'ignore by CVE' => [
            [
                new Package('vendor1/package1', '3.0.0.0', '3.0.0'),
            ],
            ['CVE1'],
            0,
            [
                ['text' => 'Found 1 ignored security vulnerability advisory affecting 1 package:'],
                ['text' => 'Package: vendor1/package1'],
                ['text' => 'Severity: medium'],
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
            ['ID2'],
            0,
            [
                ['text' => 'Found 1 ignored security vulnerability advisory affecting 1 package:'],
                ['text' => 'Package: vendor1/package2'],
                ['text' => 'Severity: medium'],
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
            ['RemoteIDx'],
            0,
            [
                ['text' => 'Found 1 ignored security vulnerability advisory affecting 1 package:'],
                ['text' => 'Package: vendorx/packagex'],
                ['text' => 'Severity: medium'],
                ['text' => 'CVE: CVE5'],
                ['text' => 'Title: advisory17'],
                ['text' => 'URL: https://advisory.example.com/advisory17'],
                ['text' => 'Affected versions: >=3,<3.4.3|>=1,<2.5.6'],
                ['text' => 'Reported at: 2015-05-25T13:21:00+00:00'],
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
            ['RemoteIDx', 'ID3', 'ID6'],
            1,
            [
                ['text' => 'Found 3 ignored security vulnerability advisories affecting 2 packages:'],
                ['text' => 'Package: vendor2/package1'],
                ['text' => 'Severity: medium'],
                ['text' => 'CVE: CVE2'],
                ['text' => 'Title: advisory3'],
                ['text' => 'URL: https://advisory.example.com/advisory3'],
                ['text' => 'Affected versions: >=3,<3.4.3|>=1,<2.5.6'],
                ['text' => 'Reported at: 2022-05-25T13:21:00+00:00'],
                ['text' => 'Ignore reason: None specified'],
                ['text' => '--------'],
                ['text' => 'Package: vendor2/package1'],
                ['text' => 'Severity: medium'],
                ['text' => 'CVE: CVE4'],
                ['text' => 'Title: advisory6'],
                ['text' => 'URL: https://advisory.example.com/advisory6'],
                ['text' => 'Affected versions: >=3,<3.4.3|>=1,<2.5.6'],
                ['text' => 'Reported at: 2015-05-25T13:21:00+00:00'],
                ['text' => 'Ignore reason: None specified'],
                ['text' => '--------'],
                ['text' => 'Package: vendorx/packagex'],
                ['text' => 'Severity: medium'],
                ['text' => 'CVE: CVE5'],
                ['text' => 'Title: advisory17'],
                ['text' => 'URL: https://advisory.example.com/advisory17'],
                ['text' => 'Affected versions: >=3,<3.4.3|>=1,<2.5.6'],
                ['text' => 'Reported at: 2015-05-25T13:21:00+00:00'],
                ['text' => 'Ignore reason: None specified'],
                ['text' => 'Found 1 security vulnerability advisory affecting 1 package:'],
                ['text' => 'Package: vendor3/package1'],
                ['text' => 'Severity: medium'],
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
     * @phpstan-param array<\Composer\Package\Package> $packages
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
            ['medium'],
            1,
            [
                ['text' => 'Found 2 ignored security vulnerability advisories affecting 1 package:'],
            ],
        ];
        yield 'ignore high' => [
            [
                new Package('vendor1/package1', '2.0.0.0', '2.0.0'),
            ],
            ['high'],
            1,
            [
                ['text' => 'Found 1 ignored security vulnerability advisory affecting 1 package:'],
            ],
        ];
        yield 'ignore high and medium' => [
            [
                new Package('vendor1/package1', '2.0.0.0', '2.0.0'),
            ],
            ['high', 'medium'],
            0,
            [
                ['text' => 'Found 3 ignored security vulnerability advisories affecting 1 package:'],
            ],
        ];
    }

    /**
     * @dataProvider ignoreSeverityProvider
     * @phpstan-param array<\Composer\Package\Package> $packages
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
}
