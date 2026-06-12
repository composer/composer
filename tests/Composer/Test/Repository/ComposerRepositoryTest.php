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

namespace Composer\Test\Repository;

use Composer\FilterList\FilterListEntry;
use Composer\IO\NullIO;
use Composer\Json\JsonFile;
use Composer\Repository\ComposerRepository;
use Composer\Repository\RepositoryInterface;
use Composer\Semver\Constraint\Constraint;
use Composer\Semver\Constraint\MatchAllConstraint;
use Composer\Test\Mock\FactoryMock;
use Composer\Test\TestCase;
use Composer\Package\Loader\ArrayLoader;

class ComposerRepositoryTest extends TestCase
{
    /**
     * @dataProvider loadDataProvider
     *
     * @param mixed[]              $expected
     * @param array<string, mixed> $repoPackages
     */
    public function testLoadData(array $expected, array $repoPackages): void
    {
        $repoConfig = [
            'url' => 'http://example.org',
        ];

        $repository = $this->getMockBuilder('Composer\Repository\ComposerRepository')
            ->onlyMethods(['loadRootServerFile'])
            ->setConstructorArgs([
                $repoConfig,
                new NullIO,
                FactoryMock::createConfig(),
                $this->getMockBuilder('Composer\Util\HttpDownloader')->disableOriginalConstructor()->getMock(),
                $this->getMockBuilder('Composer\EventDispatcher\EventDispatcher')->disableOriginalConstructor()->getMock(),
            ])
            ->getMock();

        $repository
            ->expects($this->exactly(2))
            ->method('loadRootServerFile')
            ->will($this->returnValue($repoPackages));

        // Triggers initialization
        $packages = $repository->getPackages();

        // Final sanity check, ensure the correct number of packages were added.
        self::assertCount(count($expected), $packages);

        foreach ($expected as $index => $pkg) {
            self::assertSame($pkg['name'].' '.$pkg['version'], $packages[$index]->getName().' '.$packages[$index]->getPrettyVersion());
        }
    }

    public static function loadDataProvider(): array
    {
        return [
            // Old repository format
            [
                [
                    ['name' => 'foo/bar', 'version' => '1.0.0'],
                ],
                ['foo/bar' => [
                    'name' => 'foo/bar',
                    'versions' => [
                        '1.0.0' => ['name' => 'foo/bar', 'version' => '1.0.0'],
                    ],
                ]],
            ],
            // New repository format
            [
                [
                    ['name' => 'bar/foo', 'version' => '3.14'],
                    ['name' => 'bar/foo', 'version' => '3.145'],
                ],
                ['packages' => [
                    'bar/foo' => [
                        '3.14' => ['name' => 'bar/foo', 'version' => '3.14'],
                        '3.145' => ['name' => 'bar/foo', 'version' => '3.145'],
                    ],
                ]],
            ],
            // New repository format but without versions as keys should also be supported
            [
                [
                    ['name' => 'bar/foo', 'version' => '3.14'],
                    ['name' => 'bar/foo', 'version' => '3.145'],
                ],
                ['packages' => [
                    'bar/foo' => [
                        ['name' => 'bar/foo', 'version' => '3.14'],
                        ['name' => 'bar/foo', 'version' => '3.145'],
                    ],
                ]],
            ],
        ];
    }

    public function testWhatProvides(): void
    {
        $repo = $this->getMockBuilder('Composer\Repository\ComposerRepository')
            ->setConstructorArgs([
                ['url' => 'https://dummy.test.link'],
                new NullIO,
                FactoryMock::createConfig(),
                $this->getMockBuilder('Composer\Util\HttpDownloader')->disableOriginalConstructor()->getMock(),
                $this->getMockBuilder('Composer\EventDispatcher\EventDispatcher')->disableOriginalConstructor()->getMock(),
            ])
            ->onlyMethods(['fetchFile'])
            ->getMock();

        $cache = $this->getMockBuilder('Composer\Cache')->disableOriginalConstructor()->getMock();
        $cache->expects($this->any())
            ->method('sha256')
            ->will($this->returnValue(false));

        $properties = [
            'cache' => $cache,
            'loader' => new ArrayLoader(),
            'providerListing' => ['a' => ['sha256' => 'xxx']],
            'providersUrl' => 'https://dummy.test.link/to/%package%/file',
        ];

        foreach ($properties as $property => $value) {
            $ref = new \ReflectionProperty($repo, $property);
            if (\PHP_VERSION_ID < 80100) {
                $ref->setAccessible(true);
            }

            $ref->setValue($repo, $value);
        }

        $repo->expects($this->any())
            ->method('fetchFile')
            ->will($this->returnValue([
                'packages' => [
                    [[
                        'uid' => 1,
                        'name' => 'a',
                        'version' => 'dev-master',
                        'extra' => ['branch-alias' => ['dev-master' => '1.0.x-dev']],
                    ]],
                    [[
                        'uid' => 2,
                        'name' => 'a',
                        'version' => 'dev-develop',
                        'extra' => ['branch-alias' => ['dev-develop' => '1.1.x-dev']],
                    ]],
                    [[
                        'uid' => 3,
                        'name' => 'a',
                        'version' => '0.6',
                    ]],
                ],
            ]));

        $reflMethod = new \ReflectionMethod(ComposerRepository::class, 'whatProvides');
        (\PHP_VERSION_ID < 80100) and $reflMethod->setAccessible(true);
        $packages = $reflMethod->invoke($repo, 'a');

        self::assertCount(5, $packages);
        self::assertEquals(['1', '1-alias', '2', '2-alias', '3'], array_keys($packages));
        self::assertSame($packages['2'], $packages['2-alias']->getAliasOf());
    }

