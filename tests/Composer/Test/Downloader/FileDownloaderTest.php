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

use Composer\Config;
use Composer\Downloader\FileDownloader;
use Composer\EventDispatcher\EventDispatcher;
use Composer\Plugin\PluginEvents;
use Composer\Plugin\PreFileDownloadEvent;
use Composer\Test\TestCase;
use Composer\Test\Mock\ProcessExecutorMock;
use Composer\Util\Filesystem;
use Composer\Util\Http\Response;
use Composer\Util\Loop;

class FileDownloaderTest extends TestCase
{
    /** @var \Composer\Util\HttpDownloader&\PHPUnit\Framework\MockObject\MockObject */
    private $httpDownloader;
    /** @var \Composer\Config&\PHPUnit\Framework\MockObject\MockObject */
    private $config;

    public function setUp(): void
    {
        $this->httpDownloader = $this->getMockBuilder('Composer\Util\HttpDownloader')->disableOriginalConstructor()->getMock();
        $this->config = $this->getMockBuilder('Composer\Config')->getMock();
    }

    /**
     * @param \Composer\IO\IOInterface $io
     * @param \Composer\Config&\PHPUnit\Framework\MockObject\MockObject $config
     * @param \Composer\EventDispatcher\EventDispatcher $eventDispatcher
     * @param \Composer\Cache $cache
     * @param \Composer\Util\HttpDownloader&\PHPUnit\Framework\MockObject\MockObject $httpDownloader
     * @param \Composer\Util\Filesystem $filesystem
     * @return \Composer\Downloader\FileDownloader
     */
    protected function getDownloader($io = null, $config = null, $eventDispatcher = null, $cache = null, $httpDownloader = null, $filesystem = null)
    {
        $io = $io ?: $this->getMockBuilder('Composer\IO\IOInterface')->getMock();
        $config = $config ?: $this->config;
        $httpDownloader = $httpDownloader ?: $this->getMockBuilder('Composer\Util\HttpDownloader')->disableOriginalConstructor()->getMock();
        $httpDownloader
            ->expects($this->any())
            ->method('addCopy')
            ->will($this->returnValue(\React\Promise\resolve(new Response(array('url' => 'http://example.org/'), 200, array(), 'file~'))));
        $this->httpDownloader = $httpDownloader;

        return new FileDownloader($io, $this->config, $httpDownloader, $eventDispatcher, $cache, $filesystem);
    }

    public function testDownloadForPackageWithoutDistReference()
    {
        $packageMock = $this->getMockBuilder('Composer\Package\PackageInterface')->getMock();
        $packageMock->expects($this->once())
            ->method('getDistUrl')
            ->will($this->returnValue(null))
        ;

        self::expectException('InvalidArgumentException');

        $downloader = $this->getDownloader();
        $downloader->download($packageMock, '/path');
    }

    public function testDownloadToExistingFile()
    {
        $packageMock = $this->getMockBuilder('Composer\Package\PackageInterface')->getMock();
        $packageMock->expects($this->any())
            ->method('getDistUrl')
            ->will($this->returnValue('url'))
        ;
        $packageMock->expects($this->any())
            ->method('getDistUrls')
            ->will($this->returnValue(array('url')))
        ;

        $path = $this->createTempFile($this->getUniqueTmpDirectory());
        $downloader = $this->getDownloader();

        try {
            $downloader->download($packageMock, $path);
            $this->fail();
        } catch (\Exception $e) {
            if (is_dir($path)) {
                $fs = new Filesystem();
                $fs->removeDirectory($path);
            } elseif (is_file($path)) {
                unlink($path);
            }
            $this->assertInstanceOf('RuntimeException', $e);
            $this->assertStringContainsString('exists and is not a directory', $e->getMessage());
        }
    }

    public function testGetFileName()
    {
        $packageMock = $this->getMockBuilder('Composer\Package\PackageInterface')->getMock();
        $packageMock->expects($this->once())
            ->method('getDistUrl')
            ->will($this->returnValue('http://example.com/script.js'))
        ;

        $downloader = $this->getDownloader();
        $method = new \ReflectionMethod($downloader, 'getFileName');
        $method->setAccessible(true);

        $this->config->expects($this->once())
            ->method('get')
            ->with('vendor-dir')
            ->will($this->returnValue('/vendor'));

        $this->assertMatchesRegularExpression('#/vendor/composer/tmp-[a-z0-9]+\.js#', $method->invoke($downloader, $packageMock, '/path'));
    }

