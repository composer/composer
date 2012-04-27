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

namespace Composer\Test\Downloader;

use Composer\Downloader\DownloadManager;

class DownloadManagerTest extends \PHPUnit_Framework_TestCase
{
    protected $filesystem;

    public function setUp()
    {
        $this->filesystem = $this->getMock('Composer\Util\Filesystem');
    }

    public function testSetGetDownloader()
    {
        $downloader = $this->createDownloaderMock();
        $manager    = new DownloadManager(false, $this->filesystem);

        $manager->setDownloader('test', $downloader);
        $this->assertSame($downloader, $manager->getDownloader('test'));

        $this->setExpectedException('InvalidArgumentException');
        $manager->getDownloader('unregistered');
    }

    public function testGetDownloaderForIncorrectlyInstalledPackage()
    {
        $package = $this->createPackageMock();
        $package
            ->expects($this->once())
            ->method('getInstallationSource')
            ->will($this->returnValue(null));

        $manager = new DownloadManager(false, $this->filesystem);

        $this->setExpectedException('InvalidArgumentException');

        $manager->getDownloaderForInstalledPackage($package);
    }

    public function testGetDownloaderForCorrectlyInstalledDistPackage()
    {
        $package = $this->createPackageMock();
        $package
            ->expects($this->once())
            ->method('getInstallationSource')
            ->will($this->returnValue('dist'));
        $package
            ->expects($this->once())
            ->method('getDistType')
            ->will($this->returnValue('pear'));

        $downloader = $this->createDownloaderMock();
        $downloader
            ->expects($this->once())
            ->method('getInstallationSource')
            ->will($this->returnValue('dist'));

        $manager = $this->getMockBuilder('Composer\Downloader\DownloadManager')
            ->setConstructorArgs(array(false, $this->filesystem))
            ->setMethods(array('getDownloader'))
            ->getMock();

        $manager
            ->expects($this->once())
            ->method('getDownloader')
            ->with('pear')
            ->will($this->returnValue($downloader));

        $this->assertSame($downloader, $manager->getDownloaderForInstalledPackage($package));
    }

    public function testGetDownloaderForIncorrectlyInstalledDistPackage()
    {
        $package = $this->createPackageMock();
        $package
            ->expects($this->once())
            ->method('getInstallationSource')
            ->will($this->returnValue('dist'));
        $package
            ->expects($this->once())
            ->method('getDistType')
            ->will($this->returnValue('git'));

        $downloader = $this->createDownloaderMock();
        $downloader
            ->expects($this->exactly(2))
            ->method('getInstallationSource')
            ->will($this->returnValue('source'));

        $manager = $this->getMockBuilder('Composer\Downloader\DownloadManager')
            ->setConstructorArgs(array(false, $this->filesystem))
            ->setMethods(array('getDownloader'))
            ->getMock();

        $manager
            ->expects($this->once())
            ->method('getDownloader')
            ->with('git')
            ->will($this->returnValue($downloader));

        $this->setExpectedException('LogicException');

        $manager->getDownloaderForInstalledPackage($package);
    }

    public function testGetDownloaderForCorrectlyInstalledSourcePackage()
    {
        $package = $this->createPackageMock();
        $package
            ->expects($this->once())
            ->method('getInstallationSource')
            ->will($this->returnValue('source'));
        $package
            ->expects($this->once())
            ->method('getSourceType')
            ->will($this->returnValue('git'));

        $downloader = $this->createDownloaderMock();
        $downloader
            ->expects($this->once())
            ->method('getInstallationSource')
            ->will($this->returnValue('source'));

        $manager = $this->getMockBuilder('Composer\Downloader\DownloadManager')
            ->setConstructorArgs(array(false, $this->filesystem))
            ->setMethods(array('getDownloader'))
            ->getMock();

        $manager
            ->expects($this->once())
            ->method('getDownloader')
            ->with('git')
            ->will($this->returnValue($downloader));

        $this->assertSame($downloader, $manager->getDownloaderForInstalledPackage($package));
    }

    public function testGetDownloaderForIncorrectlyInstalledSourcePackage()
    {
        $package = $this->createPackageMock();
        $package
            ->expects($this->once())
            ->method('getInstallationSource')
            ->will($this->returnValue('source'));
        $package
            ->expects($this->once())
            ->method('getSourceType')
            ->will($this->returnValue('pear'));

        $downloader = $this->createDownloaderMock();
        $downloader
            ->expects($this->exactly(2))
            ->method('getInstallationSource')
            ->will($this->returnValue('dist'));

        $manager = $this->getMockBuilder('Composer\Downloader\DownloadManager')
            ->setConstructorArgs(array(false, $this->filesystem))
            ->setMethods(array('getDownloader'))
            ->getMock();

        $manager
            ->expects($this->once())
            ->method('getDownloader')
            ->with('pear')
            ->will($this->returnValue($downloader));

        $this->setExpectedException('LogicException');

        $manager->getDownloaderForInstalledPackage($package);
    }