    public function testSearchWithType(): void
    {
        $repoConfig = [
            'url' => 'http://example.org',
        ];

        $result = [
            'results' => [
                [
                    'name' => 'foo',
                    'description' => null,
                ],
            ],
        ];

        $httpDownloader = $this->getHttpDownloaderMock();
        $httpDownloader->expects(
            [
                ['url' => 'http://example.org/packages.json', 'body' => JsonFile::encode(['search' => '/search.json?q=%query%&type=%type%'])],
                ['url' => 'http://example.org/search.json?q=foo&type=composer-plugin', 'body' => JsonFile::encode($result)],
                ['url' => 'http://example.org/search.json?q=foo&type=library', 'body' => JsonFile::encode([])],
            ],
            true
        );
        $eventDispatcher = $this->getMockBuilder('Composer\EventDispatcher\EventDispatcher')
            ->disableOriginalConstructor()
            ->getMock();

        $config = FactoryMock::createConfig();
        $config->merge(['config' => ['cache-read-only' => true]]);
        $repository = new ComposerRepository($repoConfig, new NullIO, $config, $httpDownloader, $eventDispatcher);

        self::assertSame(
            [['name' => 'foo', 'description' => null]],
            $repository->search('foo', RepositoryInterface::SEARCH_FULLTEXT, 'composer-plugin')
        );

        self::assertEmpty(
            $repository->search('foo', RepositoryInterface::SEARCH_FULLTEXT, 'library')
        );
    }

    public function testSearchWithSpecialChars(): void
    {
        $repoConfig = [
            'url' => 'http://example.org',
        ];

        $httpDownloader = $this->getHttpDownloaderMock();
        $httpDownloader->expects(
            [
                ['url' => 'http://example.org/packages.json', 'body' => JsonFile::encode(['search' => '/search.json?q=%query%&type=%type%'])],
                ['url' => 'http://example.org/search.json?q=foo+bar&type=', 'body' => JsonFile::encode([])],
            ],
            true
        );
        $eventDispatcher = $this->getMockBuilder('Composer\EventDispatcher\EventDispatcher')
            ->disableOriginalConstructor()
            ->getMock();

        $config = FactoryMock::createConfig();
        $config->merge(['config' => ['cache-read-only' => true]]);
        $repository = new ComposerRepository($repoConfig, new NullIO, $config, $httpDownloader, $eventDispatcher);

        self::assertEmpty(
            $repository->search('foo bar', RepositoryInterface::SEARCH_FULLTEXT)
        );
    }

    public function testSearchWithAbandonedPackages(): void
    {
        $repoConfig = [
            'url' => 'http://example.org',
        ];

        $result = [
            'results' => [
                [
                    'name' => 'foo1',
                    'description' => null,
                    'abandoned' => true,
                ],
                [
                    'name' => 'foo2',
                    'description' => null,
                    'abandoned' => 'bar',
                ],
            ],
        ];

        $httpDownloader = $this->getHttpDownloaderMock();
        $httpDownloader->expects(
            [
                ['url' => 'http://example.org/packages.json', 'body' => JsonFile::encode(['search' => '/search.json?q=%query%'])],
                ['url' => 'http://example.org/search.json?q=foo', 'body' => JsonFile::encode($result)],
            ],
            true
        );

        $eventDispatcher = $this->getMockBuilder('Composer\EventDispatcher\EventDispatcher')
            ->disableOriginalConstructor()
            ->getMock();

        $config = FactoryMock::createConfig();
        $config->merge(['config' => ['cache-read-only' => true]]);
        $repository = new ComposerRepository($repoConfig, new NullIO, $config, $httpDownloader, $eventDispatcher);

        self::assertSame(
            [
                ['name' => 'foo1', 'description' => null, 'abandoned' => true],
                ['name' => 'foo2', 'description' => null, 'abandoned' => 'bar'],
            ],
            $repository->search('foo')
        );
    }