    public function testDownloadButFileIsUnsaved()
    {
        $packageMock = $this->getMockBuilder('Composer\Package\PackageInterface')->getMock();
        $packageMock->expects($this->any())
            ->method('getDistUrl')
            ->will($this->returnValue($distUrl = 'http://example.com/script.js'))
        ;
        $packageMock->expects($this->once())
            ->method('getDistUrls')
            ->will($this->returnValue(array($distUrl)))
        ;
        $packageMock->expects($this->atLeastOnce())
            ->method('getTransportOptions')
            ->will($this->returnValue(array()))
        ;

        $path = $this->getUniqueTmpDirectory();
        $ioMock = $this->getMockBuilder('Composer\IO\IOInterface')->getMock();
        $ioMock->expects($this->any())
            ->method('write')
            ->will($this->returnCallback(function ($messages, $newline = true) use ($path) {
                if (is_file($path.'/script.js')) {
                    unlink($path.'/script.js');
                }

                return $messages;
            }))
        ;

        $downloader = $this->getDownloader($ioMock);

        $this->config->expects($this->once())
            ->method('get')
            ->with('vendor-dir')
            ->will($this->returnValue($path.'/vendor'));

        try {
            $loop = new Loop($this->httpDownloader);
            $promise = $downloader->download($packageMock, $path);
            $loop->wait(array($promise));

            $this->fail('Download was expected to throw');
        } catch (\Exception $e) {
            if (is_dir($path)) {
                $fs = new Filesystem();
                $fs->removeDirectory($path);
            } elseif (is_file($path)) {
                unlink($path);
            }

            $this->assertInstanceOf('UnexpectedValueException', $e, $e->getMessage());
            $this->assertStringContainsString('could not be saved to', $e->getMessage());
        }
    }

    public function testDownloadWithCustomProcessedUrl()
    {
        $path = $this->getUniqueTmpDirectory();

        $packageMock = $this->getMockBuilder('Composer\Package\PackageInterface')->getMock();
        $packageMock->expects($this->any())
            ->method('getDistUrl')
            ->will($this->returnValue('url'));
        $packageMock->expects($this->any())
            ->method('getDistUrls')
            ->will($this->returnValue(array('url')));

        $rootPackageMock = $this->getMockBuilder('Composer\Package\RootPackageInterface')->getMock();
        $rootPackageMock->expects($this->any())
            ->method('getScripts')
            ->will($this->returnValue(array()));

        $composerMock = $this->getMockBuilder('Composer\Composer')->getMock();
        $composerMock->expects($this->any())
            ->method('getPackage')
            ->will($this->returnValue($rootPackageMock));
        $composerMock->expects($this->any())
            ->method('getConfig')
            ->will($this->returnValue($this->config));
        $this->config->expects($this->any())
            ->method('get')
            ->will($this->returnCallback(function ($key) use ($path) {
                if ($key === 'vendor-dir') {
                    return $path.'/vendor';
                } elseif ($key === 'bin-dir') {
                    return $path.'/vendor/bin';
                }
            }));

        $expectedUrl = 'foobar';
        $expectedCacheKey = '/'.sha1($expectedUrl).'.';

        $dispatcher = new EventDispatcher(
            $composerMock,
            $this->getMockBuilder('Composer\IO\IOInterface')->getMock(),
            $this->getProcessExecutorMock()
        );
        $dispatcher->addListener(PluginEvents::PRE_FILE_DOWNLOAD, function (PreFileDownloadEvent $event) use ($expectedUrl) {
            $event->setProcessedUrl($expectedUrl);
        });

        $cacheMock = $this->getMockBuilder('Composer\Cache')
            ->disableOriginalConstructor()
            ->getMock();
        $cacheMock
            ->expects($this->any())
            ->method('copyTo')
            ->will($this->returnCallback(function ($cacheKey) use ($expectedCacheKey) {
                $this->assertEquals($expectedCacheKey, $cacheKey, 'Failed assertion on $cacheKey argument of Cache::copyTo method:');

                return false;
            }));
        $cacheMock
            ->expects($this->any())
            ->method('copyFrom')
            ->will($this->returnCallback(function ($cacheKey) use ($expectedCacheKey) {
                $this->assertEquals($expectedCacheKey, $cacheKey, 'Failed assertion on $cacheKey argument of Cache::copyFrom method:');

                return false;
            }));

        $httpDownloaderMock = $this->getMockBuilder('Composer\Util\HttpDownloader')->disableOriginalConstructor()->getMock();
        $httpDownloaderMock
            ->expects($this->any())
            ->method('addCopy')
            ->will($this->returnCallback(function ($url) use ($expectedUrl) {
                $this->assertEquals($expectedUrl, $url, 'Failed assertion on $url argument of HttpDownloader::addCopy method:');

                return \React\Promise\resolve(
                    new Response(array('url' => 'http://example.org/'), 200, array(), 'file~')
                );
            }));

        $downloader = $this->getDownloader(null, $this->config, $dispatcher, $cacheMock, $httpDownloaderMock);

        try {
            $loop = new Loop($this->httpDownloader);
            $promise = $downloader->download($packageMock, $path);
            $loop->wait(array($promise));

            $this->fail('Download was expected to throw');
        } catch (\Exception $e) {
            if (is_dir($path)) {
                $fs = new Filesystem();
                $fs->removeDirectory($path);
            } elseif (is_file($path)) {
                unlink($path);
            }

            $this->assertInstanceOf('UnexpectedValueException', $e, $e->getMessage());
            $this->assertStringContainsString('could not be saved to', $e->getMessage());
        }
    }