    public function testFullPackageDownload()
    {
        $package = $this->createPackageMock();
        $package
            ->expects($this->once())
            ->method('getSourceType')
            ->will($this->returnValue('git'));
        $package
            ->expects($this->once())
            ->method('getDistType')
            ->will($this->returnValue('pear'));

        $package
            ->expects($this->once())
            ->method('setInstallationSource')
            ->with('dist');

        $downloader = $this->createDownloaderMock();
        $downloader
            ->expects($this->once())
            ->method('download')
            ->with($package, 'target_dir');

        $manager = $this->getMockBuilder('Composer\Downloader\DownloadManager')
            ->setConstructorArgs(array(false, $this->filesystem))
            ->setMethods(array('getDownloaderForInstalledPackage'))
            ->getMock();
        $manager
            ->expects($this->once())
            ->method('getDownloaderForInstalledPackage')
            ->with($package)
            ->will($this->returnValue($downloader));

        $manager->download($package, 'target_dir');
    }

    public function testBadPackageDownload()
    {
        $package = $this->createPackageMock();
        $package
            ->expects($this->once())
            ->method('getSourceType')
            ->will($this->returnValue(null));
        $package
            ->expects($this->once())
            ->method('getDistType')
            ->will($this->returnValue(null));

        $manager = new DownloadManager(false, $this->filesystem);

        $this->setExpectedException('InvalidArgumentException');
        $manager->download($package, 'target_dir');
    }

    public function testDistOnlyPackageDownload()
    {
        $package = $this->createPackageMock();
        $package
            ->expects($this->once())
            ->method('getSourceType')
            ->will($this->returnValue(null));
        $package
            ->expects($this->once())
            ->method('getDistType')
            ->will($this->returnValue('pear'));

        $package
            ->expects($this->once())
            ->method('setInstallationSource')
            ->with('dist');

        $downloader = $this->createDownloaderMock();
        $downloader
            ->expects($this->once())
            ->method('download')
            ->with($package, 'target_dir');

        $manager = $this->getMockBuilder('Composer\Downloader\DownloadManager')
            ->setConstructorArgs(array(false, $this->filesystem))
            ->setMethods(array('getDownloaderForInstalledPackage'))
            ->getMock();
        $manager
            ->expects($this->once())
            ->method('getDownloaderForInstalledPackage')
            ->with($package)
            ->will($this->returnValue($downloader));

        $manager->download($package, 'target_dir');
    }

    public function testSourceOnlyPackageDownload()
    {
        $package = $this->createPackageMock();
        $package
            ->expects($this->once())
            ->method('getSourceType')
            ->will($this->returnValue('git'));
        $package
            ->expects($this->once())
            ->method('getDistType')
            ->will($this->returnValue(null));

        $package
            ->expects($this->once())
            ->method('setInstallationSource')
            ->with('source');

        $downloader = $this->createDownloaderMock();
        $downloader
            ->expects($this->once())
            ->method('download')
            ->with($package, 'target_dir');

        $manager = $this->getMockBuilder('Composer\Downloader\DownloadManager')
            ->setConstructorArgs(array(false, $this->filesystem))
            ->setMethods(array('getDownloaderForInstalledPackage'))
            ->getMock();
        $manager
            ->expects($this->once())
            ->method('getDownloaderForInstalledPackage')
            ->with($package)
            ->will($this->returnValue($downloader));

        $manager->download($package, 'target_dir');
    }

    public function testFullPackageDownloadWithSourcePreferred()
    {
        $package = $this->createPackageMock();
        $package
            ->expects($this->once())
            ->method('getSourceType')
            ->will($this->returnValue('git'));
        $package
            ->expects($this->once())
            ->method('getDistType')
            ->will($this->returnValue('pear'));

        $package
            ->expects($this->once())
            ->method('setInstallationSource')
            ->with('source');

        $downloader = $this->createDownloaderMock();
        $downloader
            ->expects($this->once())
            ->method('download')
            ->with($package, 'target_dir');

        $manager = $this->getMockBuilder('Composer\Downloader\DownloadManager')
            ->setConstructorArgs(array(false, $this->filesystem))
            ->setMethods(array('getDownloaderForInstalledPackage'))
            ->getMock();
        $manager
            ->expects($this->once())
            ->method('getDownloaderForInstalledPackage')
            ->with($package)
            ->will($this->returnValue($downloader));

        $manager->setPreferSource(true);
        $manager->download($package, 'target_dir');
    }