    /**
     * @dataProvider provideCanonicalizeUrlTestCases
     * @param non-empty-string $url
     * @param non-empty-string $repositoryUrl
     */
    public function testCanonicalizeUrl(string $expected, string $url, string $repositoryUrl): void
    {
        $repository = new ComposerRepository(
            ['url' => $repositoryUrl],
            new NullIO(),
            FactoryMock::createConfig(),
            $this->getMockBuilder('Composer\Util\HttpDownloader')->disableOriginalConstructor()->getMock(),
            $this->getMockBuilder('Composer\EventDispatcher\EventDispatcher')->disableOriginalConstructor()->getMock()
        );

        $object = new \ReflectionObject($repository);

        $method = $object->getMethod('canonicalizeUrl');
        (\PHP_VERSION_ID < 80100) and $method->setAccessible(true);

        // ComposerRepository::__construct ensures that the repository URL has a
        // protocol, so reset it here in order to test all cases.
        $property = $object->getProperty('url');
        (\PHP_VERSION_ID < 80100) and $property->setAccessible(true);
        $property->setValue($repository, $repositoryUrl);

        self::assertSame($expected, $method->invoke($repository, $url));
    }

    public static function provideCanonicalizeUrlTestCases(): array
    {
        return [
            [
                'https://example.org/path/to/file',
                '/path/to/file',
                'https://example.org',
            ],
            [
                'https://example.org/canonic_url',
                'https://example.org/canonic_url',
                'https://should-not-see-me.test',
            ],
            [
                'file:///path/to/repository/file',
                '/path/to/repository/file',
                'file:///path/to/repository',
            ],
            [
                // Assert that the repository URL is returned unchanged if it is
                // not a URL.
                // (Backward compatibility test)
                'invalid_repo_url',
                '/path/to/file',
                'invalid_repo_url',
            ],
            [
                // Assert that URLs can contain sequences resembling pattern
                // references as understood by preg_replace() without messing up
                // the result.
                // (Regression test)
                'https://example.org/path/to/unusual_$0_filename',
                '/path/to/unusual_$0_filename',
                'https://example.org',
            ],
        ];
    }

    public function testGetProviderNamesWillReturnPartialPackageNames(): void
    {
        $httpDownloader = $this->getHttpDownloaderMock();
        $httpDownloader->expects(
            [
                [
                    'url' => 'http://example.org/packages.json',
                    'body' => JsonFile::encode([
                        'providers-lazy-url' => '/foo/p/%package%.json',
                        'packages' => ['foo/bar' => [
                            'dev-branch' => ['name' => 'foo/bar'],
                            'v1.0.0' => ['name' => 'foo/bar'],
                        ]],
                    ]),
                ],
            ],
            true
        );

        $repository = new ComposerRepository(
            ['url' => 'http://example.org/packages.json'],
            new NullIO(),
            FactoryMock::createConfig(),
            $httpDownloader
        );

        self::assertEquals(['foo/bar'], $repository->getPackageNames());
    }

    public function testGetSecurityAdvisoriesAssertRepositoryHttpOptionsAreUsed(): void
    {
        $httpDownloader = $this->getHttpDownloaderMock();
        $httpDownloader->expects(
            [
                [
                    'url' => 'https://example.org/packages.json',
                    'body' => JsonFile::encode([
                        'packages' => ['foo/bar' => [
                            'dev-branch' => ['name' => 'foo/bar'],
                            'v1.0.0' => ['name' => 'foo/bar'],
                        ]],
                        'metadata-url' => 'https://example.org/p2/%package%.json',
                        'security-advisories' => [
                            'api-url' => 'https://example.org/security-advisories',
                        ],
                    ]),
                    'options' => ['http' => ['verify_peer' => false]],
                ],
                [
                    'url' => 'https://example.org/security-advisories',
                    'body' => JsonFile::encode(['advisories' => []]),
                    'options' => ['http' => [
                        'verify_peer' => false,
                        'method' => 'POST',
                        'header' => [
                            'Content-type: application/x-www-form-urlencoded',
                        ],
                        'timeout' => 10,
                        'content' => http_build_query(['packages' => ['foo/bar']]),
                    ]],
                ],
            ],
            true
        );

        $repository = new ComposerRepository(
            ['url' => 'https://example.org/packages.json', 'options' => ['http' => ['verify_peer' => false]]],
            new NullIO(),
            FactoryMock::createConfig(),
            $httpDownloader
        );

        self::assertSame([
            'namesFound' => [],
            'advisories' => [],
        ], $repository->getSecurityAdvisories(['foo/bar' => new Constraint('=', '1.0.0.0')]));
    }

