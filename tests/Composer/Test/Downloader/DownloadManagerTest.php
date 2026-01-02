<?php declare(strict_types=1);

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
use Composer\Package\PackageInterface;
use Composer\Test\TestCase;

class DownloadManagerTest extends TestCase
{
    /** @var \Composer\Util\Filesystem&\PHPUnit\Framework\MockObject\MockObject */
    protected $filesystem;

    /** @var \Composer\IO\IOInterface&\PHPUnit\Framework\MockObject\MockObject */
    protected $io;

    public function setUp(): void
    {
        $this->filesystem = $this->getMockBuilder('Composer\Util\Filesystem')->getMock();
        $this->io = $this->getMockBuilder('Composer\IO\IOInterface')->getMock();
    }

    public function testSetGetDownloader(): void
    {
        $downloader = $this->createDownloaderMock();
        $manager = new DownloadManager($this->io, false, $this->filesystem);

        $manager->setDownloader('test', $downloader);
        self::assertSame($downloader, $manager->getDownloader('test'));

        self::expectException('InvalidArgumentException');
        $manager->getDownloader('unregistered');
    }

    public function testGetDownloaderForIncorrectlyInstalledPackage(): void
    {
        $package = $this->createPackageMock();
        $package
            ->expects($this->once())
            ->method('getInstallationSource')
            ->will($this->returnValue(null));

        $manager = new DownloadManager($this->io, false, $this->filesystem);

        self::expectException('InvalidArgumentException');

        $manager->getDownloaderForPackage($package);
    }

    public function testGetDownloaderForCorrectlyInstalledDistPackage(): void
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
            ->setConstructorArgs([$this->io, false, $this->filesystem])
            ->onlyMethods(['getDownloader'])
            ->getMock();

        $manager
            ->expects($this->once())
            ->method('getDownloader')
            ->with('pear')
            ->will($this->returnValue($downloader));