    public function testDistOnlyPackageDownloadWithSourcePreferred()
    {
        $package = $this->createPackageMock();
        $package
            ->expects($this->once())
            ->method('getSourceType')
            ->will($this->returnValue(null));
        $package
            ->expects($this->once())
            ->method('getDistType')
            ->will($this->returnValue('pear'));

        $package
            ->expects($this->once())
            ->method('setInstallationSource')
            ->with('dist');

        $downloader = $this->createDownloaderMock();
        $downloader
            ->expects($this->once())
            ->method('download')
            ->with($package, 'target_dir');

        $manager = $this->getMockBuilder('Composer\Downloader\DownloadManager')
            ->setConstructorArgs(array(false, $this->filesystem))
            ->setMethods(array('getDownloaderForInstalledPackage'))
            ->getMock();
        $manager
            ->expects($this->once())
            ->method('getDownloaderForInstalledPackage')
            ->with($package)
            ->will($this->returnValue($downloader));

        $manager->setPreferSource(true);
        $manager->download($package, 'target_dir');
    }

    public function testSourceOnlyPackageDownloadWithSourcePreferred()
    {
        $package = $this->createPackageMock();
        $package
            ->expects($this->once())
            ->method('getSourceType')
            ->will($this->returnValue('git'));
        $package
            ->expects($this->once())
            ->method('getDistType')
            ->will($this->returnValue(null));

        $package
            ->expects($this->once())
            ->method('setInstallationSource')
            ->with('source');

        $downloader = $this->createDownloaderMock();
        $downloader
            ->expects($this->once())
            ->method('download')
            ->with($package, 'target_dir');

        $manager = $this->getMockBuilder('Composer\Downloader\DownloadManager')
            ->setConstructorArgs(array(false, $this->filesystem))
            ->setMethods(array('getDownloaderForInstalledPackage'))
            ->getMock();
        $manager
            ->expects($this->once())
            ->method('getDownloaderForInstalledPackage')
            ->with($package)
            ->will($this->returnValue($downloader));

        $manager->setPreferSource(true);
        $manager->download($package, 'target_dir');
    }

    public function testBadPackageDownloadWithSourcePreferred()
    {
        $package = $this->createPackageMock();
        $package
            ->expects($this->once())
            ->method('getSourceType')
            ->will($this->returnValue(null));
        $package
            ->expects($this->once())
            ->method('getDistType')
            ->will($this->returnValue(null));

        $manager = new DownloadManager(false, $this->filesystem);
        $manager->setPreferSource(true);

        $this->setExpectedException('InvalidArgumentException');
        $manager->download($package, 'target_dir');
    }

    public function testUpdateDistWithEqualTypes()
    {
        $initial = $this->createPackageMock();
        $initial
            ->expects($this->once())
            ->method('getInstallationSource')
            ->will($this->returnValue('dist'));
        $initial
            ->expects($this->once())
            ->method('getDistType')
            ->will($this->returnValue('pear'));

        $target = $this->createPackageMock();
        $target
            ->expects($this->once())
            ->method('getDistType')
            ->will($this->returnValue('pear'));
        $target
            ->expects($this->once())
            ->method('setInstallationSource')
            ->with('dist');

        $pearDownloader = $this->createDownloaderMock();
        $pearDownloader
            ->expects($this->once())
            ->method('update')
            ->with($initial, $target, 'vendor/bundles/FOS/UserBundle');

        $manager = $this->getMockBuilder('Composer\Downloader\DownloadManager')
            ->setConstructorArgs(array(false, $this->filesystem))
            ->setMethods(array('getDownloaderForInstalledPackage'))
            ->getMock();
        $manager
            ->expects($this->once())
            ->method('getDownloaderForInstalledPackage')
            ->with($initial)
            ->will($this->returnValue($pearDownloader));

        $manager->update($initial, $target, 'vendor/bundles/FOS/UserBundle');
    }