    public function testGetSecurityAdvisoriesAssertRepositoryAdvisoriesIsZeroIndexedArrayWithConsecutiveKeys(): void
    {
        $packageName = 'foo/bar';
        $advisory1 = $this->generateSecurityAdvisory($packageName, 'CVE-1999-1000', '>=1.0.0,<1.1.0');
        $advisory2 = $this->generateSecurityAdvisory($packageName, 'CVE-1999-1000', '>=2.0.0');
        $advisory3 = $this->generateSecurityAdvisory($packageName, 'CVE-1999-1000', '>=1.0.0,<1.1.0');

        $expectedPackageAdvisories = [
            $advisory1,
            $advisory3,
        ];

        $httpDownloader = $this->getHttpDownloaderMock();
        $httpDownloader->expects(
            [
                [
                    'url' => 'https://example.org/packages.json',
                    'body' => JsonFile::encode([
                        'packages' => [
                            $packageName => [
                                'dev-branch' => ['name' => $packageName],
                                'v1.0.0' => ['name' => $packageName],
                            ],
                        ],
                        'metadata-url' => 'https://example.org/p2/%package%.json',
                        'security-advisories' => [
                            'api-url' => 'https://example.org/security-advisories',
                        ],
                    ]),
                    'options' => ['http' => ['verify_peer' => false]],
                ],
                [
                    'url' => 'https://example.org/security-advisories',
                    'body' => JsonFile::encode([
                        'advisories' => [
                            $packageName => [
                                $advisory1,
                                $advisory2,
                                $advisory3,
                            ],
                        ],
                    ]),
                    'options' => [
                        'http' => [
                            'verify_peer' => false,
                            'method' => 'POST',
                            'header' => [
                                'Content-type: application/x-www-form-urlencoded',
                            ],
                            'timeout' => 10,
                            'content' => http_build_query(['packages' => [$packageName]]),
                        ],
                    ],
                ],
            ],
            true
        );

        $repository = new ComposerRepository(
            ['url' => 'https://example.org/packages.json', 'options' => ['http' => ['verify_peer' => false]]],
            new NullIO(),
            FactoryMock::createConfig(),
            $httpDownloader
        );

        [
            'advisories' => $actualAdvisories,
        ] = $repository->getSecurityAdvisories([$packageName => new Constraint('=', '1.0.0.0')]);

        $this->assertIsArray($actualAdvisories);
        $this->assertArrayHasKey($packageName, $actualAdvisories);
        $actualPackageAdvisories = $actualAdvisories[$packageName];
        $this->assertSameSize($expectedPackageAdvisories, $actualPackageAdvisories);
        foreach ($expectedPackageAdvisories as $i => $expectedAdvisory) {
            $this->assertArrayHasKey($i, $actualPackageAdvisories);
            $this->assertSame($expectedAdvisory['advisoryId'], $actualPackageAdvisories[$i]->advisoryId);
        }
    }

    public function testGetFilterWithMatchingLists(): void
    {
        $httpDownloader = $this->getHttpDownloaderMock();
        $httpDownloader->expects(
            [
                [
                    'url' => 'https://example.org/packages.json',
                    'body' => JsonFile::encode([
                        'metadata-url' => 'https://example.org/p2/%package%.json',
                        'filter' => [
                            'metadata' => true,
                            'lists' => ['test' => ['enabled' => true]],
                        ],
                    ]),
                    'options' => ['http' => ['verify_peer' => false]],
                ],
                [
                    'url' => 'https://example.org/p2/acme/package.json',
                    'body' => JsonFile::encode([
                        'filter' => [
                            'test' => [[
                                'constraint' => '*',
                                'url' => 'https://example.org/acme/package/filters.json',
                                'reason' => 'Malicious code detected',
                                'id' => 'ID-test',
                            ]],
                        ],
                    ]),
                    'options' => ['http' => ['verify_peer' => false]],
                ],
            ],
            true
        );

        $repository = new ComposerRepository(
            ['url' => 'https://example.org/packages.json', 'options' => ['http' => ['verify_peer' => false]]],
            new NullIO(),
            FactoryMock::createConfig(),
            $httpDownloader
        );

        ['filter' => $filter] = $repository->getFilter(['acme/package' => new Constraint('=', '1.0.0.0')], ['test']);

        $constraint = new MatchAllConstraint();
        $constraint->setPrettyString('*');
        $this->assertEquals(['test' => [new FilterListEntry('acme/package', $constraint, 'test', 'https://example.org/acme/package/filters.json', 'Malicious code detected', 'ID-test')]], $filter);
    }

