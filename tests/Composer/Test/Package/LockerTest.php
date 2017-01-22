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

namespace Composer\Test\Package;

use Composer\Package\Locker;
use Composer\IO\NullIO;

class LockerTest extends \PHPUnit_Framework_TestCase
{
    public function testIsLocked()
    {
        $json   = $this->createJsonFileMock();
        $locker = new Locker(new NullIO, $json, $this->createRepositoryManagerMock(), $this->createInstallationManagerMock(),
            $this->getJsonContent());

        $json
            ->expects($this->any())
            ->method('exists')
            ->will($this->returnValue(true));
        $json
            ->expects($this->any())
            ->method('read')
            ->will($this->returnValue(array('packages' => array())));

        $this->assertTrue($locker->isLocked());
    }

    public function testGetNotLockedPackages()
    {
        $json = $this->createJsonFileMock();
        $repo = $this->createRepositoryManagerMock();
        $inst = $this->createInstallationManagerMock();

        $locker = new Locker(new NullIO, $json, $repo, $inst, $this->getJsonContent());

        $json
            ->expects($this->once())
            ->method('exists')
            ->will($this->returnValue(false));

        $this->setExpectedException('LogicException');

        $locker->getLockedRepository();
    }

    public function testGetLockedPackages()
    {
        $json = $this->createJsonFileMock();
        $repo = $this->createRepositoryManagerMock();
        $inst = $this->createInstallationManagerMock();

        $locker = new Locker(new NullIO, $json, $repo, $inst, $this->getJsonContent());

        $json
            ->expects($this->once())
            ->method('exists')
            ->will($this->returnValue(true));
        $json
            ->expects($this->once())
            ->method('read')
            ->will($this->returnValue(array(
                'packages' => array(
                    array('name' => 'pkg1', 'version' => '1.0.0-beta'),
                    array('name' => 'pkg2', 'version' => '0.1.10'),
                ),
            )));

        $repo = $locker->getLockedRepository();
        $this->assertNotNull($repo->findPackage('pkg1', '1.0.0-beta'));
        $this->assertNotNull($repo->findPackage('pkg2', '0.1.10'));
    }

    public function testSetLockData()
    {
        $json = $this->createJsonFileMock();
        $repo = $this->createRepositoryManagerMock();
        $inst = $this->createInstallationManagerMock();

        $jsonContent = $this->getJsonContent() . '  ';
        $locker = new Locker(new NullIO, $json, $repo, $inst, $jsonContent);

        $package1 = $this->createPackageMock();
        $package2 = $this->createPackageMock();

        $package1
            ->expects($this->atLeastOnce())
            ->method('getPrettyName')
            ->will($this->returnValue('pkg1'));
        $package1
            ->expects($this->atLeastOnce())
            ->method('getPrettyVersion')
            ->will($this->returnValue('1.0.0-beta'));
        $package1
            ->expects($this->atLeastOnce())
            ->method('getVersion')
            ->will($this->returnValue('1.0.0.0-beta'));

        $package2
            ->expects($this->atLeastOnce())
            ->method('getPrettyName')
            ->will($this->returnValue('pkg2'));
        $package2
            ->expects($this->atLeastOnce())
            ->method('getPrettyVersion')
            ->will($this->returnValue('0.1.10'));
        $package2
            ->expects($this->atLeastOnce())
            ->method('getVersion')
            ->will($this->returnValue('0.1.10.0'));

        foreach (array($package1, $package2) as $package) {
            $package
                ->expects($this->atLeastOnce())
                ->method('getTransportOptions')
                ->will($this->returnValue(array()));
        }

        $contentHash = md5(trim($jsonContent));

        $json
            ->expects($this->once())
            ->method('write')
            ->with(array(
                '_readme' => array('This file locks the dependencies of your project to a known state',
                                   'Read more about it at https://getcomposer.org/doc/01-basic-usage.md#composer-lock-the-lock-file',
                                   'This file is @gener'.'ated automatically', ),
                'content-hash' => $contentHash,
                'packages' => array(
                    array('name' => 'pkg1', 'version' => '1.0.0-beta'),
                    array('name' => 'pkg2', 'version' => '0.1.10'),
                ),
                'packages-dev' => array(),
                'aliases' => array(),
                'minimum-stability' => 'dev',
                'stability-flags' => array(),
                'platform' => array(),
                'platform-dev' => array(),
                'platform-overrides' => array('foo/bar' => '1.0'),
                'prefer-stable' => false,
                'prefer-lowest' => false,
            ));

        $locker->setLockData(array($package1, $package2), array(), array(), array(), array(), 'dev', array(), false, false, array('foo/bar' => '1.0'));
    }

