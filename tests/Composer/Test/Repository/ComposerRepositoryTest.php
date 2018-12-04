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
use Composer\Test\TestCase;
use Composer\Package\Loader\ArrayLoader;
use Composer\Package\Version\VersionParser;

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

        $repository = $this->getMockBuilder('Composer\Repository\ComposerRepository')
            ->setMethods(array('loadRootServerFile', 'createPackages'))
            ->setConstructorArgs(array(
                $repoConfig,
                new NullIO,
                FactoryMock::createConfig(),
                $this->getMockBuilder('Composer\Util\HttpDownloader')->disableOriginalConstructor()->getMock(),
                $this->getMockBuilder('Composer\EventDispatcher\EventDispatcher')->disableOriginalConstructor()->getMock()
            ))
            ->getMock();

        $repository
            ->expects($this->exactly(2))
            ->method('loadRootServerFile')
            ->will($this->returnValue($repoPackages));

        $stubs = array();
        foreach ($expected as $at => $arg) {
            $stubs[] = $this->getPackage('stub/stub', '1.0.0');
        }

        $repository
            ->expects($this->at(2))
            ->method('createPackages')
            ->with($this->identicalTo($expected), $this->equalTo('Composer\Package\CompletePackage'))
            ->will($this->returnValue($stubs));

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

        $versionParser = new VersionParser();
        $repo->setRootAliases(array(
            'a' => array(
                $versionParser->normalize('0.6') => array('alias' => 'dev-feature', 'alias_normalized' => $versionParser->normalize('dev-feature')),
                $versionParser->normalize('1.1.x-dev') => array('alias' => '1.0', 'alias_normalized' => $versionParser->normalize('1.0')),
            ),
        ));

        $packages = $repo->whatProvides('a', false, array($this, 'isPackageAcceptableReturnTrue'));

        $this->assertCount(7, $packages);
        $this->assertEquals(array('1', '1-alias', '2', '2-alias', '2-root', '3', '3-root'), array_keys($packages));
        $this->assertInstanceOf('Composer\Package\AliasPackage', $packages['2-root']);
        $this->assertSame($packages['2'], $packages['2-root']->getAliasOf());
        $this->assertSame($packages['2'], $packages['2-alias']->getAliasOf());
    }

    public function isPackageAcceptableReturnTrue()
    {
        return true;
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

        $httpDownloader = $this->getMockBuilder('Composer\Util\HttpDownloader')
            ->disableOriginalConstructor()
            ->getMock();
        $eventDispatcher = $this->getMockBuilder('Composer\EventDispatcher\EventDispatcher')
            ->disableOriginalConstructor()
            ->getMock();

        $httpDownloader->expects($this->at(0))
            ->method('get')
            ->with($url = 'http://example.org/packages.json')
            ->willReturn(new \Composer\Util\Http\Response(array('url' => $url), 200, array(), json_encode(array('search' => '/search.json?q=%query%&type=%type%'))));

        $httpDownloader->expects($this->at(1))
            ->method('get')
            ->with($url = 'http://example.org/search.json?q=foo&type=composer-plugin')
            ->willReturn(new \Composer\Util\Http\Response(array('url' => $url), 200, array(), json_encode($result)));

        $httpDownloader->expects($this->at(2))
            ->method('get')
            ->with($url = 'http://example.org/search.json?q=foo&type=library')
            ->willReturn(new \Composer\Util\Http\Response(array('url' => $url), 200, array(), json_encode(array())));

        $repository = new ComposerRepository($repoConfig, new NullIO, FactoryMock::createConfig(), $httpDownloader, $eventDispatcher);

        $this->assertSame(
            array(array('name' => 'foo', 'description' => null)),
            $repository->search('foo', RepositoryInterface::SEARCH_FULLTEXT, 'composer-plugin')
        );

        $this->assertEmpty(
            $repository->search('foo', RepositoryInterface::SEARCH_FULLTEXT, 'library')
        );
    }
}
