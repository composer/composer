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

use Composer\Autoload\AutoloadGenerator;
use Composer\Downloader\FileDownloader;
use Composer\EventDispatcher\EventDispatcher;
use Composer\IO\NullIO;
use Composer\Plugin\PluginEvents;
use Composer\Plugin\PreFileDownloadEvent;
use Composer\Repository\InstalledArrayRepository;
use Composer\Repository\RepositoryManager;
use Composer\Test\Mock\HttpDownloaderMock;
use Composer\Test\Mock\InstallationManagerMock;
use Composer\Test\TestCase;
use Composer\Util\Filesystem;
use Composer\Util\Http\Response;
use Composer\Util\Loop;
use Composer\Config;
use Composer\Composer;

class FileDownloaderTest extends TestCase
{
    /** @var \Composer\Util\HttpDownloader&\PHPUnit\Framework\MockObject\MockObject */
    private $httpDownloader;

    public function setUp(): void
    {
        $this->httpDownloader = $this->getMockBuilder('Composer\Util\HttpDownloader')->disableOriginalConstructor()->getMock();
    }

    /**
     * @param \Composer\Util\HttpDownloader&\PHPUnit\Framework\MockObject\MockObject $httpDownloader
     */
    protected function getDownloader(?\Composer\IO\IOInterface $io = null, ?Config $config = null, ?EventDispatcher $eventDispatcher = null, ?\Composer\Cache $cache = null, $httpDownloader = null, ?Filesystem $filesystem = null): FileDownloader
    {
        $io = $io ?: $this->getMockBuilder('Composer\IO\IOInterface')->getMock();
        $config = $config ?: $this->getConfig();
        $httpDownloader = $httpDownloader ?: $this->getMockBuilder('Composer\Util\HttpDownloader')->disableOriginalConstructor()->getMock();
        $httpDownloader
            ->expects($this->any())
            ->method('addCopy')
            ->will($this->returnValue(\React\Promise\resolve(new Response(['url' => 'http://example.org/'], 200, [], 'file~'))));
        $this->httpDownloader = $httpDownloader;

        return new FileDownloader($io, $config, $httpDownloader, $eventDispatcher, $cache, $filesystem);
    }

    public function testDownloadForPackageWithoutDistReference(): void
    {
        $package = self::getPackage();

        self::expectException('InvalidArgumentException');

        $downloader = $this->getDownloader();
        $downloader->download($package, '/path');
    }

    public function testDownloadToExistingFile(): void
    {
        $package = self::getPackage();
        $package->setDistUrl('url');

        $path = $this->createTempFile(self::getUniqueTmpDirectory());
        $downloader = $this->getDownloader();

        try {
            $downloader->download($package, $path);
            $this->fail();
        } catch (\Exception $e) {
            if (is_dir($path)) {
                $fs = new Filesystem();
                $fs->removeDirectory($path);
            } elseif (is_file($path)) {
                unlink($path);
            }
            self::assertInstanceOf('RuntimeException', $e);
            self::assertStringContainsString('exists and is not a directory', $e->getMessage());
        }
    }

    public function testGetFileName(): void
    {
        $package = self::getPackage();
        $package->setDistUrl('http://example.com/script.js');

        $config = $this->getConfig(['vendor-dir' => '/vendor']);
        $downloader = $this->getDownloader(null, $config);
        $method = new \ReflectionMethod($downloader, 'getFileName');
        (\PHP_VERSION_ID < 80100) and $method->setAccessible(true);

        self::assertMatchesRegularExpression('#/vendor/composer/tmp-[a-z0-9]+\.js#', $method->invoke($downloader, $package, '/path'));
    }