    public function testLockBadPackages()
    {
        $json = $this->createJsonFileMock();
        $repo = $this->createRepositoryManagerMock();
        $inst = $this->createInstallationManagerMock();

        $locker = new Locker(new NullIO, $json, $repo, $inst, $this->getJsonContent());

        $package1 = $this->createPackageMock();
        $package1
            ->expects($this->once())
            ->method('getPrettyName')
            ->will($this->returnValue('pkg1'));

        $this->setExpectedException('LogicException');

        $locker->setLockData(array($package1), array(), array(), array(), array(), 'dev', array(), false, false, array());
    }

    public function testIsFresh()
    {
        $json = $this->createJsonFileMock();
        $repo = $this->createRepositoryManagerMock();
        $inst = $this->createInstallationManagerMock();

        $jsonContent = $this->getJsonContent();
        $locker = new Locker(new NullIO, $json, $repo, $inst, $jsonContent);

        $json
            ->expects($this->once())
            ->method('read')
            ->will($this->returnValue(array('hash' => md5($jsonContent))));

        $this->assertTrue($locker->isFresh());
    }

    public function testIsFreshFalse()
    {
        $json = $this->createJsonFileMock();
        $repo = $this->createRepositoryManagerMock();
        $inst = $this->createInstallationManagerMock();

        $locker = new Locker(new NullIO, $json, $repo, $inst, $this->getJsonContent());

        $json
            ->expects($this->once())
            ->method('read')
            ->will($this->returnValue(array('hash' => $this->getJsonContent(array('name' => 'test2')))));

        $this->assertFalse($locker->isFresh());
    }

    public function testIsFreshWithContentHash()
    {
        $json = $this->createJsonFileMock();
        $repo = $this->createRepositoryManagerMock();
        $inst = $this->createInstallationManagerMock();

        $jsonContent = $this->getJsonContent();
        $locker = new Locker(new NullIO, $json, $repo, $inst, $jsonContent);

        $json
            ->expects($this->once())
            ->method('read')
            ->will($this->returnValue(array('hash' => md5($jsonContent . '  '), 'content-hash' => md5($jsonContent))));

        $this->assertTrue($locker->isFresh());
    }

    public function testIsFreshWithContentHashAndNoHash()
    {
        $json = $this->createJsonFileMock();
        $repo = $this->createRepositoryManagerMock();
        $inst = $this->createInstallationManagerMock();

        $jsonContent = $this->getJsonContent();
        $locker = new Locker(new NullIO, $json, $repo, $inst, $jsonContent);

        $json
            ->expects($this->once())
            ->method('read')
            ->will($this->returnValue(array('content-hash' => md5($jsonContent))));

        $this->assertTrue($locker->isFresh());
    }

    public function testIsFreshFalseWithContentHash()
    {
        $json = $this->createJsonFileMock();
        $repo = $this->createRepositoryManagerMock();
        $inst = $this->createInstallationManagerMock();

        $locker = new Locker(new NullIO, $json, $repo, $inst, $this->getJsonContent());

        $differentHash = md5($this->getJsonContent(array('name' => 'test2')));

        $json
            ->expects($this->once())
            ->method('read')
            ->will($this->returnValue(array('hash' => $differentHash, 'content-hash' => $differentHash)));

        $this->assertFalse($locker->isFresh());
    }

    private function createJsonFileMock()
    {
        return $this->getMockBuilder('Composer\Json\JsonFile')
            ->disableOriginalConstructor()
            ->getMock();
    }

    private function createRepositoryManagerMock()
    {
        $mock = $this->getMockBuilder('Composer\Repository\RepositoryManager')
            ->disableOriginalConstructor()
            ->getMock();

        $mock->expects($this->any())
            ->method('getLocalRepository')
            ->will($this->returnValue($this->getMockBuilder('Composer\Repository\ArrayRepository')->getMock()));

        return $mock;
    }

    private function createInstallationManagerMock()
    {
        $mock = $this->getMockBuilder('Composer\Installer\InstallationManager')
            ->disableOriginalConstructor()
            ->getMock();

        return $mock;
    }

    private function createPackageMock()
    {
        return $this->getMockBuilder('Composer\Package\PackageInterface')
            ->getMock();
    }

    private function getJsonContent(array $customData = array())
    {
        $data = array_merge(array(
            'minimum-stability' => 'beta',
            'name' => 'test',
        ), $customData);

        ksort($data);

        return json_encode($data);
    }
}
