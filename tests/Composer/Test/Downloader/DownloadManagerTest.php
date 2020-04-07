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
use Composer\Test\TestCase;

class DownloadManagerTest extends TestCase
{
    protected $filesystem;
    protected $io;

    public function setUp()
    {
        $this->filesystem = $this->getMockBuilder('Composer\Util\Filesystem')->getMock();
        $this->io = $this->getMockBuilder('Composer\IO\IOInterface')->getMock();
    }

    public function testSetGetDownloader()
    {
        $downloader = $this->createDownloaderMock();
        $manager = new DownloadManager($this->io, false, $this->filesystem);

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

        $manager = new DownloadManager($this->io, false, $this->filesystem);

        $this->setExpectedException('InvalidArgumentException');

        $manager->getDownloaderForPackage($package);
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
            ->setConstructorArgs(array($this->io, false, $this->filesystem))
            ->setMethods(array('getDownloader'))
            ->getMock();

        $manager
            ->expects($this->once())
            ->method('getDownloader')
            ->with('pear')
            ->will($this->returnValue($downloader));

        $this->assertSame($downloader, $manager->getDownloaderForPackage($package));
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
            ->setConstructorArgs(array($this->io, false, $this->filesystem))
            ->setMethods(array('getDownloader'))
            ->getMock();

        $manager
            ->expects($this->once())
            ->method('getDownloader')
            ->with('git')
            ->will($this->returnValue($downloader));

        $this->setExpectedException('LogicException');

        $manager->getDownloaderForPackage($package);
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
            ->setConstructorArgs(array($this->io, false, $this->filesystem))
            ->setMethods(array('getDownloader'))
            ->getMock();

        $manager
            ->expects($this->once())
            ->method('getDownloader')
            ->with('git')
            ->will($this->returnValue($downloader));

        $this->assertSame($downloader, $manager->getDownloaderForPackage($package));
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
            ->setConstructorArgs(array($this->io, false, $this->filesystem))
            ->setMethods(array('getDownloader'))
            ->getMock();

        $manager
            ->expects($this->once())
            ->method('getDownloader')
            ->with('pear')
            ->will($this->returnValue($downloader));

        $this->setExpectedException('LogicException');

        $manager->getDownloaderForPackage($package);
    }

    public function testGetDownloaderForMetapackage()
    {
        $package = $this->createPackageMock();
        $package
          ->expects($this->once())
          ->method('getType')
          ->will($this->returnValue('metapackage'));

        $manager = new DownloadManager($this->io, false, $this->filesystem);

        $this->assertNull($manager->getDownloaderForPackage($package));
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
            ->setConstructorArgs(array($this->io, false, $this->filesystem))
            ->setMethods(array('getDownloaderForPackage'))
            ->getMock();
        $manager
            ->expects($this->once())
            ->method('getDownloaderForPackage')
            ->with($package)
            ->will($this->returnValue($downloader));

        $manager->download($package, 'target_dir');
    }

    public function testFullPackageDownloadFailover()
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
            ->expects($this->any())
            ->method('getPrettyString')
            ->will($this->returnValue('prettyPackage'));

        $package
            ->expects($this->at(3))
            ->method('setInstallationSource')
            ->with('dist');
        $package
            ->expects($this->at(5))
            ->method('setInstallationSource')
            ->with('source');

        $downloaderFail = $this->createDownloaderMock();
        $downloaderFail
            ->expects($this->once())
            ->method('download')
            ->with($package, 'target_dir')
            ->will($this->throwException(new \RuntimeException("Foo")));

        $downloaderSuccess = $this->createDownloaderMock();
        $downloaderSuccess
            ->expects($this->once())
            ->method('download')
            ->with($package, 'target_dir');

        $manager = $this->getMockBuilder('Composer\Downloader\DownloadManager')
            ->setConstructorArgs(array($this->io, false, $this->filesystem))
            ->setMethods(array('getDownloaderForPackage'))
            ->getMock();
        $manager
            ->expects($this->at(0))
            ->method('getDownloaderForPackage')
            ->with($package)
            ->will($this->returnValue($downloaderFail));
        $manager
            ->expects($this->at(1))
            ->method('getDownloaderForPackage')
            ->with($package)
            ->will($this->returnValue($downloaderSuccess));

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