        self::assertSame($downloader, $manager->getDownloaderForPackage($package));
    }

    public function testGetDownloaderForIncorrectlyInstalledDistPackage(): void
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
            ->setConstructorArgs([$this->io, false, $this->filesystem])
            ->onlyMethods(['getDownloader'])
            ->getMock();

        $manager
            ->expects($this->once())
            ->method('getDownloader')
            ->with('git')
            ->will($this->returnValue($downloader));

        self::expectException('LogicException');

        $manager->getDownloaderForPackage($package);
    }

    public function testGetDownloaderForCorrectlyInstalledSourcePackage(): void
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
            ->setConstructorArgs([$this->io, false, $this->filesystem])
            ->onlyMethods(['getDownloader'])
            ->getMock();

        $manager
            ->expects($this->once())
            ->method('getDownloader')
            ->with('git')
            ->will($this->returnValue($downloader));

        self::assertSame($downloader, $manager->getDownloaderForPackage($package));
    }

    public function testGetDownloaderForIncorrectlyInstalledSourcePackage(): void
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
            ->setConstructorArgs([$this->io, false, $this->filesystem])
            ->onlyMethods(['getDownloader'])
            ->getMock();

        $manager
            ->expects($this->once())
            ->method('getDownloader')
            ->with('pear')
            ->will($this->returnValue($downloader));

        self::expectException('LogicException');

        $manager->getDownloaderForPackage($package);
    }

    public function testGetDownloaderForMetapackage(): void
    {
        $package = $this->createPackageMock();
        $package
          ->expects($this->once())
          ->method('getType')
          ->will($this->returnValue('metapackage'));

        $manager = new DownloadManager($this->io, false, $this->filesystem);

        self::assertNull($manager->getDownloaderForPackage($package));
    }

    public function testFullPackageDownload(): void
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
            ->with($package, 'target_dir')
            ->will($this->returnValue(\React\Promise\resolve(null)));

        $manager = $this->getMockBuilder('Composer\Downloader\DownloadManager')
            ->setConstructorArgs([$this->io, false, $this->filesystem])
            ->onlyMethods(['getDownloaderForPackage'])
            ->getMock();
        $manager
            ->expects($this->once())
            ->method('getDownloaderForPackage')
            ->with($package)
            ->will($this->returnValue($downloader));

        $manager->download($package, 'target_dir');
    }

    public function testFullPackageDownloadFailover(): void
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
            ->expects($this->exactly(2))
            ->method('setInstallationSource')
            ->willReturnCallback(static function ($type) {
                static $series = [
                    'dist',
                    'source',
                ];

                self::assertSame(array_shift($series), $type);
            });

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
            ->with($package, 'target_dir')
            ->will($this->returnValue(\React\Promise\resolve(null)));

        $manager = $this->getMockBuilder('Composer\Downloader\DownloadManager')
            ->setConstructorArgs([$this->io, false, $this->filesystem])
            ->onlyMethods(['getDownloaderForPackage'])
            ->getMock();
        $manager
            ->expects($this->exactly(2))
            ->method('getDownloaderForPackage')
            ->with($package)
            ->willReturnOnConsecutiveCalls(
                $downloaderFail,
                $downloaderSuccess
            );

        $manager->download($package, 'target_dir');
    }

    public function testBadPackageDownload(): void
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

        self::expectException('InvalidArgumentException');
        $manager->download($package, 'target_dir');
    }

    public function testDistOnlyPackageDownload(): void
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
            ->with($package, 'target_dir')
            ->will($this->returnValue(\React\Promise\resolve(null)));

        $manager = $this->getMockBuilder('Composer\Downloader\DownloadManager')
            ->setConstructorArgs([$this->io, false, $this->filesystem])
            ->onlyMethods(['getDownloaderForPackage'])
            ->getMock();
        $manager
            ->expects($this->once())
            ->method('getDownloaderForPackage')
            ->with($package)
            ->will($this->returnValue($downloader));

        $manager->download($package, 'target_dir');
    }

    public function testSourceOnlyPackageDownload(): void
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
            ->with($package, 'target_dir')
            ->will($this->returnValue(\React\Promise\resolve(null)));

        $manager = $this->getMockBuilder('Composer\Downloader\DownloadManager')
            ->setConstructorArgs([$this->io, false, $this->filesystem])
            ->onlyMethods(['getDownloaderForPackage'])
            ->getMock();
        $manager
            ->expects($this->once())
            ->method('getDownloaderForPackage')
            ->with($package)
            ->will($this->returnValue($downloader));

        $manager->download($package, 'target_dir');
    }

    public function testMetapackagePackageDownload(): void
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
          ->setConstructorArgs([$this->io, false, $this->filesystem])
          ->onlyMethods(['getDownloaderForPackage'])
          ->getMock();
        $manager
          ->expects($this->once())
          ->method('getDownloaderForPackage')
          ->with($package)
          ->will($this->returnValue(null)); // There is no downloader for Metapackages.

        $manager->download($package, 'target_dir');
    }

    public function testFullPackageDownloadWithSourcePreferred(): void
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
            ->with($package, 'target_dir')
            ->will($this->returnValue(\React\Promise\resolve(null)));

        $manager = $this->getMockBuilder('Composer\Downloader\DownloadManager')
            ->setConstructorArgs([$this->io, false, $this->filesystem])
            ->onlyMethods(['getDownloaderForPackage'])
            ->getMock();
        $manager
            ->expects($this->once())
            ->method('getDownloaderForPackage')
            ->with($package)
            ->will($this->returnValue($downloader));

        $manager->setPreferSource(true);
        $manager->download($package, 'target_dir');
    }

    public function testDistOnlyPackageDownloadWithSourcePreferred(): void
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
            ->with($package, 'target_dir')
            ->will($this->returnValue(\React\Promise\resolve(null)));

        $manager = $this->getMockBuilder('Composer\Downloader\DownloadManager')
            ->setConstructorArgs([$this->io, false, $this->filesystem])
            ->onlyMethods(['getDownloaderForPackage'])
            ->getMock();
        $manager
            ->expects($this->once())
            ->method('getDownloaderForPackage')
            ->with($package)
            ->will($this->returnValue($downloader));

        $manager->setPreferSource(true);
        $manager->download($package, 'target_dir');
    }

    public function testSourceOnlyPackageDownloadWithSourcePreferred(): void
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
            ->with($package, 'target_dir')
            ->will($this->returnValue(\React\Promise\resolve(null)));

        $manager = $this->getMockBuilder('Composer\Downloader\DownloadManager')
            ->setConstructorArgs([$this->io, false, $this->filesystem])
            ->onlyMethods(['getDownloaderForPackage'])
            ->getMock();
        $manager
            ->expects($this->once())
            ->method('getDownloaderForPackage')
            ->with($package)
            ->will($this->returnValue($downloader));

        $manager->setPreferSource(true);
        $manager->download($package, 'target_dir');
    }

    public function testBadPackageDownloadWithSourcePreferred(): void
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

        self::expectException('InvalidArgumentException');
        $manager->download($package, 'target_dir');
    }

    public function testUpdateDistWithEqualTypes(): void
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
            ->with($initial, $target, 'vendor/bundles/FOS/UserBundle')
            ->will($this->returnValue(\React\Promise\resolve(null)));
        $zipDownloader
            ->expects($this->any())
            ->method('getInstallationSource')
            ->will($this->returnValue('dist'));

        $manager = new DownloadManager($this->io, false, $this->filesystem);
        $manager->setDownloader('zip', $zipDownloader);

        $manager->update($initial, $target, 'vendor/bundles/FOS/UserBundle');
    }

    public function testUpdateDistWithNotEqualTypes(): void
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
            ->with($initial, 'vendor/bundles/FOS/UserBundle')
            ->will($this->returnValue(\React\Promise\resolve(null)));
        $xzDownloader
            ->expects($this->any())
            ->method('getInstallationSource')
            ->will($this->returnValue('dist'));

        $zipDownloader = $this->createDownloaderMock();
        $zipDownloader
            ->expects($this->once())
            ->method('install')
            ->with($target, 'vendor/bundles/FOS/UserBundle')
            ->will($this->returnValue(\React\Promise\resolve(null)));
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
     * @param string[] $targetAvailable
     * @param string[] $expected
     */
    public function testGetAvailableSourcesUpdateSticksToSameSource(?string $prevPkgSource, ?bool $prevPkgIsDev, array $targetAvailable, bool $targetIsDev, array $expected): void
    {
        $initial = null;
        if ($prevPkgSource) {
            $initial = $this->getMockBuilder(PackageInterface::class)->getMock();
            $initial->expects($this->atLeastOnce())
                ->method('getInstallationSource')
                ->willReturn($prevPkgSource);
            $initial->expects($this->any())
                ->method('isDev')
                ->willReturn($prevPkgIsDev);
        }

        $target = $this->getMockBuilder(PackageInterface::class)->getMock();
        $target->expects($this->atLeastOnce())
            ->method('getSourceType')
            ->willReturn(in_array('source', $targetAvailable, true) ? 'git' : null);
        $target->expects($this->atLeastOnce())
            ->method('getDistType')
            ->willReturn(in_array('dist', $targetAvailable, true) ? 'zip' : null);
        $target->expects($this->any())
            ->method('isDev')
            ->willReturn($targetIsDev);

        $manager = new DownloadManager($this->io, false, $this->filesystem);
        $method = new \ReflectionMethod($manager, 'getAvailableSources');
        (\PHP_VERSION_ID < 80100) and $method->setAccessible(true);
        self::assertEquals($expected, $method->invoke($manager, $target, $initial ?? null));
    }

    public static function updatesProvider(): array
    {
        return [
            //    prevPkg source,  prevPkg isDev, pkg available,           pkg isDev,  expected
            // updates keep previous source as preference
            ['source',        false,         ['source', 'dist'], false,      ['source', 'dist']],
            ['dist',          false,         ['source', 'dist'], false,      ['dist', 'source']],
            // updates do not keep previous source if target package does not have it
            ['source',        false,         ['dist'],           false,      ['dist']],
            ['dist',          false,         ['source'],         false,      ['source']],
            // updates do not keep previous source if target is dev and prev wasn't dev and installed from dist
            ['source',        false,         ['source', 'dist'], true,       ['source', 'dist']],
            ['dist',          false,         ['source', 'dist'], true,       ['source', 'dist']],
            // install picks the right default
            [null,            null,          ['source', 'dist'], true,       ['source', 'dist']],
            [null,            null,          ['dist'],           true,       ['dist']],
            [null,            null,          ['source'],         true,       ['source']],
            [null,            null,          ['source', 'dist'], false,      ['dist', 'source']],
            [null,            null,          ['dist'],           false,      ['dist']],
            [null,            null,          ['source'],         false,      ['source']],
        ];
    }

    public function testUpdateMetapackage(): void
    {
        $initial = $this->createPackageMock();
        $target = $this->createPackageMock();

        $manager = $this->getMockBuilder('Composer\Downloader\DownloadManager')
          ->setConstructorArgs([$this->io, false, $this->filesystem])
          ->onlyMethods(['getDownloaderForPackage'])
          ->getMock();
        $manager
          ->expects($this->exactly(2))
          ->method('getDownloaderForPackage')
          ->with($initial)
          ->will($this->returnValue(null)); // There is no downloader for metapackages.

        $manager->update($initial, $target, 'vendor/pkg');
    }

    public function testRemove(): void
    {
        $package = $this->createPackageMock();

        $pearDownloader = $this->createDownloaderMock();
        $pearDownloader
            ->expects($this->once())
            ->method('remove')
            ->with($package, 'vendor/bundles/FOS/UserBundle');

        $manager = $this->getMockBuilder('Composer\Downloader\DownloadManager')
            ->setConstructorArgs([$this->io, false, $this->filesystem])
            ->onlyMethods(['getDownloaderForPackage'])
            ->getMock();
        $manager
            ->expects($this->once())
            ->method('getDownloaderForPackage')
            ->with($package)
            ->will($this->returnValue($pearDownloader));

        $manager->remove($package, 'vendor/bundles/FOS/UserBundle');
    }

    public function testMetapackageRemove(): void
    {
        $package = $this->createPackageMock();

        $manager = $this->getMockBuilder('Composer\Downloader\DownloadManager')
          ->setConstructorArgs([$this->io, false, $this->filesystem])
          ->onlyMethods(['getDownloaderForPackage'])
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
    public function testInstallPreferenceWithoutPreferenceDev(): void
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
            ->with($package, 'target_dir')
            ->will($this->returnValue(\React\Promise\resolve(null)));

        $manager = $this->getMockBuilder('Composer\Downloader\DownloadManager')
            ->setConstructorArgs([$this->io, false, $this->filesystem])
            ->onlyMethods(['getDownloaderForPackage'])
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
    public function testInstallPreferenceWithoutPreferenceNoDev(): void
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
            ->with($package, 'target_dir')
            ->will($this->returnValue(\React\Promise\resolve(null)));

        $manager = $this->getMockBuilder('Composer\Downloader\DownloadManager')
            ->setConstructorArgs([$this->io, false, $this->filesystem])
            ->onlyMethods(['getDownloaderForPackage'])
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
    public function testInstallPreferenceWithoutMatchDev(): void
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
            ->with($package, 'target_dir')
            ->will($this->returnValue(\React\Promise\resolve(null)));

        $manager = $this->getMockBuilder('Composer\Downloader\DownloadManager')
            ->setConstructorArgs([$this->io, false, $this->filesystem])
            ->onlyMethods(['getDownloaderForPackage'])
            ->getMock();
        $manager
            ->expects($this->once())
            ->method('getDownloaderForPackage')
            ->with($package)
            ->will($this->returnValue($downloader));
        $manager->setPreferences(['foo/*' => 'source']);

        $manager->download($package, 'target_dir');
    }

    /**
     * @covers Composer\Downloader\DownloadManager::resolvePackageInstallPreference
     */
    public function testInstallPreferenceWithoutMatchNoDev(): void
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
            ->with($package, 'target_dir')
            ->will($this->returnValue(\React\Promise\resolve(null)));

        $manager = $this->getMockBuilder('Composer\Downloader\DownloadManager')
            ->setConstructorArgs([$this->io, false, $this->filesystem])
            ->onlyMethods(['getDownloaderForPackage'])
            ->getMock();
        $manager
            ->expects($this->once())
            ->method('getDownloaderForPackage')
            ->with($package)
            ->will($this->returnValue($downloader));
        $manager->setPreferences(['foo/*' => 'source']);

        $manager->download($package, 'target_dir');
    }

    /**
     * @covers Composer\Downloader\DownloadManager::resolvePackageInstallPreference
     */
    public function testInstallPreferenceWithMatchAutoDev(): void
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
            ->with($package, 'target_dir')
            ->will($this->returnValue(\React\Promise\resolve(null)));

        $manager = $this->getMockBuilder('Composer\Downloader\DownloadManager')
            ->setConstructorArgs([$this->io, false, $this->filesystem])
            ->onlyMethods(['getDownloaderForPackage'])
            ->getMock();
        $manager
            ->expects($this->once())
            ->method('getDownloaderForPackage')
            ->with($package)
            ->will($this->returnValue($downloader));
        $manager->setPreferences(['foo/*' => 'auto']);

        $manager->download($package, 'target_dir');
    }

    /**
     * @covers Composer\Downloader\DownloadManager::resolvePackageInstallPreference
     */
    public function testInstallPreferenceWithMatchAutoNoDev(): void
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
            ->with($package, 'target_dir')
            ->will($this->returnValue(\React\Promise\resolve(null)));

        $manager = $this->getMockBuilder('Composer\Downloader\DownloadManager')
            ->setConstructorArgs([$this->io, false, $this->filesystem])
            ->onlyMethods(['getDownloaderForPackage'])
            ->getMock();
        $manager
            ->expects($this->once())
            ->method('getDownloaderForPackage')
            ->with($package)
            ->will($this->returnValue($downloader));
        $manager->setPreferences(['foo/*' => 'auto']);

        $manager->download($package, 'target_dir');
    }

    /**
     * @covers Composer\Downloader\DownloadManager::resolvePackageInstallPreference
     */
    public function testInstallPreferenceWithMatchSource(): void
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
            ->with($package, 'target_dir')
            ->will($this->returnValue(\React\Promise\resolve(null)));

        $manager = $this->getMockBuilder('Composer\Downloader\DownloadManager')
            ->setConstructorArgs([$this->io, false, $this->filesystem])
            ->onlyMethods(['getDownloaderForPackage'])
            ->getMock();
        $manager
            ->expects($this->once())
            ->method('getDownloaderForPackage')
            ->with($package)
            ->will($this->returnValue($downloader));
        $manager->setPreferences(['foo/*' => 'source']);

        $manager->download($package, 'target_dir');
    }

    /**
     * @covers Composer\Downloader\DownloadManager::resolvePackageInstallPreference
     */
    public function testInstallPreferenceWithMatchDist(): void
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
            ->with($package, 'target_dir')
            ->will($this->returnValue(\React\Promise\resolve(null)));

        $manager = $this->getMockBuilder('Composer\Downloader\DownloadManager')
            ->setConstructorArgs([$this->io, false, $this->filesystem])
            ->onlyMethods(['getDownloaderForPackage'])
            ->getMock();
        $manager
            ->expects($this->once())
            ->method('getDownloaderForPackage')
            ->with($package)
            ->will($this->returnValue($downloader));
        $manager->setPreferences(['foo/*' => 'dist']);

        $manager->download($package, 'target_dir');
    }

    public function testDownloadFailsWithoutFallbackWhenDisabled(): void
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
            ->expects($this->any())
            ->method('getPrettyName')
            ->will($this->returnValue('foo/bar'));

        $package
            ->expects($this->once())
            ->method('setInstallationSource')
            ->with('dist');

        $downloaderFail = $this->createDownloaderMock();
        $downloaderFail
            ->expects($this->once())
            ->method('download')
            ->with($package, 'target_dir')
            ->will($this->throwException(new \RuntimeException("Foo")));

        $manager = $this->getMockBuilder('Composer\Downloader\DownloadManager')
            ->setConstructorArgs([$this->io, false, $this->filesystem])
            ->onlyMethods(['getDownloaderForPackage'])
            ->getMock();
        $manager
            ->expects($this->once())
            ->method('getDownloaderForPackage')
            ->with($package)
            ->will($this->returnValue($downloaderFail));

        // Disable source fallback
        $manager->setSourceFallback(false);

        $this->io
            ->expects($this->exactly(2))
            ->method('writeError');

        self::expectException('RuntimeException');
        self::expectExceptionMessage('Foo');

        $manager->download($package, 'target_dir');
    }

    public function testDownloadFallsBackWhenEnabled(): void
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
            ->expects($this->any())
            ->method('getPrettyName')
            ->will($this->returnValue('foo/bar'));

        $package
            ->expects($this->exactly(2))
            ->method('setInstallationSource')
            ->willReturnCallback(static function ($type) {
                static $series = [
                    'dist',
                    'source',
                ];

                self::assertSame(array_shift($series), $type);
            });

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
            ->with($package, 'target_dir')
            ->will($this->returnValue(\React\Promise\resolve(null)));

        $manager = $this->getMockBuilder('Composer\Downloader\DownloadManager')
            ->setConstructorArgs([$this->io, false, $this->filesystem])
            ->onlyMethods(['getDownloaderForPackage'])
            ->getMock();
        $manager
            ->expects($this->exactly(2))
            ->method('getDownloaderForPackage')
            ->with($package)
            ->willReturnOnConsecutiveCalls(
                $downloaderFail,
                $downloaderSuccess
            );

        // Explicitly enable source fallback (default behavior)
        $manager->setSourceFallback(true);

        $manager->download($package, 'target_dir');
    }

    /**
     * @return \Composer\Downloader\DownloaderInterface&\PHPUnit\Framework\MockObject\MockObject
     */
    private function createDownloaderMock()
    {
        return $this->getMockBuilder('Composer\Downloader\DownloaderInterface')
            ->getMock();
    }

    /**
     * @return PackageInterface&\PHPUnit\Framework\MockObject\MockObject
     */
    private function createPackageMock()
    {
        return $this->getMockBuilder('Composer\Package\PackageInterface')
            ->getMock();
    }
}
