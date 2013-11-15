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

use Composer\Package\Dumper\ArrayDumper;
use Composer\Package\Package;
use Composer\Repository\InstalledFilesystemRepository;
use Composer\TestCase;

class FilesystemRepositoryTest extends TestCase
{
    public function testReadPackageData()
    {
        $json = $this->createJsonFileMock();

        $repository = new InstalledFilesystemRepository($json);

        $json
            ->expects($this->once())
            ->method('read')
            ->will($this->returnValue(array(
                array(
                    'package' => array('name' => 'package1', 'version' => '1.0.0-beta', 'type' => 'vendor'),
                    'install-path' => '../package',
                )
            )));
        $json
            ->expects($this->once())
            ->method('getPath')
            ->will($this->returnValue('/var/foo/vendor/composer/installed.json'));
        $json
            ->expects($this->once())
            ->method('exists')
            ->will($this->returnValue(true));

        $packages = $repository->getPackages();

        $this->assertSame(1, count($packages));
        $this->assertSame('package1', $packages[0]->getName());
        $this->assertSame('/var/foo/vendor/package', $repository->getInstallPath($packages[0]));
    }

    /**
     * @expectedException \InvalidArgumentException
     * @expectedExceptionMessage The package "a/a-1.0.0.0" is not in the repository.
     */
    public function testGetInstallPathForUnknownPackage()
    {
        $json = $this->createJsonFileMock();

        $repository = new InstalledFilesystemRepository($json);

        $json
            ->expects($this->once())
            ->method('read')
            ->will($this->returnValue(array()));
        $json
            ->expects($this->once())
            ->method('exists')
            ->will($this->returnValue(true));

        $repository->getInstallPath(new Package('a/a', '1.0.0.0', '1.0'));
        $this->fail();
    }

    /**
     * @expectedException \InvalidArgumentException
     * @expectedExceptionMessage The installation path "../a/a" of the package "a/a-1.0.0.0" is not absolute.
     */
    public function testSetRelativeInstallPath()
    {
        $json = $this->createJsonFileMock();

        $repository = new InstalledFilesystemRepository($json);
        $repository->addPackage($a = new Package('a/a', '1.0.0.0', '1.0'));
        $repository->setInstallPath($a, '../a/a');
    }

    /**
     * @dataProvider getInstallPath
     */
    public function testInstallPath($file, $setpath, $getpath, $installPath)
    {
        $json = $this->createJsonFileMock();

        $repository = new InstalledFilesystemRepository($json);

        $package = new Package('a/a/', '1.0', '1.0');

        $dumper = new ArrayDumper();
        $installed = array(array('package' => $dumper->dump($package), 'install-path' => $installPath));

        $json
            ->expects($this->any())
            ->method('getPath')
            ->will($this->returnValue($file));
        $json
            ->expects($this->once())
            ->method('write')
            ->with($installed)
            ->will($this->returnValue(null));

        $repository->addPackage($package);
        $repository->setInstallPath($package, $setpath);

        $this->assertSame($getpath, $repository->getInstallPath($package));
        $repository->write();
    }

    public function getInstallPath()
    {
        return array(
            array('/var/vendor/composer/installed.json', '/var/vendor/a/a', '/var/vendor/a/a', '../a/a'),
            array('/var/composer/installed.json', null, null, null),
            array('d:/var/vendor/composer/installed.json', 'c:/var/vendor/a/a', 'c:/var/vendor/a/a', 'c:/var/vendor/a/a'),
            array('d:\var\vendor\composer\installed.json', 'c:\var\vendor\a\a', 'c:/var/vendor/a/a', 'c:/var/vendor/a/a'),
        );
    }

    private function createJsonFileMock()
    {
        return $this->getMockBuilder('Composer\Json\JsonFile')
            ->disableOriginalConstructor()
            ->getMock();
    }
}
