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

use Composer\Downloader\FileDownloader;
use Composer\Downloader\TransportException;
use Composer\EventDispatcher\EventDispatcher;
use Composer\Plugin\PluginEvents;
use Composer\Plugin\PreFileDownloadEvent;
use Composer\Test\TestCase;
use Composer\Util\Filesystem;
use Composer\Util\Http\Response;
use Composer\Util\Loop;
use Composer\Config;
use Composer\PartialComposer;

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

    public function testInstallDoesNotChmodBinOutsideOfPackage(): void
    {
        $rootDir = self::getUniqueTmpDirectory();
        $vendorDir = $rootDir.'/vendor';
        $path = $vendorDir.'/attacker/pkg';

        $package = self::getPackage();
        $package->setDistUrl('http://example.com/script.js');
        // a ".." bin which never went through ValidatingArrayLoader::validatePackage(), as is the
        // case for composer reinstall which builds its operations straight from installed.json
        $package->setBinaries(['../../../victim.sh']);

        $victim = $rootDir.'/victim.sh';
        file_put_contents($victim, "#!/bin/sh\necho pwned\n");
        chmod($victim, 0600);
        clearstatcache();
        $modeBefore = fileperms($victim);

        $downloader = $this->getDownloader(null, $this->getConfig(['vendor-dir' => $vendorDir]));

        // seed the downloaded file where install() expects to find it
        $method = new \ReflectionMethod($downloader, 'getFileName');
        (\PHP_VERSION_ID < 80100) and $method->setAccessible(true);
        $tmpFile = $method->invoke($downloader, $package, $path);
        $fs = new Filesystem();
        $fs->ensureDirectoryExists(dirname($tmpFile));
        file_put_contents($tmpFile, 'downloaded');

        try {
            $downloader->install($package, $path, false);

            self::assertFileExists($path.'/script.js');
            clearstatcache();
            self::assertSame($modeBefore, fileperms($victim), 'A bin escaping the package dir must not be chmod\'d');
        } finally {
            $fs->removeDirectory($rootDir);
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

        $composer = new PartialComposer;
        $composer->setPackage($rootPackage);
        $composer->setConfig($config);

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

        $composer = new PartialComposer;
        $composer->setPackage($rootPackage);
        $composer->setConfig($config);

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

    /**
     * @dataProvider provideMirrorHeaderScenarios
     * @param ?array{url: string, preferred: bool} $mirror
     */
    public function testRepositoryHeadersAreOnlySentToTheRepositoryOrigin(string $distUrl, ?array $mirror, string $contactedUrl, bool $expectHeader): void
    {
        $path = self::getUniqueTmpDirectory();

        $package = self::getPackage();
        $package->setDistType('zip');
        $package->setDistUrl($distUrl);
        if ($mirror !== null) {
            // a preferred mirror is tried before the canonical URL
            $package->setDistMirrors([$mirror]);
        }
        // the repository http header (restored from the repository config) carries a credential
        $package->setTransportOptions(['http' => ['header' => ['Authorization: Bearer SECRET']]]);

        $config = $this->getConfig([
            'vendor-dir' => $path.'/vendor',
            'bin-dir' => $path.'/vendor/bin',
        ]);

        $seenOptions = [];
        $httpDownloaderMock = $this->getMockBuilder('Composer\Util\HttpDownloader')->disableOriginalConstructor()->getMock();
        $httpDownloaderMock
            ->expects($this->any())
            ->method('addCopy')
            ->will($this->returnCallback(static function ($url, $to, $options) use (&$seenOptions) {
                $seenOptions[$url] = $options;

                return \React\Promise\resolve(
                    new Response(['url' => $url], 200, [], 'file~')
                );
            }));

        $downloader = $this->getDownloader(null, $config, null, null, $httpDownloaderMock);

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
        }

        self::assertArrayHasKey($contactedUrl, $seenOptions, 'expected the first candidate URL to be contacted');
        if ($expectHeader) {
            self::assertSame(['Authorization: Bearer SECRET'], $seenOptions[$contactedUrl]['http']['header'] ?? null, 'the repository header must be sent to the repository origin');
        } else {
            self::assertArrayNotHasKey('header', $seenOptions[$contactedUrl]['http'] ?? [], 'the repository header must not be sent to a mirror on another origin');
        }
    }

    /**
     * @return array<string, array{string, ?array{url: string, preferred: bool}, string, bool}>
     */
    public function provideMirrorHeaderScenarios(): array
    {
        $canonical = 'https://repo.example.org/dist/pkg.zip';
        $pref = static function (string $url): array {
            return ['url' => $url, 'preferred' => true];
        };

        return [
            'no mirror: canonical receives the header' => [
                $canonical, null, $canonical, true,
            ],
            'preferred mirror on the same origin keeps the header' => [
                $canonical, $pref('https://repo.example.org/mirror/pkg.zip'), 'https://repo.example.org/mirror/pkg.zip', true,
            ],
            'preferred mirror on another host is stripped' => [
                $canonical, $pref('https://mirror.example.net/pkg.zip'), 'https://mirror.example.net/pkg.zip', false,
            ],
            'userinfo pointing at another host is stripped' => [
                $canonical, $pref('https://repo.example.org@evil.example.net/pkg.zip'), 'https://repo.example.org@evil.example.net/pkg.zip', false,
            ],
            'different port on the same host is stripped' => [
                $canonical, $pref('https://repo.example.org:8443/pkg.zip'), 'https://repo.example.org:8443/pkg.zip', false,
            ],
            'http scheme on the same host is stripped' => [
                $canonical, $pref('http://repo.example.org/pkg.zip'), 'http://repo.example.org/pkg.zip', false,
            ],
            'URLs without a parseable origin never match each other' => [
                'https:///dist/pkg.zip', $pref('https:///mirror/pkg.zip'), 'https:///mirror/pkg.zip', false,
            ],
        ];
    }

    public function testRepositoryHeadersAreSentToTheCanonicalUrlAfterAMirrorFailed(): void
    {
        $path = self::getUniqueTmpDirectory();
        $canonical = 'https://repo.example.org/dist/pkg.zip';
        $mirror = 'https://mirror.example.net/pkg.zip';

        $package = self::getPackage();
        $package->setDistType('zip');
        $package->setDistUrl($canonical);
        $package->setDistMirrors([['url' => $mirror, 'preferred' => true]]);
        $package->setTransportOptions(['http' => ['header' => ['Authorization: Bearer SECRET']]]);

        $config = $this->getConfig([
            'vendor-dir' => $path.'/vendor',
            'bin-dir' => $path.'/vendor/bin',
        ]);

        $seenOptions = [];
        $httpDownloaderMock = $this->getMockBuilder('Composer\Util\HttpDownloader')->disableOriginalConstructor()->getMock();
        $httpDownloaderMock
            ->expects($this->any())
            ->method('addCopy')
            ->will($this->returnCallback(static function ($url, $to, $options) use (&$seenOptions, $mirror) {
                $seenOptions[$url] = $options;
                if ($url === $mirror) {
                    // a 404 moves on to the next URL without retrying
                    return \React\Promise\reject(new TransportException('Not found', 404));
                }

                return \React\Promise\resolve(
                    new Response(['url' => $url], 200, [], 'file~')
                );
            }));

        $downloader = $this->getDownloader(null, $config, null, null, $httpDownloaderMock);

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
        }

        self::assertSame([$mirror, $canonical], array_keys($seenOptions));
        self::assertArrayNotHasKey('header', $seenOptions[$mirror]['http'] ?? []);
        self::assertSame(['Authorization: Bearer SECRET'], $seenOptions[$canonical]['http']['header'] ?? null);
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