    public function testUpdateDistWithNotEqualTypes()
    {
        $initial = $this->createPackageMock();
        $initial
            ->expects($this->once())
            ->method('getInstallationSource')
            ->will($this->returnValue('dist'));
        $initial
            ->expects($this->once())
            ->method('getDistType')
            ->will($this->returnValue('pear'));

        $target = $this->createPackageMock();
        $target
            ->expects($this->once())
            ->method('getDistType')
            ->will($this->returnValue('composer'));

        $pearDownloader = $this->createDownloaderMock();
        $pearDownloader
            ->expects($this->once())
            ->method('remove')
            ->with($initial, 'vendor/bundles/FOS/UserBundle');

        $manager = $this->getMockBuilder('Composer\Downloader\DownloadManager')
            ->setConstructorArgs(array(false, $this->filesystem))
            ->setMethods(array('getDownloaderForInstalledPackage', 'download'))
            ->getMock();
        $manager
            ->expects($this->once())
            ->method('getDownloaderForInstalledPackage')
            ->with($initial)
            ->will($this->returnValue($pearDownloader));
        $manager
            ->expects($this->once())
            ->method('download')
            ->with($target, 'vendor/bundles/FOS/UserBundle', false);

        $manager->update($initial, $target, 'vendor/bundles/FOS/UserBundle');
    }

    public function testUpdateSourceWithEqualTypes()
    {
        $initial = $this->createPackageMock();
        $initial
            ->expects($this->once())
            ->method('getInstallationSource')
            ->will($this->returnValue('source'));
        $initial
            ->expects($this->once())
            ->method('getSourceType')
            ->will($this->returnValue('svn'));

        $target = $this->createPackageMock();
        $target
            ->expects($this->once())
            ->method('getSourceType')
            ->will($this->returnValue('svn'));

        $svnDownloader = $this->createDownloaderMock();
        $svnDownloader
            ->expects($this->once())
            ->method('update')
            ->with($initial, $target, 'vendor/pkg');

        $manager = $this->getMockBuilder('Composer\Downloader\DownloadManager')
            ->setConstructorArgs(array(false, $this->filesystem))
            ->setMethods(array('getDownloaderForInstalledPackage', 'download'))
            ->getMock();
        $manager
            ->expects($this->once())
            ->method('getDownloaderForInstalledPackage')
            ->with($initial)
            ->will($this->returnValue($svnDownloader));

        $manager->update($initial, $target, 'vendor/pkg');
    }

    public function testUpdateSourceWithNotEqualTypes()
    {
        $initial = $this->createPackageMock();
        $initial
            ->expects($this->once())
            ->method('getInstallationSource')
            ->will($this->returnValue('source'));
        $initial
            ->expects($this->once())
            ->method('getSourceType')
            ->will($this->returnValue('svn'));

        $target = $this->createPackageMock();
        $target
            ->expects($this->once())
            ->method('getSourceType')
            ->will($this->returnValue('git'));

        $svnDownloader = $this->createDownloaderMock();
        $svnDownloader
            ->expects($this->once())
            ->method('remove')
            ->with($initial, 'vendor/pkg');

        $manager = $this->getMockBuilder('Composer\Downloader\DownloadManager')
            ->setConstructorArgs(array(false, $this->filesystem))
            ->setMethods(array('getDownloaderForInstalledPackage', 'download'))
            ->getMock();
        $manager
            ->expects($this->once())
            ->method('getDownloaderForInstalledPackage')
            ->with($initial)
            ->will($this->returnValue($svnDownloader));
        $manager
            ->expects($this->once())
            ->method('download')
            ->with($target, 'vendor/pkg', true);

        $manager->update($initial, $target, 'vendor/pkg');
    }

    public function testRemove()
    {
        $package = $this->createPackageMock();

        $pearDownloader = $this->createDownloaderMock();
        $pearDownloader
            ->expects($this->once())
            ->method('remove')
            ->with($package, 'vendor/bundles/FOS/UserBundle');

        $manager = $this->getMockBuilder('Composer\Downloader\DownloadManager')
            ->setConstructorArgs(array(false, $this->filesystem))
            ->setMethods(array('getDownloaderForInstalledPackage'))
            ->getMock();
        $manager
            ->expects($this->once())
            ->method('getDownloaderForInstalledPackage')
            ->with($package)
            ->will($this->returnValue($pearDownloader));

        $manager->remove($package, 'vendor/bundles/FOS/UserBundle');
    }

    private function createDownloaderMock()
    {
        return $this->getMockBuilder('Composer\Downloader\DownloaderInterface')
            ->getMock();
    }

    private function createPackageMock()
    {
        return $this->getMockBuilder('Composer\Package\PackageInterface')
            ->getMock();
    }
}