    public function testUserFilterDisabledFalseShortCircuitsHasFilterAndGetFilterLists(): void
    {
        // No HTTP requests should be issued when the user has set `filter: false`,
        // so we configure the mock with no expectations.
        $httpDownloader = $this->getHttpDownloaderMock();
        $httpDownloader->expects([], true);

        $repository = new ComposerRepository(
            ['url' => 'https://example.org/packages.json', 'filter' => false],
            new NullIO(),
            FactoryMock::createConfig(),
            $httpDownloader
        );

        $this->assertFalse($repository->hasFilter());
        $this->assertSame([], $repository->getFilterLists());
    }

    public function testUserFilterPerListOptOut(): void
    {
        $httpDownloader = $this->getHttpDownloaderMock();
        $httpDownloader->expects(
            [
                [
                    'url' => 'https://example.org/packages.json',
                    'body' => JsonFile::encode([
                        'metadata-url' => 'https://example.org/p2/%package%.json',
                        'filter' => [
                            'metadata' => true,
                            'lists' => [
                                'malware' => ['enabled' => true],
                                'typosquatting' => ['enabled' => true],
                                'deprecated' => ['enabled' => true],
                            ],
                        ],
                    ]),
                    'options' => ['http' => ['verify_peer' => false]],
                ],
            ],
            true
        );

        $repository = new ComposerRepository(
            [
                'url' => 'https://example.org/packages.json',
                'options' => ['http' => ['verify_peer' => false]],
                'filter' => [
                    'typosquatting' => false,
                    // Opting out of a list this repo doesn't advertise is harmless.
                    'unknown-list' => false,
                ],
            ],
            new NullIO(),
            FactoryMock::createConfig(),
            $httpDownloader
        );

        $this->assertTrue($repository->hasFilter());
        $this->assertSame(['malware', 'deprecated'], $repository->getFilterLists());
    }

    public function testUserFilterAcceptsTrueAsNoOp(): void
    {
        // `true` is undocumented but accepted silently so layered configs can
        // round-trip a key without losing data; it has no effect because
        // unmentioned lists are already enabled.
        $httpDownloader = $this->getHttpDownloaderMock();
        $httpDownloader->expects(
            [
                [
                    'url' => 'https://example.org/packages.json',
                    'body' => JsonFile::encode([
                        'metadata-url' => 'https://example.org/p2/%package%.json',
                        'filter' => [
                            'metadata' => true,
                            'lists' => [
                                'malware' => ['enabled' => true],
                                'typosquatting' => ['enabled' => true],
                            ],
                        ],
                    ]),
                    'options' => ['http' => ['verify_peer' => false]],
                ],
            ],
            true
        );

        $repository = new ComposerRepository(
            [
                'url' => 'https://example.org/packages.json',
                'options' => ['http' => ['verify_peer' => false]],
                'filter' => [
                    'malware' => true,
                    'typosquatting' => false,
                ],
            ],
            new NullIO(),
            FactoryMock::createConfig(),
            $httpDownloader
        );

        $this->assertTrue($repository->hasFilter());
        $this->assertSame(['malware'], $repository->getFilterLists());
    }

    public function testGetFilterSkipsMetadataFetchesForPackagesNotInSummary(): void
    {
        $httpDownloader = $this->getHttpDownloaderMock();
        $httpDownloader->expects(
            [
                [
                    'url' => 'https://example.org/packages.json',
                    'body' => JsonFile::encode([
                        'metadata-url' => 'https://example.org/p2/%package%.json',
                        'filter' => [
                            'metadata' => true,
                            'lists' => ['malware' => ['enabled' => true]],
                            'summary-url' => '/lists/all/summary.json',
                        ],
                    ]),
                    'options' => ['http' => ['verify_peer' => false]],
                ],
                [
                    'url' => 'https://example.org/lists/all/summary.json',
                    'body' => JsonFile::encode([
                        'filter' => [
                            'malware' => ['evil/pkg' => '^1.0'],
                        ],
                    ]),
                    'options' => ['http' => ['verify_peer' => false]],
                ],
                [
                    'url' => 'https://example.org/p2/evil/pkg.json',
                    'body' => JsonFile::encode([
                        'filter' => [
                            'malware' => [[
                                'constraint' => '*',
                                'url' => 'https://example.org/evil/pkg/filters.json',
                                'reason' => 'Confirmed malware',
                                'id' => 'ID-test',
                            ]],
                        ],
                    ]),
                    'options' => ['http' => ['verify_peer' => false]],
                ],
            ],
            true
        );

        $repository = new ComposerRepository(
            ['url' => 'https://example.org/packages.json', 'options' => ['http' => ['verify_peer' => false]]],
            new NullIO(),
            FactoryMock::createConfig(),
            $httpDownloader
        );

        ['filter' => $filter] = $repository->getFilter(
            [
                'evil/pkg' => new Constraint('=', '1.0.0.0'),
                'safe/pkg' => new Constraint('=', '1.0.0.0'),
            ],
            ['malware']
        );

        $this->assertArrayHasKey('malware', $filter);
        $this->assertCount(1, $filter['malware']);
        $this->assertSame('evil/pkg', $filter['malware'][0]->packageName);
    }