    public function testDownloadButFileIsUnsaved(): void
    {
        $package = self::getPackage();
        $package->setDistUrl('http://example.com/script.js');

        $path = self::getUniqueTmpDirectory();
        $ioMock = $this->getMockBuilder('Composer\IO\IOInterface')->getMock();
        $ioMock->expects($this->any())
            ->method('write')
            ->will($this->returnCallback(static function ($messages, $newline = true) use ($path) {
                if (is_file($path.'/script.js')) {
                    unlink($path.'/script.js');
                }

                return $messages;
            }))
        ;

        $config = $this->getConfig(['vendor-dir' => $path.'/vendor']);
        $downloader = $this->getDownloader($ioMock, $config);

        try {
            $loop = new Loop($this->httpDownloader);
            $promise = $downloader->download($package, $path);
            $loop->wait([$promise]);

            $this->fail('Download was expected to throw');
        } catch (\Exception $e) {
            if (is_dir($path)) {
                $fs = new Filesystem();
                $fs->removeDirectory($path);
            } elseif (is_file($path)) {
                unlink($path);
            }

            self::assertInstanceOf('UnexpectedValueException', $e, $e->getMessage());
            self::assertStringContainsString('could not be saved to', $e->getMessage());
        }
    }

    public function testDownloadWithCustomProcessedUrl(): void
    {
        $path = self::getUniqueTmpDirectory();

        $package = self::getPackage();
        $package->setDistUrl('url');

        $rootPackage = self::getRootPackage();

        $config = $this->getConfig([
            'vendor-dir' => $path.'/vendor',
            'bin-dir' => $path.'/vendor/bin',
        ]);

        $composer = new Composer;
        $composer->setPackage($rootPackage);
        $composer->setConfig($config);
        $composer->setRepositoryManager($rm = new RepositoryManager(new NullIO(), $config, new HttpDownloaderMock()));
        $rm->setLocalRepository(new InstalledArrayRepository([]));
        $composer->setInstallationManager(new InstallationManagerMock());

        $expectedUrl = 'foobar';
        $expectedCacheKey = 'dummy/pkg/'.hash('sha1', $expectedUrl).'.';

        $dispatcher = new EventDispatcher(
            $composer,
            $this->getMockBuilder('Composer\IO\IOInterface')->getMock(),
            $this->getProcessExecutorMock()
        );
        $dispatcher->addListener(PluginEvents::PRE_FILE_DOWNLOAD, static function (PreFileDownloadEvent $event) use ($expectedUrl): void {
            $event->setProcessedUrl($expectedUrl);
        });
        $composer->setAutoloadGenerator(new AutoloadGenerator($dispatcher));

        $cacheMock = $this->getMockBuilder('Composer\Cache')
            ->disableOriginalConstructor()
            ->getMock();
        $cacheMock
            ->expects($this->any())
            ->method('copyTo')
            ->will($this->returnCallback(static function ($cacheKey) use ($expectedCacheKey): bool {
                self::assertEquals($expectedCacheKey, $cacheKey, 'Failed assertion on $cacheKey argument of Cache::copyTo method:');

                return false;
            }));
        $cacheMock
            ->expects($this->any())
            ->method('copyFrom')
            ->will($this->returnCallback(static function ($cacheKey) use ($expectedCacheKey): bool {
                self::assertEquals($expectedCacheKey, $cacheKey, 'Failed assertion on $cacheKey argument of Cache::copyFrom method:');

                return false;
            }));

        $httpDownloaderMock = $this->getMockBuilder('Composer\Util\HttpDownloader')->disableOriginalConstructor()->getMock();
        $httpDownloaderMock
            ->expects($this->any())
            ->method('addCopy')
            ->will($this->returnCallback(static function ($url) use ($expectedUrl) {
                self::assertEquals($expectedUrl, $url, 'Failed assertion on $url argument of HttpDownloader::addCopy method:');

                return \React\Promise\resolve(
                    new Response(['url' => 'http://example.org/'], 200, [], 'file~')
                );
            }));

        $downloader = $this->getDownloader(null, $config, $dispatcher, $cacheMock, $httpDownloaderMock);

        try {
            $loop = new Loop($this->httpDownloader);
            $promise = $downloader->download($package, $path);
            $loop->wait([$promise]);

            $this->fail('Download was expected to throw');
        } catch (\Exception $e) {
            if (is_dir($path)) {
                $fs = new Filesystem();
                $fs->removeDirectory($path);
            } elseif (is_file($path)) {
                unlink($path);
            }

            self::assertInstanceOf('UnexpectedValueException', $e, $e->getMessage());
            self::assertStringContainsString('could not be saved to', $e->getMessage());
        }
    }