    public function testDownloadWithCustomCacheKey()
    {
        $path = $this->getUniqueTmpDirectory();

        $packageMock = $this->getMockBuilder('Composer\Package\PackageInterface')->getMock();
        $packageMock->expects($this->any())
            ->method('getDistUrl')
            ->will($this->returnValue('url'));
        $packageMock->expects($this->any())
            ->method('getDistUrls')
            ->will($this->returnValue(array('url')));

        $rootPackageMock = $this->getMockBuilder('Composer\Package\RootPackageInterface')->getMock();
        $rootPackageMock->expects($this->any())
            ->method('getScripts')
            ->will($this->returnValue(array()));

        $composerMock = $this->getMockBuilder('Composer\Composer')->getMock();
        $composerMock->expects($this->any())
            ->method('getPackage')
            ->will($this->returnValue($rootPackageMock));
        $composerMock->expects($this->any())
            ->method('getConfig')
            ->will($this->returnValue($this->config));
        $this->config->expects($this->any())
            ->method('get')
            ->will($this->returnCallback(function ($key) use ($path) {
                if ($key === 'vendor-dir') {
                    return $path.'/vendor';
                } elseif ($key === 'bin-dir') {
                    return $path.'/vendor/bin';
                }
            }));

        $expectedUrl = 'url';
        $customCacheKey = 'xyzzy';
        $expectedCacheKey = '/'.sha1($customCacheKey).'.';

        $dispatcher = new EventDispatcher(
            $composerMock,
            $this->getMockBuilder('Composer\IO\IOInterface')->getMock(),
            $this->getProcessExecutorMock()
        );
        $dispatcher->addListener(PluginEvents::PRE_FILE_DOWNLOAD, function (PreFileDownloadEvent $event) use ($customCacheKey) {
            $event->setCustomCacheKey($customCacheKey);
        });

        $cacheMock = $this->getMockBuilder('Composer\Cache')
            ->disableOriginalConstructor()
            ->getMock();
        $cacheMock
            ->expects($this->any())
            ->method('copyTo')
            ->will($this->returnCallback(function ($cacheKey) use ($expectedCacheKey) {
                $this->assertEquals($expectedCacheKey, $cacheKey, 'Failed assertion on $cacheKey argument of Cache::copyTo method:');

                return false;
            }));
        $cacheMock
            ->expects($this->any())
            ->method('copyFrom')
            ->will($this->returnCallback(function ($cacheKey) use ($expectedCacheKey) {
                $this->assertEquals($expectedCacheKey, $cacheKey, 'Failed assertion on $cacheKey argument of Cache::copyFrom method:');

                return false;
            }));

        $httpDownloaderMock = $this->getMockBuilder('Composer\Util\HttpDownloader')->disableOriginalConstructor()->getMock();
        $httpDownloaderMock
            ->expects($this->any())
            ->method('addCopy')
            ->will($this->returnCallback(function ($url) use ($expectedUrl) {
                $this->assertEquals($expectedUrl, $url, 'Failed assertion on $url argument of HttpDownloader::addCopy method:');

                return \React\Promise\resolve(
                    new Response(array('url' => 'http://example.org/'), 200, array(), 'file~')
                );
            }));

        $downloader = $this->getDownloader(null, $this->config, $dispatcher, $cacheMock, $httpDownloaderMock);

        try {
            $loop = new Loop($this->httpDownloader);
            $promise = $downloader->download($packageMock, $path);
            $loop->wait(array($promise));

            $this->fail('Download was expected to throw');
        } catch (\Exception $e) {
            if (is_dir($path)) {
                $fs = new Filesystem();
                $fs->removeDirectory($path);
            } elseif (is_file($path)) {
                unlink($path);
            }

            $this->assertInstanceOf('UnexpectedValueException', $e, $e->getMessage());
            $this->assertStringContainsString('could not be saved to', $e->getMessage());
        }
    }

