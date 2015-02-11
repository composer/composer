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

namespace Composer\Repository\Pear;

use Composer\TestCase;
use Composer\Package\Version\VersionParser;
use Composer\Package\LinkConstraint\VersionConstraint;
use Composer\Package\Link;
use Composer\Package\CompletePackage;
use Composer\Test\Mock\RemoteFilesystemMock;

class ChannelReaderTest extends TestCase
{
    public function testShouldBuildPackagesFromPearSchema()
    {
        $rfs = new RemoteFilesystemMock(array(
            'http://pear.net/channel.xml' => file_get_contents(__DIR__ . '/Fixtures/channel.1.1.xml'),
            'http://test.loc/rest11/c/categories.xml' => file_get_contents(__DIR__ . '/Fixtures/Rest1.1/categories.xml'),
            'http://test.loc/rest11/c/Default/packagesinfo.xml' => file_get_contents(__DIR__ . '/Fixtures/Rest1.1/packagesinfo.xml'),
        ));

        $reader = new \Composer\Repository\Pear\ChannelReader($rfs);

        $channelInfo = $reader->read('http://pear.net/');
        $packages = $channelInfo->getPackages();

        $this->assertCount(3, $packages);
        $this->assertEquals('HTTP_Client', $packages[0]->getPackageName());
        $this->assertEquals('HTTP_Request', $packages[1]->getPackageName());
        $this->assertEquals('MDB2', $packages[2]->getPackageName());

        $mdb2releases = $packages[2]->getReleases();
        $this->assertEquals(9, count($mdb2releases['2.4.0']->getDependencyInfo()->getOptionals()));
    }

    public function testShouldSelectCorrectReader()
    {
        $rfs = new RemoteFilesystemMock(array(
            'http://pear.1.0.net/channel.xml' => file_get_contents(__DIR__ . '/Fixtures/channel.1.0.xml'),
            'http://test.loc/rest10/p/packages.xml' => file_get_contents(__DIR__ . '/Fixtures/Rest1.0/packages.xml'),
            'http://test.loc/rest10/p/http_client/info.xml' => file_get_contents(__DIR__ . '/Fixtures/Rest1.0/http_client_info.xml'),
            'http://test.loc/rest10/p/http_request/info.xml' => file_get_contents(__DIR__ . '/Fixtures/Rest1.0/http_request_info.xml'),
            'http://pear.1.1.net/channel.xml' => file_get_contents(__DIR__ . '/Fixtures/channel.1.1.xml'),
            'http://test.loc/rest11/c/categories.xml' => file_get_contents(__DIR__ . '/Fixtures/Rest1.1/categories.xml'),
            'http://test.loc/rest11/c/Default/packagesinfo.xml' => file_get_contents(__DIR__ . '/Fixtures/Rest1.1/packagesinfo.xml'),
        ));

        $reader = new \Composer\Repository\Pear\ChannelReader($rfs);

        $reader->read('http://pear.1.0.net/');
        $reader->read('http://pear.1.1.net/');
    }

    public function testShouldCreatePackages()
    {
        $reader = $this->getMockBuilder('\Composer\Repository\PearRepository')
            ->disableOriginalConstructor()
            ->getMock();

        $ref = new \ReflectionMethod($reader, 'buildComposerPackages');
        $ref->setAccessible(true);

        $channelInfo = new ChannelInfo(
            'test.loc',
            'test',
            array(
                new PackageInfo(
                    'test.loc',
                    'sample',
                    'license',
                    'shortDescription',
                    'description',
                    array(
                        '1.0.0.1' => new ReleaseInfo(
                            'stable',
                            new DependencyInfo(
                                array(
                                    new DependencyConstraint(
                                        'required',
                                        '> 5.2.0.0',
                                        'php',
                                        ''
                                    ),
                                    new DependencyConstraint(
                                        'conflicts',
                                        '== 2.5.6.0',
                                        'pear.php.net',
                                        'broken'
                                    ),
                                ),
                                array(
                                    '*' => array(
                                        new DependencyConstraint(
                                            'optional',
                                            '*',
                                            'ext',
                                            'xml'
                                        ),
                                    )
                                )
                            )
                        )
                    )
                )
            )
        );

        $packages = $ref->invoke($reader, $channelInfo, new VersionParser());

        $expectedPackage = new CompletePackage('pear-test.loc/sample', '1.0.0.1' , '1.0.0.1');
        $expectedPackage->setType('pear-library');
        $expectedPackage->setDistType('file');
        $expectedPackage->setDescription('description');
        $expectedPackage->setLicense(array('license'));
        $expectedPackage->setDistUrl("http://test.loc/get/sample-1.0.0.1.tgz");
        $expectedPackage->setAutoload(array('classmap' => array('')));
        $expectedPackage->setIncludePaths(array('/'));
        $expectedPackage->setRequires(array(
            new Link('pear-test.loc/sample', 'php', $this->createConstraint('>', '5.2.0.0'), 'required', '> 5.2.0.0'),
        ));
        $expectedPackage->setConflicts(array(
            new Link('pear-test.loc/sample', 'pear-pear.php.net/broken', $this->createConstraint('==', '2.5.6.0'), 'conflicts', '== 2.5.6.0'),
        ));
        $expectedPackage->setSuggests(array(
            '*-ext-xml' => '*',
        ));
        $expectedPackage->setReplaces(array(
            new Link('pear-test.loc/sample', 'pear-test/sample', new VersionConstraint('==', '1.0.0.1'), 'replaces', '== 1.0.0.1'),
        ));

        $this->assertCount(1, $packages);
        $this->assertEquals($expectedPackage, $packages[0], 0, 1);
    }

    private function createConstraint($operator, $version)
    {
        $constraint = new VersionConstraint($operator, $version);
        $constraint->setPrettyString($operator.' '.$version);

        return $constraint;
    }
}