    public function testDownloadWithCustomCacheKey(): void
    {
        $path = self::getUniqueTmpDirectory();

        $package = self::getPackage();
        $package->setDistUrl('url');

        $rootPackage = self::getRootPackage();

        $config = $this->getConfig([
            'vendor-dir' => $path.'/vendor',
            'bin-dir' => $path.'/vendor/bin',
        ]);

        $composer = new Composer;
        $composer->setPackage($rootPackage);
        $composer->setConfig($config);
        $composer->setRepositoryManager($rm = new RepositoryManager(new NullIO(), $config, new HttpDownloaderMock()));
        $rm->setLocalRepository(new InstalledArrayRepository([]));
        $composer->setInstallationManager(new InstallationManagerMock());

        $expectedUrl = 'url';
        $customCacheKey = 'xyzzy';
        $expectedCacheKey = 'dummy/pkg/'.hash('sha1', $customCacheKey).'.';

        $dispatcher = new EventDispatcher(
            $composer,
            $this->getMockBuilder('Composer\IO\IOInterface')->getMock(),
            $this->getProcessExecutorMock()
        );
        $dispatcher->addListener(PluginEvents::PRE_FILE_DOWNLOAD, static function (PreFileDownloadEvent $event) use ($customCacheKey): void {
            $event->setCustomCacheKey($customCacheKey);
        });
        $composer->setAutoloadGenerator(new AutoloadGenerator($dispatcher));

        $cacheMock = $this->getMockBuilder('Composer\Cache')
            ->disableOriginalConstructor()
            ->getMock();
        $cacheMock
            ->expects($this->any())
            ->method('copyTo')
            ->will($this->returnCallback(static function ($cacheKey) use ($expectedCacheKey): bool {
                self::assertEquals($expectedCacheKey, $cacheKey, 'Failed assertion on $cacheKey argument of Cache::copyTo method:');

                return false;
            }));
        $cacheMock
            ->expects($this->any())
            ->method('copyFrom')
            ->will($this->returnCallback(static function ($cacheKey) use ($expectedCacheKey): bool {
                self::assertEquals($expectedCacheKey, $cacheKey, 'Failed assertion on $cacheKey argument of Cache::copyFrom method:');

                return false;
            }));

        $httpDownloaderMock = $this->getMockBuilder('Composer\Util\HttpDownloader')->disableOriginalConstructor()->getMock();
        $httpDownloaderMock
            ->expects($this->any())
            ->method('addCopy')
            ->will($this->returnCallback(static function ($url) use ($expectedUrl) {
                self::assertEquals($expectedUrl, $url, 'Failed assertion on $url argument of HttpDownloader::addCopy method:');

                return \React\Promise\resolve(
                    new Response(['url' => 'http://example.org/'], 200, [], 'file~')
                );
            }));

        $downloader = $this->getDownloader(null, $config, $dispatcher, $cacheMock, $httpDownloaderMock);

        try {
            $loop = new Loop($this->httpDownloader);
            $promise = $downloader->download($package, $path);
            $loop->wait([$promise]);

            $this->fail('Download was expected to throw');
        } catch (\Exception $e) {
            if (is_dir($path)) {
                $fs = new Filesystem();
                $fs->removeDirectory($path);
            } elseif (is_file($path)) {
                unlink($path);
            }

            self::assertInstanceOf('UnexpectedValueException', $e, $e->getMessage());
            self::assertStringContainsString('could not be saved to', $e->getMessage());
        }
    }