    public function testCacheGarbageCollectionIsCalled()
    {
        $expectedTtl = '99999999';

        $this->config = $this->getMockBuilder('Composer\Config')->getMock();
        $this->config
            ->expects($this->atLeast(2))
            ->method('get')
            ->willReturnMap([
                ['cache-files-ttl', 0, $expectedTtl],
                ['cache-files-maxsize', 0, '500M'],
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

        $downloader = $this->getDownloader(null, $this->config, null, $cacheMock, null, null);
    }

    public function testDownloadFileWithInvalidChecksum()
    {
        $packageMock = $this->getMockBuilder('Composer\Package\PackageInterface')->getMock();
        $packageMock->expects($this->any())
            ->method('getDistUrl')
            ->will($this->returnValue($distUrl = 'http://example.com/script.js'))
        ;
        $packageMock->expects($this->atLeastOnce())
            ->method('getTransportOptions')
            ->will($this->returnValue(array()))
        ;
        $packageMock->expects($this->any())
            ->method('getDistSha1Checksum')
            ->will($this->returnValue('invalid'))
        ;
        $packageMock->expects($this->once())
            ->method('getDistUrls')
            ->will($this->returnValue(array($distUrl)))
        ;
        $filesystem = $this->getMockBuilder('Composer\Util\Filesystem')->getMock();

        $path = $this->getUniqueTmpDirectory();
        $downloader = $this->getDownloader(null, null, null, null, null, $filesystem);

        // make sure the file expected to be downloaded is on disk already
        $this->config->expects($this->any())
            ->method('get')
            ->with('vendor-dir')
            ->will($this->returnValue($path.'/vendor'));

        $method = new \ReflectionMethod($downloader, 'getFileName');
        $method->setAccessible(true);
        $dlFile = $method->invoke($downloader, $packageMock, $path);
        mkdir(dirname($dlFile), 0777, true);
        touch($dlFile);

        try {
            $loop = new Loop($this->httpDownloader);
            $promise = $downloader->download($packageMock, $path);
            $loop->wait(array($promise));

            $this->fail('Download was expected to throw');
        } catch (\Exception $e) {
            if (is_dir($path)) {
                $fs = new Filesystem();
                $fs->removeDirectory($path);
            } elseif (is_file($path)) {
                unlink($path);
            }

            $this->assertInstanceOf('UnexpectedValueException', $e, $e->getMessage());
            $this->assertStringContainsString('checksum verification', $e->getMessage());
        }
    }

    public function testDowngradeShowsAppropriateMessage()
    {
        $oldPackage = $this->getMockBuilder('Composer\Package\PackageInterface')->getMock();
        $oldPackage->expects($this->once())
            ->method('getFullPrettyVersion')
            ->will($this->returnValue('1.2.0'));
        $oldPackage->expects($this->once())
            ->method('getVersion')
            ->will($this->returnValue('1.2.0.0'));

        $newPackage = $this->getMockBuilder('Composer\Package\PackageInterface')->getMock();
        $newPackage->expects($this->any())
            ->method('getFullPrettyVersion')
            ->will($this->returnValue('1.0.0'));
        $newPackage->expects($this->once())
            ->method('getVersion')
            ->will($this->returnValue('1.0.0.0'));
        $newPackage->expects($this->any())
            ->method('getDistUrl')
            ->will($this->returnValue($distUrl = 'http://example.com/script.js'));
        $newPackage->expects($this->once())
            ->method('getDistUrls')
            ->will($this->returnValue(array($distUrl)));

        $ioMock = $this->getMockBuilder('Composer\IO\IOInterface')->getMock();
        $ioMock->expects($this->atLeast(2))
            ->method('writeError')
            ->withConsecutive(
                [$this->stringContains('Downloading')],
                [$this->stringContains('Downgrading')]
            );

        $path = $this->getUniqueTmpDirectory();
        $filesystem = $this->getMockBuilder('Composer\Util\Filesystem')->getMock();
        $filesystem->expects($this->once())
            ->method('removeDirectoryAsync')
            ->will($this->returnValue(\React\Promise\resolve(true)));

        $downloader = $this->getDownloader($ioMock, null, null, null, null, $filesystem);

        // make sure the file expected to be downloaded is on disk already
        $this->config->expects($this->any())
            ->method('get')
            ->with('vendor-dir')
            ->will($this->returnValue($path.'/vendor'));

        $method = new \ReflectionMethod($downloader, 'getFileName');
        $method->setAccessible(true);
        $dlFile = $method->invoke($downloader, $newPackage, $path);
        mkdir(dirname($dlFile), 0777, true);
        touch($dlFile);

        $loop = new Loop($this->httpDownloader);
        $promise = $downloader->download($newPackage, $path, $oldPackage);
        $loop->wait(array($promise));

        $downloader->update($oldPackage, $newPackage, $path);
    }
}
