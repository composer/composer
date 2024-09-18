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

use Composer\IO\NullIO;
use Composer\Json\JsonFile;
use Composer\Repository\ComposerRepository;
use Composer\Repository\RepositoryInterface;
use Composer\Semver\Constraint\Constraint;
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
            $ref->setAccessible(true);
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
        $reflMethod->setAccessible(true);
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
        $method->setAccessible(true);

        // ComposerRepository::__construct ensures that the repository URL has a
        // protocol, so reset it here in order to test all cases.
        $property = $object->getProperty('url');
        $property->setAccessible(true);
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
                ]
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
}