    public function testCacheGarbageCollectionIsCalled(): void
    {
        $expectedTtl = '99999999';

        $config = $this->getConfig([
            'cache-files-ttl' => $expectedTtl,
            'cache-files-maxsize' => '500M',
        ]);

        $cacheMock = $this->getMockBuilder('Composer\Cache')
                     ->disableOriginalConstructor()
                     ->getMock();
        $cacheMock
            ->expects($this->any())
            ->method('gcIsNecessary')
            ->will($this->returnValue(true));
        $cacheMock
            ->expects($this->once())
            ->method('gc')
            ->with($expectedTtl, $this->anything());

        $downloader = $this->getDownloader(null, $config, null, $cacheMock, null, null);
    }

    public function testDownloadFileWithInvalidChecksum(): void
    {
        $package = self::getPackage();
        $package->setDistUrl($distUrl = 'http://example.com/script.js');
        $package->setDistSha1Checksum('invalid');

        $filesystem = $this->getMockBuilder('Composer\Util\Filesystem')->getMock();

        $path = self::getUniqueTmpDirectory();
        $config = $this->getConfig(['vendor-dir' => $path.'/vendor']);

        $downloader = $this->getDownloader(null, $config, null, null, null, $filesystem);

        // make sure the file expected to be downloaded is on disk already
        $method = new \ReflectionMethod($downloader, 'getFileName');
        (\PHP_VERSION_ID < 80100) and $method->setAccessible(true);
        $dlFile = $method->invoke($downloader, $package, $path);
        mkdir(dirname($dlFile), 0777, true);
        touch($dlFile);

        try {
            $loop = new Loop($this->httpDownloader);
            $promise = $downloader->download($package, $path);
            $loop->wait([$promise]);

            $this->fail('Download was expected to throw');
        } catch (\Exception $e) {
            if (is_dir($path)) {
                $fs = new Filesystem();
                $fs->removeDirectory($path);
            } elseif (is_file($path)) {
                unlink($path);
            }

            self::assertInstanceOf('UnexpectedValueException', $e, $e->getMessage());
            self::assertStringContainsString('checksum verification', $e->getMessage());
        }
    }

    public function testDowngradeShowsAppropriateMessage(): void
    {
        $oldPackage = self::getPackage('dummy/pkg', '1.2.0');
        $newPackage = self::getPackage('dummy/pkg', '1.0.0');
        $newPackage->setDistUrl($distUrl = 'http://example.com/script.js');

        $ioMock = $this->getIOMock();
        $ioMock->expects([
            ['text' => '{Downloading .*}', 'regex' => true],
            ['text' => '{Downgrading .*}', 'regex' => true],
        ]);

        $path = self::getUniqueTmpDirectory();
        $config = $this->getConfig(['vendor-dir' => $path.'/vendor']);

        $filesystem = $this->getMockBuilder('Composer\Util\Filesystem')->getMock();
        $filesystem->expects($this->once())
            ->method('removeDirectoryAsync')
            ->will($this->returnValue(\React\Promise\resolve(true)));
        $filesystem->expects($this->any())
            ->method('normalizePath')
            ->will(self::returnArgument(0));

        $downloader = $this->getDownloader($ioMock, $config, null, null, null, $filesystem);

        // make sure the file expected to be downloaded is on disk already
        $method = new \ReflectionMethod($downloader, 'getFileName');
        (\PHP_VERSION_ID < 80100) and $method->setAccessible(true);
        $dlFile = $method->invoke($downloader, $newPackage, $path);
        mkdir(dirname($dlFile), 0777, true);
        touch($dlFile);

        $loop = new Loop($this->httpDownloader);
        $promise = $downloader->download($newPackage, $path, $oldPackage);
        $loop->wait([$promise]);

        $downloader->update($oldPackage, $newPackage, $path);
    }
}
