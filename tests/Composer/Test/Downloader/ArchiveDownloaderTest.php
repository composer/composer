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

use Composer\Test\TestCase;

class ArchiveDownloaderTest extends TestCase
{
    /** @var \Composer\Config&\PHPUnit\Framework\MockObject\MockObject */
    protected $config;

    public function testGetFileName(): void
    {
        $packageMock = $this->getMockBuilder('Composer\Package\PackageInterface')->getMock();
        $packageMock->expects($this->any())
            ->method('getDistUrl')
            ->will($this->returnValue('http://example.com/script.js'))
        ;

        $downloader = $this->getArchiveDownloaderMock();
        $method = new \ReflectionMethod($downloader, 'getFileName');
        (\PHP_VERSION_ID < 80100) and $method->setAccessible(true);

        $this->config->expects($this->any())
            ->method('get')
            ->with('vendor-dir')
            ->will($this->returnValue('/vendor'));

        $first = $method->invoke($downloader, $packageMock, '/path');
        self::assertMatchesRegularExpression('#/vendor/composer/tmp-[a-z0-9]+\.js#', $first);
        self::assertSame($first, $method->invoke($downloader, $packageMock, '/path'));
    }

    public function testProcessUrl(): void
    {
        if (!extension_loaded('openssl')) {
            $this->markTestSkipped('Requires openssl');
        }

        $downloader = $this->getArchiveDownloaderMock();
        $method = new \ReflectionMethod($downloader, 'processUrl');
        (\PHP_VERSION_ID < 80100) and $method->setAccessible(true);

        $expected = 'https://github.com/composer/composer/zipball/master';
        $url = $method->invoke($downloader, $this->getMockBuilder('Composer\Package\PackageInterface')->getMock(), $expected);

        self::assertEquals($expected, $url);
    }

    public function testProcessUrl2(): void
    {
        if (!extension_loaded('openssl')) {
            $this->markTestSkipped('Requires openssl');
        }

        $downloader = $this->getArchiveDownloaderMock();
        $method = new \ReflectionMethod($downloader, 'processUrl');
        (\PHP_VERSION_ID < 80100) and $method->setAccessible(true);

        $expected = 'https://github.com/composer/composer/archive/master.tar.gz';
        $url = $method->invoke($downloader, $this->getMockBuilder('Composer\Package\PackageInterface')->getMock(), $expected);

        self::assertEquals($expected, $url);
    }

    public function testProcessUrl3(): void
    {
        if (!extension_loaded('openssl')) {
            $this->markTestSkipped('Requires openssl');
        }

        $downloader = $this->getArchiveDownloaderMock();
        $method = new \ReflectionMethod($downloader, 'processUrl');
        (\PHP_VERSION_ID < 80100) and $method->setAccessible(true);

        $expected = 'https://api.github.com/repos/composer/composer/zipball/master';
        $url = $method->invoke($downloader, $this->getMockBuilder('Composer\Package\PackageInterface')->getMock(), $expected);

        self::assertEquals($expected, $url);
    }

    /**
     * @dataProvider provideUrls
     */
    public function testProcessUrlRewriteDist(string $url): void
    {
        if (!extension_loaded('openssl')) {
            $this->markTestSkipped('Requires openssl');
        }

        $downloader = $this->getArchiveDownloaderMock();
        $method = new \ReflectionMethod($downloader, 'processUrl');
        (\PHP_VERSION_ID < 80100) and $method->setAccessible(true);

        $type = strpos($url, 'tar') ? 'tar' : 'zip';
        $expected = 'https://api.github.com/repos/composer/composer/'.$type.'ball/ref';

        $package = $this->getMockBuilder('Composer\Package\PackageInterface')->getMock();
        $package->expects($this->any())
            ->method('getDistReference')
            ->will($this->returnValue('ref'));
        $url = $method->invoke($downloader, $package, $url);

        self::assertEquals($expected, $url);
    }

    public static function provideUrls(): array
    {
        return [
            ['https://api.github.com/repos/composer/composer/zipball/master'],
            ['https://api.github.com/repos/composer/composer/tarball/master'],
            ['https://github.com/composer/composer/zipball/master'],
            ['https://www.github.com/composer/composer/tarball/master'],
            ['https://github.com/composer/composer/archive/master.zip'],
            ['https://github.com/composer/composer/archive/master.tar.gz'],
        ];
    }

    /**
     * @dataProvider provideBitbucketUrls
     */
    public function testProcessUrlRewriteBitbucketDist(string $url, string $extension): void
    {
        if (!extension_loaded('openssl')) {
            $this->markTestSkipped('Requires openssl');
        }

        $downloader = $this->getArchiveDownloaderMock();
        $method = new \ReflectionMethod($downloader, 'processUrl');
        (\PHP_VERSION_ID < 80100) and $method->setAccessible(true);

        $url .= '.' . $extension;
        $expected = 'https://bitbucket.org/davereid/drush-virtualhost/get/ref.' . $extension;

        $package = $this->getMockBuilder('Composer\Package\PackageInterface')->getMock();
        $package->expects($this->any())
            ->method('getDistReference')
            ->will($this->returnValue('ref'));
        $url = $method->invoke($downloader, $package, $url);

        self::assertEquals($expected, $url);
    }

    public static function provideBitbucketUrls(): array
    {
        return [
            ['https://bitbucket.org/davereid/drush-virtualhost/get/77ca490c26ac818e024d1138aa8bd3677d1ef21f', 'zip'],
            ['https://bitbucket.org/davereid/drush-virtualhost/get/master', 'tar.gz'],
            ['https://bitbucket.org/davereid/drush-virtualhost/get/v1.0', 'tar.bz2'],
        ];
    }

    /**
     * @return \Composer\Downloader\ArchiveDownloader&\PHPUnit\Framework\MockObject\MockObject
     */
    private function getArchiveDownloaderMock()
    {
        return $this->getMockForAbstractClass(
            'Composer\Downloader\ArchiveDownloader',
            [
                $io = $this->getMockBuilder('Composer\IO\IOInterface')->getMock(),
                $this->config = $this->getMockBuilder('Composer\Config')->getMock(),
                new \Composer\Util\HttpDownloader($io, $this->config),
            ]
        );
    }
}