    public function testGetFilterSkipsSummaryListsNotInConfiguredLists(): void
    {
        $httpDownloader = $this->getHttpDownloaderMock();
        // typosquatting list is in the summary but not in configuredLists; no metadata fetch should happen.
        $httpDownloader->expects(
            [
                [
                    'url' => 'https://example.org/packages.json',
                    'body' => JsonFile::encode([
                        'metadata-url' => 'https://example.org/p2/%package%.json',
                        'filter' => [
                            'metadata' => true,
                            'lists' => [
                                'malware' => ['enabled' => true],
                                'typosquatting' => ['enabled' => true],
                            ],
                            'summary-url' => '/lists/all/summary.json',
                        ],
                    ]),
                    'options' => ['http' => ['verify_peer' => false]],
                ],
                [
                    'url' => 'https://example.org/lists/all/summary.json',
                    'body' => JsonFile::encode([
                        'filter' => [
                            'typosquatting' => ['lookalike/pkg' => '*'],
                        ],
                    ]),
                    'options' => ['http' => ['verify_peer' => false]],
                ],
            ],
            true
        );

        $repository = new ComposerRepository(
            ['url' => 'https://example.org/packages.json', 'options' => ['http' => ['verify_peer' => false]]],
            new NullIO(),
            FactoryMock::createConfig(),
            $httpDownloader
        );

        ['filter' => $filter] = $repository->getFilter(
            ['lookalike/pkg' => new Constraint('=', '1.0.0.0')],
            ['malware']
        );

        $this->assertSame([], $filter);
    }

    public function testGetFilterSkipsPackagesWithNonIntersectingSummaryConstraint(): void
    {
        $httpDownloader = $this->getHttpDownloaderMock();
        $httpDownloader->expects(
            [
                [
                    'url' => 'https://example.org/packages.json',
                    'body' => JsonFile::encode([
                        'metadata-url' => 'https://example.org/p2/%package%.json',
                        'filter' => [
                            'metadata' => true,
                            'lists' => ['malware' => ['enabled' => true]],
                            'summary-url' => '/lists/all/summary.json',
                        ],
                    ]),
                    'options' => ['http' => ['verify_peer' => false]],
                ],
                [
                    'url' => 'https://example.org/lists/all/summary.json',
                    'body' => JsonFile::encode([
                        'filter' => [
                            'malware' => ['evil/pkg' => '^1.0'],
                        ],
                    ]),
                    'options' => ['http' => ['verify_peer' => false]],
                ],
            ],
            true
        );

        $repository = new ComposerRepository(
            ['url' => 'https://example.org/packages.json', 'options' => ['http' => ['verify_peer' => false]]],
            new NullIO(),
            FactoryMock::createConfig(),
            $httpDownloader
        );

        // Requested constraint =2.5.0 does not intersect summary constraint ^1.0; no metadata fetch.
        ['filter' => $filter] = $repository->getFilter(
            ['evil/pkg' => new Constraint('=', '2.5.0.0')],
            ['malware']
        );

        $this->assertSame([], $filter);
    }

