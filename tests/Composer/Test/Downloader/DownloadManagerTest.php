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
    public function testSetGetDownloader()
    {
        $downloader = $this->createDownloaderMock();
        $manager    = new DownloadManager();

        $manager->setDownloader('test', $downloader);
        $this->assertSame($downloader, $manager->getDownloader('test'));

        $this->setExpectedException('UnexpectedValueException');
        $manager->getDownloader('unregistered');
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
            ->method('getDistUrl')
            ->will($this->returnValue('dist_url'));
        $package
            ->expects($this->once())
            ->method('getDistSha1Checksum')
            ->will($this->returnValue('sha1'));

        $package
            ->expects($this->once())
            ->method('setInstallationSource')
            ->with('dist');

        $pearDownloader = $this->createDownloaderMock();
        $pearDownloader
            ->expects($this->once())
            ->method('download')
            ->with($package, 'target_dir', 'dist_url', 'sha1');

        $manager = new DownloadManager();
        $manager->setDownloader('pear', $pearDownloader);

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

        $manager = new DownloadManager();

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
            ->method('getDistUrl')
            ->will($this->returnValue('dist_url'));
        $package
            ->expects($this->once())
            ->method('getDistSha1Checksum')
            ->will($this->returnValue('sha1'));

        $package
            ->expects($this->once())
            ->method('setInstallationSource')
            ->with('dist');

        $pearDownloader = $this->createDownloaderMock();
        $pearDownloader
            ->expects($this->once())
            ->method('download')
            ->with($package, 'target_dir', 'dist_url', 'sha1');

        $manager = new DownloadManager();
        $manager->setDownloader('pear', $pearDownloader);

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
            ->method('getSourceUrl')
            ->will($this->returnValue('source_url'));

        $package
            ->expects($this->once())
            ->method('setInstallationSource')
            ->with('source');

        $gitDownloader = $this->createDownloaderMock();
        $gitDownloader
            ->expects($this->once())
            ->method('download')
            ->with($package, 'vendor/pkg', 'source_url');

        $manager = new DownloadManager();
        $manager->setDownloader('git', $gitDownloader);

        $manager->download($package, 'vendor/pkg');
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
            ->method('getSourceUrl')
            ->will($this->returnValue('source_url'));

        $package
            ->expects($this->once())
            ->method('setInstallationSource')
            ->with('source');

        $gitDownloader = $this->createDownloaderMock();
        $gitDownloader
            ->expects($this->once())
            ->method('download')
            ->with($package, 'vendor/pkg', 'source_url');

        $manager = new DownloadManager();
        $manager->setDownloader('git', $gitDownloader);
        $manager->preferSource();

        $manager->download($package, 'vendor/pkg');
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
            ->method('getDistUrl')
            ->will($this->returnValue('dist_url'));
        $package
            ->expects($this->once())
            ->method('getDistSha1Checksum')
            ->will($this->returnValue('sha1'));

        $package
            ->expects($this->once())
            ->method('setInstallationSource')
            ->with('dist');

        $pearDownloader = $this->createDownloaderMock();
        $pearDownloader
            ->expects($this->once())
            ->method('download')
            ->with($package, 'target_dir', 'dist_url', 'sha1');

        $manager = new DownloadManager();
        $manager->setDownloader('pear', $pearDownloader);
        $manager->preferSource();

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
            ->method('getSourceUrl')
            ->will($this->returnValue('source_url'));

        $package
            ->expects($this->once())
            ->method('setInstallationSource')
            ->with('source');

        $gitDownloader = $this->createDownloaderMock();
        $gitDownloader
            ->expects($this->once())
            ->method('download')
            ->with($package, 'vendor/pkg', 'source_url');

        $manager = new DownloadManager();
        $manager->setDownloader('git', $gitDownloader);
        $manager->preferSource();

        $manager->download($package, 'vendor/pkg');
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

        $manager = new DownloadManager();
        $manager->preferSource();

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

        $pearDownloader = $this->createDownloaderMock();
        $pearDownloader
            ->expects($this->once())
            ->method('update')
            ->with($initial, $target, 'vendor/bundles/FOS/UserBundle');

        $manager = new DownloadManager();
        $manager->setDownloader('pear', $pearDownloader);

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
            ->setMethods(array('download'))
            ->getMock();
        $manager
            ->expects($this->once())
            ->method('download')
            ->with($target, 'vendor/bundles/FOS/UserBundle', false);

        $manager->setDownloader('pear', $pearDownloader);
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

        $manager = new DownloadManager();
        $manager->setDownloader('svn', $svnDownloader);

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
            ->setMethods(array('download'))
            ->getMock();
        $manager
            ->expects($this->once())
            ->method('download')
            ->with($target, 'vendor/pkg', true);
        $manager->setDownloader('svn', $svnDownloader);

        $manager->update($initial, $target, 'vendor/pkg');
    }

    public function testUpdateBadlyInstalledPackage()
    {
        $initial = $this->createPackageMock();
        $target  = $this->createPackageMock();

        $this->setExpectedException('InvalidArgumentException');

        $manager = new DownloadManager();
        $manager->update($initial, $target, 'vendor/pkg');
    }

    public function testRemoveDist()
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

        $pearDownloader = $this->createDownloaderMock();
        $pearDownloader
            ->expects($this->once())
            ->method('remove')
            ->with($package, 'vendor/bundles/FOS/UserBundle');

        $manager = new DownloadManager();
        $manager->setDownloader('pear', $pearDownloader);

        $manager->remove($package, 'vendor/bundles/FOS/UserBundle');
    }

    public function testRemoveSource()
    {
        $package = $this->createPackageMock();
        $package
            ->expects($this->once())
            ->method('getInstallationSource')
            ->will($this->returnValue('source'));
        $package
            ->expects($this->once())
            ->method('getSourceType')
            ->will($this->returnValue('svn'));

        $svnDownloader = $this->createDownloaderMock();
        $svnDownloader
            ->expects($this->once())
            ->method('remove')
            ->with($package, 'vendor/pkg');

        $manager = new DownloadManager();
        $manager->setDownloader('svn', $svnDownloader);

        $manager->remove($package, 'vendor/pkg');
    }

    public function testRemoveBadlyInstalledPackage()
    {
        $package = $this->createPackageMock();
        $manager = new DownloadManager();

        $this->setExpectedException('InvalidArgumentException');

        $manager->remove($package, 'vendor/pkg');
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
