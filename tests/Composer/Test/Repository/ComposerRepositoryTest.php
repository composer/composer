<?php

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
        $repoConfig = array(
            'url' => 'http://example.org',
        );

        $repository = $this->getMockBuilder('Composer\Repository\ComposerRepository')
            ->onlyMethods(array('loadRootServerFile'))
            ->setConstructorArgs(array(
                $repoConfig,
                new NullIO,
                FactoryMock::createConfig(),
                $this->getMockBuilder('Composer\Util\HttpDownloader')->disableOriginalConstructor()->getMock(),
                $this->getMockBuilder('Composer\EventDispatcher\EventDispatcher')->disableOriginalConstructor()->getMock(),
            ))
            ->getMock();

        $repository
            ->expects($this->exactly(2))
            ->method('loadRootServerFile')
            ->will($this->returnValue($repoPackages));

        // Triggers initialization
        $packages = $repository->getPackages();

        // Final sanity check, ensure the correct number of packages were added.
        $this->assertCount(count($expected), $packages);

        foreach ($expected as $index => $pkg) {
            self::assertSame($pkg['name'].' '.$pkg['version'], $packages[$index]->getName().' '.$packages[$index]->getPrettyVersion());
        }
    }

    public function loadDataProvider(): array
    {
        return array(
            // Old repository format
            array(
                array(
                    array('name' => 'foo/bar', 'version' => '1.0.0'),
                ),
                array('foo/bar' => array(
                    'name' => 'foo/bar',
                    'versions' => array(
                        '1.0.0' => array('name' => 'foo/bar', 'version' => '1.0.0'),
                    ),
                )),
            ),
            // New repository format
            array(
                array(
                    array('name' => 'bar/foo', 'version' => '3.14'),
                    array('name' => 'bar/foo', 'version' => '3.145'),
                ),
                array('packages' => array(
                    'bar/foo' => array(
                        '3.14' => array('name' => 'bar/foo', 'version' => '3.14'),
                        '3.145' => array('name' => 'bar/foo', 'version' => '3.145'),
                    ),
                )),
            ),
        );
    }

    public function testWhatProvides(): void
    {
        $repo = $this->getMockBuilder('Composer\Repository\ComposerRepository')
            ->setConstructorArgs(array(
                array('url' => 'https://dummy.test.link'),
                new NullIO,
                FactoryMock::createConfig(),
                $this->getMockBuilder('Composer\Util\HttpDownloader')->disableOriginalConstructor()->getMock(),
                $this->getMockBuilder('Composer\EventDispatcher\EventDispatcher')->disableOriginalConstructor()->getMock(),
            ))
            ->onlyMethods(array('fetchFile'))
            ->getMock();

        $cache = $this->getMockBuilder('Composer\Cache')->disableOriginalConstructor()->getMock();
        $cache->expects($this->any())
            ->method('sha256')
            ->will($this->returnValue(false));

        $properties = array(
            'cache' => $cache,
            'loader' => new ArrayLoader(),
            'providerListing' => array('a' => array('sha256' => 'xxx')),
            'providersUrl' => 'https://dummy.test.link/to/%package%/file',
        );

        foreach ($properties as $property => $value) {
            $ref = new \ReflectionProperty($repo, $property);
            $ref->setAccessible(true);
            $ref->setValue($repo, $value);
        }

        $repo->expects($this->any())
            ->method('fetchFile')
            ->will($this->returnValue(array(
                'packages' => array(
                    array(array(
                        'uid' => 1,
                        'name' => 'a',
                        'version' => 'dev-master',
                        'extra' => array('branch-alias' => array('dev-master' => '1.0.x-dev')),
                    )),
                    array(array(
                        'uid' => 2,
                        'name' => 'a',
                        'version' => 'dev-develop',
                        'extra' => array('branch-alias' => array('dev-develop' => '1.1.x-dev')),
                    )),
                    array(array(
                        'uid' => 3,
                        'name' => 'a',
                        'version' => '0.6',
                    )),
                ),
            )));

        $reflMethod = new \ReflectionMethod($repo, 'whatProvides');
        $reflMethod->setAccessible(true);
        $packages = $reflMethod->invoke($repo, 'a');

        $this->assertCount(5, $packages);
        $this->assertEquals(array('1', '1-alias', '2', '2-alias', '3'), array_keys($packages));
        $this->assertSame($packages['2'], $packages['2-alias']->getAliasOf());
    }

    public function testSearchWithType(): void
    {
        $repoConfig = array(
            'url' => 'http://example.org',
        );

        $result = array(
            'results' => array(
                array(
                    'name' => 'foo',
                    'description' => null,
                ),
            ),
        );

        $httpDownloader = $this->getHttpDownloaderMock();
        $httpDownloader->expects(
            [
                ['url' => 'http://example.org/packages.json', 'body' => JsonFile::encode(array('search' => '/search.json?q=%query%&type=%type%'))],
                ['url' => 'http://example.org/search.json?q=foo&type=composer-plugin', 'body' => JsonFile::encode($result)],
                ['url' => 'http://example.org/search.json?q=foo&type=library', 'body' => JsonFile::encode(array())],
            ],
            true
        );
        $eventDispatcher = $this->getMockBuilder('Composer\EventDispatcher\EventDispatcher')
            ->disableOriginalConstructor()
            ->getMock();

        $config = FactoryMock::createConfig();
        $config->merge(['config' => ['cache-read-only' => true]]);
        $repository = new ComposerRepository($repoConfig, new NullIO, $config, $httpDownloader, $eventDispatcher);

        $this->assertSame(
            array(array('name' => 'foo', 'description' => null)),
            $repository->search('foo', RepositoryInterface::SEARCH_FULLTEXT, 'composer-plugin')
        );

        $this->assertEmpty(
            $repository->search('foo', RepositoryInterface::SEARCH_FULLTEXT, 'library')
        );
    }

    public function testSearchWithSpecialChars(): void
    {
        $repoConfig = array(
            'url' => 'http://example.org',
        );

        $httpDownloader = $this->getHttpDownloaderMock();
        $httpDownloader->expects(
            [
                ['url' => 'http://example.org/packages.json', 'body' => JsonFile::encode(array('search' => '/search.json?q=%query%&type=%type%'))],
                ['url' => 'http://example.org/search.json?q=foo+bar&type=', 'body' => JsonFile::encode(array())],
            ],
            true
        );
        $eventDispatcher = $this->getMockBuilder('Composer\EventDispatcher\EventDispatcher')
            ->disableOriginalConstructor()
            ->getMock();

        $config = FactoryMock::createConfig();
        $config->merge(['config' => ['cache-read-only' => true]]);
        $repository = new ComposerRepository($repoConfig, new NullIO, $config, $httpDownloader, $eventDispatcher);

        $this->assertEmpty(
            $repository->search('foo bar', RepositoryInterface::SEARCH_FULLTEXT)
        );
    }

    public function testSearchWithAbandonedPackages(): void
    {
        $repoConfig = array(
            'url' => 'http://example.org',
        );

        $result = array(
            'results' => array(
                array(
                    'name' => 'foo1',
                    'description' => null,
                    'abandoned' => true,
                ),
                array(
                    'name' => 'foo2',
                    'description' => null,
                    'abandoned' => 'bar',
                ),
            ),
        );

        $httpDownloader = $this->getHttpDownloaderMock();
        $httpDownloader->expects(
            [
                ['url' => 'http://example.org/packages.json', 'body' => JsonFile::encode(array('search' => '/search.json?q=%query%'))],
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

        $this->assertSame(
            array(
                array('name' => 'foo1', 'description' => null, 'abandoned' => true),
                array('name' => 'foo2', 'description' => null, 'abandoned' => 'bar'),
            ),
            $repository->search('foo')
        );
    }

    /**
     * @dataProvider provideCanonicalizeUrlTestCases
     *
     * @param string $expected
     * @param string $url
     * @param string $repositoryUrl
     */
    public function testCanonicalizeUrl($expected, $url, $repositoryUrl): void
    {
        $repository = new ComposerRepository(
            array('url' => $repositoryUrl),
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

        $this->assertSame($expected, $method->invoke($repository, $url));
    }

    public function provideCanonicalizeUrlTestCases(): array
    {
        return array(
            array(
                'https://example.org/path/to/file',
                '/path/to/file',
                'https://example.org',
            ),
            array(
                'https://example.org/canonic_url',
                'https://example.org/canonic_url',
                'https://should-not-see-me.test',
            ),
            array(
                'file:///path/to/repository/file',
                '/path/to/repository/file',
                'file:///path/to/repository',
            ),
            array(
                // Assert that the repository URL is returned unchanged if it is
                // not a URL.
                // (Backward compatibility test)
                'invalid_repo_url',
                '/path/to/file',
                'invalid_repo_url',
            ),
            array(
                // Assert that URLs can contain sequences resembling pattern
                // references as understood by preg_replace() without messing up
                // the result.
                // (Regression test)
                'https://example.org/path/to/unusual_$0_filename',
                '/path/to/unusual_$0_filename',
                'https://example.org',
            ),
        );
    }

    public function testGetProviderNamesWillReturnPartialPackageNames(): void
    {
        $httpDownloader = $this->getHttpDownloaderMock();
        $httpDownloader->expects(
            [
                [
                    'url' => 'http://example.org/packages.json',
                    'body' => JsonFile::encode(array(
                        'providers-lazy-url' => '/foo/p/%package%.json',
                        'packages' => array('foo/bar' => array(
                            'dev-branch' => array('name' => 'foo/bar'),
                            'v1.0.0' => array('name' => 'foo/bar'),
                        )),
                    )),
                ],
            ],
            true
        );

        $repository = new ComposerRepository(
            array('url' => 'http://example.org/packages.json'),
            new NullIO(),
            FactoryMock::createConfig(),
            $httpDownloader
        );

        $this->assertEquals(array('foo/bar'), $repository->getPackageNames());
    }
}