        $manager = new DownloadManager($this->io, false, $this->filesystem);

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
            ->setConstructorArgs(array($this->io, false, $this->filesystem))
            ->setMethods(array('getDownloaderForPackage'))
            ->getMock();
        $manager
            ->expects($this->once())
            ->method('getDownloaderForPackage')
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
            ->setConstructorArgs(array($this->io, false, $this->filesystem))
            ->setMethods(array('getDownloaderForPackage'))
            ->getMock();
        $manager
            ->expects($this->once())
            ->method('getDownloaderForPackage')
            ->with($package)
            ->will($this->returnValue($downloader));

        $manager->download($package, 'target_dir');
    }

    public function testMetapackagePackageDownload()
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

        $manager = $this->getMockBuilder('Composer\Downloader\DownloadManager')
          ->setConstructorArgs(array($this->io, false, $this->filesystem))
          ->setMethods(array('getDownloaderForPackage'))
          ->getMock();
        $manager
          ->expects($this->once())
          ->method('getDownloaderForPackage')
          ->with($package)
          ->will($this->returnValue(null)); // There is no downloader for Metapackages.

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
            ->setConstructorArgs(array($this->io, false, $this->filesystem))
            ->setMethods(array('getDownloaderForPackage'))
            ->getMock();
        $manager
            ->expects($this->once())
            ->method('getDownloaderForPackage')
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
            ->setConstructorArgs(array($this->io, false, $this->filesystem))
            ->setMethods(array('getDownloaderForPackage'))
            ->getMock();
        $manager
            ->expects($this->once())
            ->method('getDownloaderForPackage')
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
            ->setConstructorArgs(array($this->io, false, $this->filesystem))
            ->setMethods(array('getDownloaderForPackage'))
            ->getMock();
        $manager
            ->expects($this->once())
            ->method('getDownloaderForPackage')
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

        $manager = new DownloadManager($this->io, false, $this->filesystem);
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
            ->will($this->returnValue('zip'));

        $target = $this->createPackageMock();
        $target
            ->expects($this->once())
            ->method('getInstallationSource')
            ->will($this->returnValue('dist'));
        $target
            ->expects($this->once())
            ->method('getDistType')
            ->will($this->returnValue('zip'));

        $zipDownloader = $this->createDownloaderMock();
        $zipDownloader
            ->expects($this->once())
            ->method('update')
            ->with($initial, $target, 'vendor/bundles/FOS/UserBundle');
        $zipDownloader
            ->expects($this->any())
            ->method('getInstallationSource')
            ->will($this->returnValue('dist'));

        $manager = new DownloadManager($this->io, false, $this->filesystem);
        $manager->setDownloader('zip', $zipDownloader);

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
            ->will($this->returnValue('xz'));

        $target = $this->createPackageMock();
        $target
            ->expects($this->any())
            ->method('getInstallationSource')
            ->will($this->returnValue('dist'));
        $target
            ->expects($this->any())
            ->method('getDistType')
            ->will($this->returnValue('zip'));

        $xzDownloader = $this->createDownloaderMock();
        $xzDownloader
            ->expects($this->once())
            ->method('remove')
            ->with($initial, 'vendor/bundles/FOS/UserBundle');
        $xzDownloader
            ->expects($this->any())
            ->method('getInstallationSource')
            ->will($this->returnValue('dist'));

        $zipDownloader = $this->createDownloaderMock();
        $zipDownloader
            ->expects($this->once())
            ->method('install')
            ->with($target, 'vendor/bundles/FOS/UserBundle');
        $zipDownloader
            ->expects($this->any())
            ->method('getInstallationSource')
            ->will($this->returnValue('dist'));

        $manager = new DownloadManager($this->io, false, $this->filesystem);
        $manager->setDownloader('xz', $xzDownloader);
        $manager->setDownloader('zip', $zipDownloader);

        $manager->update($initial, $target, 'vendor/bundles/FOS/UserBundle');
    }

    /**
     * @dataProvider updatesProvider
     */
    public function testGetAvailableSourcesUpdateSticksToSameSource($prevPkgSource, $prevPkgIsDev, $targetAvailable, $targetIsDev, $expected)
    {
        $initial = null;
        if ($prevPkgSource) {
            $initial = $this->prophesize('Composer\Package\PackageInterface');
            $initial->getInstallationSource()->willReturn($prevPkgSource);
            $initial->isDev()->willReturn($prevPkgIsDev);
        }

        $target = $this->prophesize('Composer\Package\PackageInterface');
        $target->getSourceType()->willReturn(in_array('source', $targetAvailable, true) ? 'git' : null);
        $target->getDistType()->willReturn(in_array('dist', $targetAvailable, true) ? 'zip' : null);
        $target->isDev()->willReturn($targetIsDev);

        $manager = new DownloadManager($this->io, false, $this->filesystem);
        $method = new \ReflectionMethod($manager, 'getAvailableSources');
        $method->setAccessible(true);
        $this->assertEquals($expected, $method->invoke($manager, $target->reveal(), $initial ? $initial->reveal() : null));
    }

    public static function updatesProvider()
    {
        return array(
            //    prevPkg source,  prevPkg isDev, pkg available,           pkg isDev,  expected
            // updates keep previous source as preference
            array('source',        false,         array('source', 'dist'), false,      array('source', 'dist')),
            array('dist',          false,         array('source', 'dist'), false,      array('dist', 'source')),
            // updates do not keep previous source if target package does not have it
            array('source',        false,         array('dist'),           false,      array('dist')),
            array('dist',          false,         array('source'),         false,      array('source')),
            // updates do not keep previous source if target is dev and prev wasn't dev and installed from dist
            array('source',        false,         array('source', 'dist'), true,       array('source', 'dist')),
            array('dist',          false,         array('source', 'dist'), true,       array('source', 'dist')),
            // install picks the right default
            array(null,            null,          array('source', 'dist'), true,       array('source', 'dist')),
            array(null,            null,          array('dist'),           true,       array('dist')),
            array(null,            null,          array('source'),         true,       array('source')),
            array(null,            null,          array('source', 'dist'), false,      array('dist', 'source')),
            array(null,            null,          array('dist'),           false,      array('dist')),
            array(null,            null,          array('source'),         false,      array('source')),
        );
    }

    public function testUpdateMetapackage()
    {
        $initial = $this->createPackageMock();
        $target = $this->createPackageMock();

        $manager = $this->getMockBuilder('Composer\Downloader\DownloadManager')
          ->setConstructorArgs(array($this->io, false, $this->filesystem))
          ->setMethods(array('getDownloaderForPackage'))
          ->getMock();
        $manager
          ->expects($this->exactly(2))
          ->method('getDownloaderForPackage')
          ->with($initial)
          ->will($this->returnValue(null)); // There is no downloader for metapackages.

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
            ->setConstructorArgs(array($this->io, false, $this->filesystem))
            ->setMethods(array('getDownloaderForPackage'))
            ->getMock();
        $manager
            ->expects($this->once())
            ->method('getDownloaderForPackage')
            ->with($package)
            ->will($this->returnValue($pearDownloader));

        $manager->remove($package, 'vendor/bundles/FOS/UserBundle');
    }

    public function testMetapackageRemove()
    {
        $package = $this->createPackageMock();

        $manager = $this->getMockBuilder('Composer\Downloader\DownloadManager')
          ->setConstructorArgs(array($this->io, false, $this->filesystem))
          ->setMethods(array('getDownloaderForPackage'))
          ->getMock();
        $manager
          ->expects($this->once())
          ->method('getDownloaderForPackage')
          ->with($package)
          ->will($this->returnValue(null)); // There is no downloader for metapackages.

        $manager->remove($package, 'vendor/bundles/FOS/UserBundle');
    }

    /**
     * @covers Composer\Downloader\DownloadManager::resolvePackageInstallPreference
     */
    public function testInstallPreferenceWithoutPreferenceDev()
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
            ->method('isDev')
            ->will($this->returnValue(true));

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
            ->setConstructorArgs(array($this->io, false, $this->filesystem))
            ->setMethods(array('getDownloaderForPackage'))
            ->getMock();
        $manager
            ->expects($this->once())
            ->method('getDownloaderForPackage')
            ->with($package)
            ->will($this->returnValue($downloader));

        $manager->download($package, 'target_dir');
    }

    /**
     * @covers Composer\Downloader\DownloadManager::resolvePackageInstallPreference
     */
    public function testInstallPreferenceWithoutPreferenceNoDev()
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
            ->method('isDev')
            ->will($this->returnValue(false));

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
            ->setConstructorArgs(array($this->io, false, $this->filesystem))
            ->setMethods(array('getDownloaderForPackage'))
            ->getMock();
        $manager
            ->expects($this->once())
            ->method('getDownloaderForPackage')
            ->with($package)
            ->will($this->returnValue($downloader));

        $manager->download($package, 'target_dir');
    }

    /**
     * @covers Composer\Downloader\DownloadManager::resolvePackageInstallPreference
     */
    public function testInstallPreferenceWithoutMatchDev()
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
            ->method('isDev')
            ->will($this->returnValue(true));
        $package
            ->expects($this->once())
            ->method('getName')
            ->will($this->returnValue('bar/package'));
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
            ->setConstructorArgs(array($this->io, false, $this->filesystem))
            ->setMethods(array('getDownloaderForPackage'))
            ->getMock();
        $manager
            ->expects($this->once())
            ->method('getDownloaderForPackage')
            ->with($package)
            ->will($this->returnValue($downloader));
        $manager->setPreferences(array('foo/*' => 'source'));

        $manager->download($package, 'target_dir');
    }

    /**
     * @covers Composer\Downloader\DownloadManager::resolvePackageInstallPreference
     */
    public function testInstallPreferenceWithoutMatchNoDev()
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
            ->method('isDev')
            ->will($this->returnValue(false));
        $package
            ->expects($this->once())
            ->method('getName')
            ->will($this->returnValue('bar/package'));
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
            ->setConstructorArgs(array($this->io, false, $this->filesystem))
            ->setMethods(array('getDownloaderForPackage'))
            ->getMock();
        $manager
            ->expects($this->once())
            ->method('getDownloaderForPackage')
            ->with($package)
            ->will($this->returnValue($downloader));
        $manager->setPreferences(array('foo/*' => 'source'));

        $manager->download($package, 'target_dir');
    }

    /**
     * @covers Composer\Downloader\DownloadManager::resolvePackageInstallPreference
     */
    public function testInstallPreferenceWithMatchAutoDev()
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
            ->method('isDev')
            ->will($this->returnValue(true));
        $package
            ->expects($this->once())
            ->method('getName')
            ->will($this->returnValue('foo/package'));
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
            ->setConstructorArgs(array($this->io, false, $this->filesystem))
            ->setMethods(array('getDownloaderForPackage'))
            ->getMock();
        $manager
            ->expects($this->once())
            ->method('getDownloaderForPackage')
            ->with($package)
            ->will($this->returnValue($downloader));
        $manager->setPreferences(array('foo/*' => 'auto'));

        $manager->download($package, 'target_dir');
    }

    /**
     * @covers Composer\Downloader\DownloadManager::resolvePackageInstallPreference
     */
    public function testInstallPreferenceWithMatchAutoNoDev()
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
            ->method('isDev')
            ->will($this->returnValue(false));
        $package
            ->expects($this->once())
            ->method('getName')
            ->will($this->returnValue('foo/package'));
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
            ->setConstructorArgs(array($this->io, false, $this->filesystem))
            ->setMethods(array('getDownloaderForPackage'))
            ->getMock();
        $manager
            ->expects($this->once())
            ->method('getDownloaderForPackage')
            ->with($package)
            ->will($this->returnValue($downloader));
        $manager->setPreferences(array('foo/*' => 'auto'));

        $manager->download($package, 'target_dir');
    }

    /**
     * @covers Composer\Downloader\DownloadManager::resolvePackageInstallPreference
     */
    public function testInstallPreferenceWithMatchSource()
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
            ->method('getName')
            ->will($this->returnValue('foo/package'));
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
            ->setConstructorArgs(array($this->io, false, $this->filesystem))
            ->setMethods(array('getDownloaderForPackage'))
            ->getMock();
        $manager
            ->expects($this->once())
            ->method('getDownloaderForPackage')
            ->with($package)
            ->will($this->returnValue($downloader));
        $manager->setPreferences(array('foo/*' => 'source'));

        $manager->download($package, 'target_dir');
    }

    /**
     * @covers Composer\Downloader\DownloadManager::resolvePackageInstallPreference
     */
    public function testInstallPreferenceWithMatchDist()
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
            ->method('getName')
            ->will($this->returnValue('foo/package'));
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
            ->setConstructorArgs(array($this->io, false, $this->filesystem))
            ->setMethods(array('getDownloaderForPackage'))
            ->getMock();
        $manager
            ->expects($this->once())
            ->method('getDownloaderForPackage')
            ->with($package)
            ->will($this->returnValue($downloader));
        $manager->setPreferences(array('foo/*' => 'dist'));

        $manager->download($package, 'target_dir');
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
