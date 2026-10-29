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

use Composer\IO\BufferIO;
use Composer\Package\Locker;
use Composer\Package\Package;
use Composer\Plugin\PluginInterface;
use Composer\IO\NullIO;
use Composer\Repository\ComposerRepository;
use Composer\Repository\FilterRepository;
use Composer\Repository\RepositoryManager;
use Composer\Test\Mock\FactoryMock;
use Composer\Test\TestCase;

class LockerTest extends TestCase
{
    public function testIsLocked()
    {
        $json = $this->createJsonFileMock();
        $locker = new Locker(
            new NullIO,
            $json,
            $this->createInstallationManagerMock(),
            $this->getJsonContent()
        );

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
        $inst = $this->createInstallationManagerMock();

        $locker = new Locker(new NullIO, $json, $inst, $this->getJsonContent());

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
        $inst = $this->createInstallationManagerMock();

        $locker = new Locker(new NullIO, $json, $inst, $this->getJsonContent());

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
        $inst = $this->createInstallationManagerMock();

        $jsonContent = $this->getJsonContent() . '  ';
        $locker = new Locker(new NullIO, $json, $inst, $jsonContent);

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
                                   'Read more about it at https://getcomposer.org/doc/01-basic-usage.md#installing-dependencies',
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
                'plugin-api-version' => PluginInterface::PLUGIN_API_VERSION,
            ));

        $locker->setLockData(array($package1, $package2), array(), array(), array(), array(), 'dev', array(), false, false, array('foo/bar' => '1.0'));
    }

    public function testLockBadPackages()
    {
        $json = $this->createJsonFileMock();
        $inst = $this->createInstallationManagerMock();

        $locker = new Locker(new NullIO, $json, $inst, $this->getJsonContent());

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
        $inst = $this->createInstallationManagerMock();

        $jsonContent = $this->getJsonContent();
        $locker = new Locker(new NullIO, $json, $inst, $jsonContent);

        $json
            ->expects($this->once())
            ->method('read')
            ->will($this->returnValue(array('hash' => md5($jsonContent))));

        $this->assertTrue($locker->isFresh());
    }

    public function testIsFreshFalse()
    {
        $json = $this->createJsonFileMock();
        $inst = $this->createInstallationManagerMock();

        $locker = new Locker(new NullIO, $json, $inst, $this->getJsonContent());

        $json
            ->expects($this->once())
            ->method('read')
            ->will($this->returnValue(array('hash' => $this->getJsonContent(array('name' => 'test2')))));

        $this->assertFalse($locker->isFresh());
    }

    public function testIsFreshWithContentHash()
    {
        $json = $this->createJsonFileMock();
        $inst = $this->createInstallationManagerMock();

        $jsonContent = $this->getJsonContent();
        $locker = new Locker(new NullIO, $json, $inst, $jsonContent);

        $json
            ->expects($this->once())
            ->method('read')
            ->will($this->returnValue(array('hash' => md5($jsonContent . '  '), 'content-hash' => md5($jsonContent))));

        $this->assertTrue($locker->isFresh());
    }

    public function testIsFreshWithContentHashAndNoHash()
    {
        $json = $this->createJsonFileMock();
        $inst = $this->createInstallationManagerMock();

        $jsonContent = $this->getJsonContent();
        $locker = new Locker(new NullIO, $json, $inst, $jsonContent);

        $json
            ->expects($this->once())
            ->method('read')
            ->will($this->returnValue(array('content-hash' => md5($jsonContent))));

        $this->assertTrue($locker->isFresh());
    }

    public function testIsFreshFalseWithContentHash()
    {
        $json = $this->createJsonFileMock();
        $inst = $this->createInstallationManagerMock();

        $locker = new Locker(new NullIO, $json, $inst, $this->getJsonContent());

        $differentHash = md5($this->getJsonContent(array('name' => 'test2')));

        $json
            ->expects($this->once())
            ->method('read')
            ->will($this->returnValue(array('hash' => $differentHash, 'content-hash' => $differentHash)));

        $this->assertFalse($locker->isFresh());
    }

    /**
     * @return \PHPUnit\Framework\MockObject\MockObject&\Composer\Json\JsonFile
     */
    private function createJsonFileMock()
    {
        return $this->getMockBuilder('Composer\Json\JsonFile')
            ->disableOriginalConstructor()
            ->getMock();
    }

    /**
     * @return \PHPUnit\Framework\MockObject\MockObject&\Composer\Installer\InstallationManager
     */
    private function createInstallationManagerMock()
    {
        $mock = $this->getMockBuilder('Composer\Installer\InstallationManager')
            ->disableOriginalConstructor()
            ->getMock();

        return $mock;
    }

    /**
     * @return \PHPUnit\Framework\MockObject\MockObject&\Composer\Package\PackageInterface
     */
    private function createPackageMock()
    {
        return $this->getMockBuilder('Composer\Package\PackageInterface')
            ->getMock();
    }

    /**
     * @param array<string, string> $customData
     *
     * @return false|string
     */
    private function getJsonContent(array $customData = array())
    {
        $data = array_merge(array(
            'minimum-stability' => 'beta',
            'name' => 'test',
        ), $customData);

        ksort($data);

        return json_encode($data);
    }

    public function testSetLockDataRemovesRestorableHttpHeaders()
    {
        $json = $this->createJsonFileMock();
        $io = new BufferIO();

        $locker = new Locker($io, $json, $this->createInstallationManagerMock(), $this->getJsonContent());
        $locker->setRepositoryManager($this->createRepositoryManagerWithHeaders());

        $packages = array(
            $this->getPackageWithDist('a/base', 'https://example.org/dists/a.zip', array('http' => array('header' => array('API-TOKEN: base')))),
            $this->getPackageWithDist('b/team', 'https://example.org/team/dists/b.zip', array('http' => array('header' => array('API-TOKEN: team'), 'timeout' => 10), 'ssl' => array('verify_peer' => false))),
            $this->getPackageWithDist('c/other-header', 'https://example.org/dists/c.zip', array('http' => array('header' => array('API-TOKEN: other')))),
            $this->getPackageWithDist('d/inline', 'https://inline.example.net/d.zip', array('http' => array('header' => array('API-TOKEN: inline')))),
        );

        $written = null;
        $json
            ->expects($this->once())
            ->method('write')
            ->will($this->returnCallback(function (array $data) use (&$written) {
                $written = $data;
            }));

        $locker->setLockData($packages, array(), array(), array(), array(), 'dev', array(), false, false, array());

        $this->assertNotNull($written);
        $transportOptions = array();
        foreach ($written['packages'] as $package) {
            $transportOptions[$package['name']] = isset($package['transport-options']) ? $package['transport-options'] : null;
        }
        $this->assertSame(array(
            'a/base' => null,
            'b/team' => array('http' => array('timeout' => 10), 'ssl' => array('verify_peer' => false)),
            'c/other-header' => array('http' => array('header' => array('API-TOKEN: other'))),
            'd/inline' => array('http' => array('header' => array('API-TOKEN: inline'))),
        ), $transportOptions);
        $this->assertStringContainsString('The transport options of c/other-header, d/inline contain http headers which will be stored in composer.lock', $io->getOutput());
        // the packages themselves keep their options for the install that follows
        $this->assertSame(array('http' => array('header' => array('API-TOKEN: base'))), $packages[0]->getTransportOptions());
    }

    public function testGetLockedRepositoryRestoresHttpHeaders()
    {
        $json = $this->createJsonFileMock();

        $locker = new Locker(new NullIO, $json, $this->createInstallationManagerMock(), $this->getJsonContent());
        $locker->setRepositoryManager($this->createRepositoryManagerWithHeaders());

        $ssl = array('ssl' => array('verify_peer' => false));
        $json->expects($this->any())->method('exists')->will($this->returnValue(true));
        $json->expects($this->any())->method('read')->will($this->returnValue(array(
            'packages' => array(
                array('name' => 'a/base', 'version' => '1.0.0', 'dist' => array('type' => 'zip', 'url' => 'https://example.org/dists/a.zip')),
                array('name' => 'b/team', 'version' => '1.0.0', 'dist' => array('type' => 'zip', 'url' => 'https://example.org/team/dists/b.zip'), 'transport-options' => $ssl),
                array('name' => 'c/stored-header', 'version' => '1.0.0', 'dist' => array('type' => 'zip', 'url' => 'https://example.org/dists/c.zip'), 'transport-options' => array('http' => array('header' => array('API-TOKEN: stored')))),
                array('name' => 'd/no-header-repo', 'version' => '1.0.0', 'dist' => array('type' => 'zip', 'url' => 'https://no-header.example.org/dists/d.zip'), 'transport-options' => $ssl),
                array('name' => 'e/elsewhere', 'version' => '1.0.0', 'dist' => array('type' => 'zip', 'url' => 'https://elsewhere.example.net/e.zip')),
            ),
        )));

        $transportOptions = array();
        foreach ($locker->getLockedRepository()->getPackages() as $package) {
            $transportOptions[$package->getName()] = $package->getTransportOptions();
        }
        $this->assertSame(array(
            'a/base' => array('http' => array('header' => array('API-TOKEN: base'))),
            'b/team' => $ssl + array('http' => array('header' => array('API-TOKEN: team'))),
            'c/stored-header' => array('http' => array('header' => array('API-TOKEN: stored'))),
            'd/no-header-repo' => $ssl,
            'e/elsewhere' => array(),
        ), $transportOptions);
    }

    /**
     * @return RepositoryManager
     */
    private function createRepositoryManagerWithHeaders()
    {
        $io = new NullIO;
        $config = FactoryMock::createConfig();
        $httpDownloader = $this->getMockBuilder('Composer\Util\HttpDownloader')->disableOriginalConstructor()->getMock();

        $rm = new RepositoryManager($io, $config, $httpDownloader);
        $rm->addRepository(new ComposerRepository(array('url' => 'https://example.org', 'options' => array('http' => array('header' => array('API-TOKEN: base')))), $io, $config, $httpDownloader));
        // added after its parent URL so the longest match has to win, and filtered as only/exclude/canonical would do
        $rm->addRepository(new FilterRepository(
            new ComposerRepository(array('url' => 'https://example.org/team', 'options' => array('http' => array('header' => array('API-TOKEN: team')))), $io, $config, $httpDownloader),
            array('canonical' => false)
        ));
        $rm->addRepository(new ComposerRepository(array('url' => 'https://no-header.example.org'), $io, $config, $httpDownloader));

        return $rm;
    }

    /**
     * @param string  $name
     * @param string  $distUrl
     * @param mixed[] $transportOptions
     *
     * @return Package
     */
    private function getPackageWithDist($name, $distUrl, array $transportOptions)
    {
        $package = new Package($name, '1.0.0.0', '1.0.0');
        $package->setDistType('zip');
        $package->setDistUrl($distUrl);
        $package->setTransportOptions($transportOptions);

        return $package;
    }
}
