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
use Composer\IO\NullIO;
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
        return [
            // Test no advisories returns 0
            [
                'data' => [
                    'packages' => [
                        new Package('vendor1/package2', '9.0.0', '9.0.0'),
                        new Package('vendor1/package1', '9.0.0', '9.0.0'),
                        new Package('vendor3/package1', '9.0.0', '9.0.0'),
                    ],
                    'warningOnly' => true,
                ],
                'expected' => 0,
                'message' => 'Test no advisories returns 0',
            ],
            // Test with advisories returns 1
            [
                'data' => [
                    'packages' => [
                        new Package('vendor1/package2', '9.0.0', '9.0.0'),
                        new Package('vendor1/package1', '8.2.1', '8.2.1'),
                        new Package('vendor3/package1', '9.0.0', '9.0.0'),
                    ],
                    'warningOnly' => true,
                ],
                'expected' => 1,
                'message' => 'Test with advisories returns 1',
            ],
        ];
    }

    /**
     * @dataProvider auditProvider
     * @phpstan-param array<string, mixed> $data
     */
    public function testAudit(array $data, int $expected, string $message): void
    {
        if (count($data['packages']) === 0) {
            $this->expectException(InvalidArgumentException::class);
        }
        $auditor = new Auditor();
        $result = $auditor->audit(new NullIO(), $this->getRepoSet(), $data['packages'], Auditor::FORMAT_PLAIN, $data['warningOnly']);
        $this->assertSame($expected, $result, $message);
    }

    public function testAuditIgnoredIDs(): void
    {
        $packages = [
            new Package('vendor1/package1', '3.0.0.0', '3.0.0'),
            new Package('vendor1/package2', '3.0.0.0', '3.0.0'),
            new Package('vendorx/packagex', '3.0.0.0', '3.0.0'),
            new Package('vendor3/package1', '3.0.0.0', '3.0.0'),
        ];

        $ignoredIds = ['CVE1', 'ID2', 'RemoteIDx'];

        $auditor = new Auditor();
        $result = $auditor->audit($io = $this->getIOMock(), $this->getRepoSet(), $packages, Auditor::FORMAT_PLAIN, false, $ignoredIds);
        $io->expects([
            ['text' => 'Found 1 security vulnerability advisory affecting 1 package:'],
            ['text' => 'Package: vendor3/package1'],
            ['text' => 'CVE: CVE5'],
            ['text' => 'Title: advisory7'],
            ['text' => 'URL: https://advisory.example.com/advisory7'],
            ['text' => 'Affected versions: >=3,<3.4.3|>=1,<2.5.6'],
            ['text' => 'Reported at: 2015-05-25T13:21:00+00:00'],
        ], true);
        $this->assertSame(1, $result);

        // without ignored IDs, we should get all 4
        $result = $auditor->audit($io, $this->getRepoSet(), $packages, Auditor::FORMAT_PLAIN, false);
        $this->assertSame(4, $result);
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
                    'reportedAt' => '',
                    'composerRepository' => 'https://packagist.org',
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
                ],
            ],
        ];

        return $advisories;
    }
}