    public function testGetFilterSkipsSummaryWhenMetadataAlreadyFetched(): void
    {
        $httpDownloader = $this->getHttpDownloaderMock();
        // Only one fetch of each URL is expected: the second getFilter() call must skip the
        // summary entirely (freshMetadataUrls is non-empty) and short-circuit the metadata fetch.
        $httpDownloader->expects(
            [
                [
                    'url' => 'https://example.org/packages.json',
                    'body' => JsonFile::encode([
                        'metadata-url' => 'https://example.org/p2/%package%.json',
                        'filter' => [
                            'metadata' => true,
                            'lists' => ['malware' => ['enabled' => true]],
                            'summary-url' => '/lists/all/summary.json',
                        ],
                    ]),
                    'headers' => ['Last-Modified: Tue, 01 Jan 2099 00:00:00 GMT'],
                ],
                [
                    'url' => 'https://example.org/lists/all/summary.json',
                    'body' => JsonFile::encode([
                        'filter' => [
                            'malware' => ['evil/pkg' => '*'],
                        ],
                    ]),
                    'headers' => ['Last-Modified: Tue, 01 Jan 2099 00:00:00 GMT'],
                ],
                [
                    'url' => 'https://example.org/p2/evil/pkg.json',
                    'body' => JsonFile::encode([
                        'filter' => [
                            'malware' => [[
                                'constraint' => '*',
                                'url' => 'https://example.org/evil/pkg/filters.json',
                                'reason' => 'Confirmed malware',
                                'id' => 'ID-test',
                            ]],
                        ],
                    ]),
                    'headers' => ['Last-Modified: Tue, 01 Jan 2030 00:00:00 GMT'],
                ],
            ],
            true
        );

        $repository = new ComposerRepository(
            ['url' => 'https://example.org/packages.json', 'options' => ['http' => ['verify_peer' => false]]],
            new NullIO(),
            FactoryMock::createConfig(),
            $httpDownloader
        );

        ['filter' => $firstFilter] = $repository->getFilter(
            ['evil/pkg' => new Constraint('=', '1.0.0.0')],
            ['malware']
        );
        $this->assertCount(1, $firstFilter['malware']);

        // Second call on the same instance: freshMetadataUrls is populated, so no further HTTP requests should be issued.
        ['filter' => $secondFilter] = $repository->getFilter(
            ['evil/pkg' => new Constraint('=', '1.0.0.0')],
            ['malware']
        );
        $this->assertCount(1, $secondFilter['malware']);
        $this->assertSame('evil/pkg', $secondFilter['malware'][0]->packageName);
    }

    public function testGetFilterReusesCachedSummaryOn304(): void
    {
        $config = FactoryMock::createConfig();
        $repoArgs = ['url' => 'https://example.org/packages.json', 'options' => ['http' => ['verify_peer' => false]]];

        $packagesJsonBody = JsonFile::encode([
            'metadata-url' => 'https://example.org/p2/%package%.json',
            'filter' => [
                'metadata' => true,
                'lists' => ['malware' => ['enabled' => true]],
                'summary-url' => '/lists/all/summary.json',
            ],
        ]);
        $summaryBody = JsonFile::encode([
            'filter' => ['malware' => ['evil/pkg' => '*']],
        ]);
        $metadataBody = JsonFile::encode([
            'filter' => [
                'malware' => [[
                    'constraint' => '*',
                    'url' => 'https://example.org/evil/pkg/filters.json',
                    'reason' => 'Confirmed malware',
                    'id' => 'ID-test',
                ]],
            ],
        ]);

        $httpDownloader = $this->getHttpDownloaderMock();
        $httpDownloader->expects(
            [
                // First call: fresh fetches, populating the on-disk cache.
                ['url' => 'https://example.org/packages.json', 'body' => $packagesJsonBody, 'headers' => ['Last-Modified: Tue, 01 Jan 2030 00:00:00 GMT']],
                ['url' => 'https://example.org/lists/all/summary.json', 'body' => $summaryBody, 'headers' => ['Last-Modified: Tue, 01 Jan 2030 00:00:00 GMT']],
                ['url' => 'https://example.org/p2/evil/pkg.json', 'body' => $metadataBody, 'headers' => ['Last-Modified: Tue, 01 Jan 2030 00:00:00 GMT']],
                // Second call: cache age on packages.json keeps it cache-fresh; summary + metadata revalidate via 304.
                ['url' => 'https://example.org/lists/all/summary.json', 'status' => 304, 'body' => ''],
                ['url' => 'https://example.org/p2/evil/pkg.json', 'status' => 304, 'body' => ''],
            ],
            true
        );

        $firstRepo = new ComposerRepository($repoArgs, new NullIO(), $config, $httpDownloader);
        ['filter' => $firstFilter] = $firstRepo->getFilter(
            ['evil/pkg' => new Constraint('=', '1.0.0.0')],
            ['malware']
        );
        $this->assertCount(1, $firstFilter['malware']);

        $secondRepo = new ComposerRepository($repoArgs, new NullIO(), $config, $httpDownloader);
        ['filter' => $secondFilter] = $secondRepo->getFilter(
            ['evil/pkg' => new Constraint('=', '1.0.0.0')],
            ['malware']
        );

        $this->assertCount(1, $secondFilter['malware']);
        $this->assertSame('evil/pkg', $secondFilter['malware'][0]->packageName);
    }

