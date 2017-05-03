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
use Composer\Repository\ComposerRepository;
use Composer\Repository\RepositoryInterface;
use Composer\Test\Mock\FactoryMock;
use Composer\TestCase;
use Composer\Package\Loader\ArrayLoader;
use Composer\Semver\VersionParser;

class ComposerRepositoryTest extends TestCase
{
    /**
     * @dataProvider loadDataProvider
     */
    public function testLoadData(array $expected, array $repoPackages)
    {
        $repoConfig = array(
            'url' => 'http://example.org',
        );

        $repository = $this->getMock(
            'Composer\Repository\ComposerRepository',
            array(
                'loadRootServerFile',
                'createPackage',
            ),
            array(
                $repoConfig,
                new NullIO,
                FactoryMock::createConfig(),
            )
        );

        $repository
            ->expects($this->exactly(2))
            ->method('loadRootServerFile')
            ->will($this->returnValue($repoPackages));

        foreach ($expected as $at => $arg) {
            $stubPackage = $this->getPackage('stub/stub', '1.0.0');

            $repository
                ->expects($this->at($at + 2))
                ->method('createPackage')
                ->with($this->identicalTo($arg), $this->equalTo('Composer\Package\CompletePackage'))
                ->will($this->returnValue($stubPackage));
        }

        // Triggers initialization
        $packages = $repository->getPackages();

        // Final sanity check, ensure the correct number of packages were added.
        $this->assertCount(count($expected), $packages);
    }

    public function loadDataProvider()
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

    public function testWhatProvides()
    {
        $repo = $this->getMockBuilder('Composer\Repository\ComposerRepository')
            ->disableOriginalConstructor()
            ->setMethods(array('fetchFile'))
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

        $pool = $this->getMock('Composer\DependencyResolver\Pool');
        $pool->expects($this->any())
            ->method('isPackageAcceptable')
            ->will($this->returnValue(true));

        $versionParser = new VersionParser();
        $repo->setRootAliases(array(
            'a' => array(
                $versionParser->normalize('0.6') => array('alias' => 'dev-feature', 'alias_normalized' => $versionParser->normalize('dev-feature')),
                $versionParser->normalize('1.1.x-dev') => array('alias' => '1.0', 'alias_normalized' => $versionParser->normalize('1.0')),
            ),
        ));

        $packages = $repo->whatProvides($pool, 'a');

        $this->assertCount(7, $packages);
        $this->assertEquals(array('1', '1-alias', '2', '2-alias', '2-root', '3', '3-root'), array_keys($packages));
        $this->assertInstanceOf('Composer\Package\AliasPackage', $packages['2-root']);
        $this->assertSame($packages['2'], $packages['2-root']->getAliasOf());
        $this->assertSame($packages['2'], $packages['2-alias']->getAliasOf());
    }

    public function testSearchWithType()
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

        $rfs = $this->getMockBuilder('Composer\Util\RemoteFilesystem')
            ->disableOriginalConstructor()
            ->getMock();

        $rfs->expects($this->at(0))
            ->method('getContents')
            ->with('example.org', 'http://example.org/packages.json', false)
            ->willReturn(json_encode(array('search' => '/search.json?q=%query%&type=%type%')));

        $rfs->expects($this->at(1))
            ->method('getContents')
            ->with('example.org', 'http://example.org/search.json?q=foo&type=composer-plugin', false)
            ->willReturn(json_encode($result));

        $repository = new ComposerRepository($repoConfig, new NullIO, FactoryMock::createConfig(), null, $rfs);

        $this->assertSame(
            array(array('name' => 'foo', 'description' => null)),
            $repository->search('foo', RepositoryInterface::SEARCH_FULLTEXT, 'composer-plugin')
        );

        $this->assertEmpty(
            $repository->search('foo', RepositoryInterface::SEARCH_FULLTEXT, 'library')
        );
    }
}
