<?php

namespace Composer\Test\Util;

use Composer\IO\IOInterface;
use Composer\Package\Package;
use Composer\Test\TestCase;
use Composer\Util\Auditor;
use Composer\Util\Http\Response;
use Composer\Util\HttpDownloader;
use InvalidArgumentException;
use PHPUnit\Framework\MockObject\MockObject;

class AuditorTest extends TestCase
{
    /** @var IOInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $io;

    protected function setUp(): void
    {
        $this->io = $this
            ->getMockBuilder(IOInterface::class)
            ->disableOriginalConstructor()
            ->getMock();
    }

    public function auditProvider()
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
            // Test no packages throws InvalidArgumentException
            [
                'data' => [
                    'packages' => [],
                    'warningOnly' => true,
                ],
                'expected' => 1,
                'message' => 'Test no packages throws InvalidArgumentException',
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
        $auditor = new Auditor($this->getHttpDownloader(), Auditor::FORMAT_PLAIN);
        $result = $auditor->audit($this->io, $data['packages'], $data['warningOnly']);
        $this->assertSame($expected, $result, $message);
    }

    public function advisoriesProvider()
    {
        $advisories = static::getMockAdvisories(null);
        return [
            [
                'data' => [
                    'packages' => [
                        new Package('vendor1/package1', '8.2.1', '8.2.1'),
                        new Package('vendor1/package2', '3.1.0', '3.1.0'),
                        // Check a package with no advisories at all doesn't cause any issues
                        new Package('vendor5/package2', '5.0.0', '5.0.0'),
                    ],
                    'updatedSince' => null,
                    'filterByVersion' => false
                ],
                'expected' => [
                    'vendor1/package1' => $advisories['vendor1/package1'],
                    'vendor1/package2' => $advisories['vendor1/package2'],
                ],
                'message' => 'Check not filtering by version',
            ],
            [
                'data' => [
                    'packages' => [
                        new Package('vendor1/package1', '8.2.1', '8.2.1'),
                        new Package('vendor1/package2', '3.1.0', '3.1.0'),
                        // Check a package with no advisories at all doesn't cause any issues
                        new Package('vendor5/package2', '5.0.0', '5.0.0'),
                    ],
                    'updatedSince' => null,
                    'filterByVersion' => true
                ],
                'expected' => [
                    'vendor1/package1' => [
                        $advisories['vendor1/package1'][1],
                        $advisories['vendor1/package1'][2],
                    ],
                    'vendor1/package2' => [
                        $advisories['vendor1/package2'][0],
                    ],
                ],
                'message' => 'Check filter by version',
            ],
            [
                'data' => [
                    'packages' => [
                        new Package('vendor1/package1', '8.2.1', '8.2.1'),
                        new Package('vendor1/package2', '5.0.0', '5.0.0'),
                        new Package('vendor2/package1', '3.0.0', '3.0.0'),
                    ],
                    'updatedSince' => 1335939007,
                    'filterByVersion' => false
                ],
                'expected' => [
                    'vendor1/package1' => [
                        $advisories['vendor1/package1'][0],
                        $advisories['vendor1/package1'][1],
                    ],
                    'vendor1/package2' => [
                        $advisories['vendor1/package2'][0],
                    ],
                    'vendor2/package1' => [
                        $advisories['vendor2/package1'][0],
                    ],
                ],
                'message' => 'Check updatedSince is passed through to the API',
            ],
            [
                'data' => [
                    'packages' => [],
                    'updatedSince' => 1335939007,
                    'filterByVersion' => true
                ],
                'expected' => [],
                'message' => 'No packages and filterByVersion === true should return 0 results',
            ],
            [
                'data' => [
                    'packages' => [],
                    'updatedSince' => 0,
                    'filterByVersion' => false
                ],
                // All advisories expected with no packages and updatedSince === 0
                'expected' => $advisories,
                'message' => 'No packages and updatedSince === 0 should NOT throw LogicException',
            ],
            [
                'data' => [
                    'packages' => [],
                    'updatedSince' => null,
                    'filterByVersion' => false
                ],
                'expected' => [],
                'message' => 'No packages and updatedSince === null should throw LogicException',
            ],
        ];
    }

    /**
     * @dataProvider advisoriesProvider
     * @phpstan-param array<string, mixed> $data
     * @phpstan-param string[][][] $expected
     */
    public function testGetAdvisories(array $data, array $expected, string $message): void
    {
        if (count($data['packages']) === 0 && $data['updatedSince'] === null) {
            $this->expectException(InvalidArgumentException::class);
        }
        $auditor = new Auditor($this->getHttpDownloader(), Auditor::FORMAT_PLAIN);
        $result = $auditor->getAdvisories($data['packages'], $data['updatedSince'], $data['filterByVersion']);
        $this->assertSame($expected, $result, $message);
    }

    /**
     * @return HttpDownloader&MockObject
     */
    private function getHttpDownloader(): MockObject
    {
        $httpDownloader = $this
            ->getMockBuilder(HttpDownloader::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get'])
            ->getMock();

        $callback = function(string $url, array $options) {
            parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);
            $updatedSince = null;
            if (isset($query['updatedSince'])) {
                $updatedSince = $query['updatedSince'];
            }

            $advisories = AuditorTest::getMockAdvisories($updatedSince);

            // If the mock API request is for specific packages, only include advisories for those packages
            if (isset($options['http']['content'])) {
                parse_str($options['http']['content'], $body);
                $packages = $body['packages'];
                foreach ($advisories as $package => $data) {
                    if (!in_array($package, $packages)) {
                        unset($advisories[$package]);
                    }
                }
            }

            return new Response(['url' => Auditor::API_URL], 200, [], json_encode(['advisories' => $advisories]));
        };

        $httpDownloader
            ->method('get')
            ->willReturnCallback($callback);

        return $httpDownloader;
    }

    public static function getMockAdvisories(?int $updatedSince)
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
                            'remoteId' => 'RemoteID2',
                        ],
                    ],
                    'reportedAt' => '2022-05-25 13:21:00',
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
            ],
        ];

        // Intentionally allow updatedSince === 0 to include these advisories
        if (!$updatedSince) {
            $advisories['vendor1/package1'][] = [
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
            ];
            $advisories['vendor2/package1'][] = [
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
            ];
            $advisories['vendory/packagey'][] = [
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
            ];
            $advisories['vendor3/package1'][] = [
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
            ];
        }

        return $advisories;
    }
}