    public function testGetFilterUsesApiUrlInsteadOfSummary(): void
    {
        $expectedApiRequestBody = json_encode([
            'packages' => ['pkg://composer/evil/pkg', 'pkg://composer/safe/pkg'],
            'lists' => ['malware'],
        ]);

        $httpDownloader = $this->getHttpDownloaderMock();
        $httpDownloader->expects(
            [
                [
                    'url' => 'https://example.org/packages.json',
                    'body' => JsonFile::encode([
                        'metadata-url' => 'https://example.org/p2/%package%.json',
                        'filter' => [
                            'metadata' => true,
                            'lists' => ['malware' => ['enabled' => true]],
                            'api-url' => '/api/filter',
                        ],
                    ]),
                    'options' => ['http' => ['verify_peer' => false]],
                ],
                [
                    'url' => 'https://example.org/api/filter',
                    'options' => [
                        'http' => [
                            'method' => 'POST',
                            'header' => ['Content-type: application/json'],
                            'timeout' => 10,
                            'content' => $expectedApiRequestBody,
                        ],
                    ],
                    'body' => JsonFile::encode([
                        'filter' => [
                            'malware' => [[
                                'package' => 'evil/pkg',
                                'constraint' => '*',
                                'url' => 'https://example.org/evil/pkg/filters.json',
                                'reason' => 'Confirmed malware',
                                'id' => 'ID-api',
                            ]],
                        ],
                    ]),
                ],
            ],
            true
        );

        $repository = new ComposerRepository(
            ['url' => 'https://example.org/packages.json', 'options' => ['http' => ['verify_peer' => false]]],
            new NullIO(),
            FactoryMock::createConfig(),
            $httpDownloader
        );

        ['filter' => $filter] = $repository->getFilter(
            [
                'evil/pkg' => new Constraint('=', '1.0.0.0'),
                'safe/pkg' => new Constraint('=', '1.0.0.0'),
            ],
            ['malware']
        );

        $this->assertArrayHasKey('malware', $filter);
        $this->assertCount(1, $filter['malware']);
        $this->assertSame('evil/pkg', $filter['malware'][0]->packageName);
        $this->assertSame('ID-api', $filter['malware'][0]->id);
    }

    public function testGetFilterSkipsApiUrlWhenMetadataAlreadyFetched(): void
    {
        // When per-package metadata has already been fetched in this run, api-url must be
        // skipped: the existing per-package metadata loop will short-circuit on the cache and
        // surface the filter entries from there. We simulate that prior fetch by seeding
        // freshMetadataUrls via reflection.
        $httpDownloader = $this->getHttpDownloaderMock();
        $httpDownloader->expects(
            [
                [
                    'url' => 'https://example.org/packages.json',
                    'body' => JsonFile::encode([
                        'metadata-url' => 'https://example.org/p2/%package%.json',
                        'filter' => [
                            'metadata' => true,
                            'lists' => ['malware' => ['enabled' => true]],
                            'api-url' => '/api/filter',
                        ],
                    ]),
                    'options' => ['http' => ['verify_peer' => false]],
                ],
                [
                    'url' => 'https://example.org/p2/evil/pkg.json',
                    'body' => JsonFile::encode([
                        'filter' => [
                            'malware' => [[
                                'constraint' => '*',
                                'url' => 'https://example.org/evil/pkg/filters.json',
                                'reason' => 'From metadata',
                                'id' => 'ID-meta',
                            ]],
                        ],
                    ]),
                    'options' => ['http' => ['verify_peer' => false]],
                ],
            ],
            true
        );

        $repository = new ComposerRepository(
            ['url' => 'https://example.org/packages.json', 'options' => ['http' => ['verify_peer' => false]]],
            new NullIO(),
            FactoryMock::createConfig(),
            $httpDownloader
        );

        // Pretend a previous metadata fetch has already happened in this run.
        $reflFresh = new \ReflectionProperty($repository, 'freshMetadataUrls');
        (\PHP_VERSION_ID < 80100) and $reflFresh->setAccessible(true);
        $reflFresh->setValue($repository, ['https://example.org/p2/some-other/pkg.json' => true]);

        ['filter' => $filter] = $repository->getFilter(
            ['evil/pkg' => new Constraint('=', '1.0.0.0')],
            ['malware']
        );

        // api-url POST is not in the expectations list — strict mode would fail if it was hit.
        // The filter entry comes from the per-package metadata file, not from api-url.
        $this->assertCount(1, $filter['malware']);
        $this->assertSame('ID-meta', $filter['malware'][0]->id);
    }

    /**
     * @return array<string, mixed>
     */
    private function generateSecurityAdvisory(string $packageName, ?string $cve, string $affectedVersions): array
    {
        return [
            'advisoryId' => uniqid('PKSA-'),
            'packageName' => $packageName,
            'remoteId' => 'test',
            'title' => 'Security Advisory',
            'link' => null,
            'cve' => $cve,
            'affectedVersions' => $affectedVersions,
            'source' => 'Tests',
            'reportedAt' => '2024-04-31 12:37:47',
            'composerRepository' => 'Package Repository',
            'severity' => 'high',
            'sources' => [
                [
                    'name' => 'Security Advisory',
                    'remoteId' => 'test',
                ],
            ],
        ];
    }
}
