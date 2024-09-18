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

namespace Composer\Test\Util;

use Composer\Config;
use Composer\IO\ConsoleIO;
use Composer\IO\IOInterface;
use Composer\Util\AuthHelper;
use Composer\Util\RemoteFilesystem;
use Composer\Test\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use ReflectionMethod;
use ReflectionProperty;

class RemoteFilesystemTest extends TestCase
{
    public function testGetOptionsForUrl(): void
    {
        $io = $this->getIOInterfaceMock();
        $io
            ->expects($this->once())
            ->method('hasAuthentication')
            ->willReturn(false)
        ;

        $res = $this->callGetOptionsForUrl($io, ['http://example.org', []]);
        self::assertTrue(isset($res['http']['header']) && is_array($res['http']['header']), 'getOptions must return an array with headers');
    }

    public function testGetOptionsForUrlWithAuthorization(): void
    {
        $io = $this->getIOInterfaceMock();
        $io
            ->expects($this->once())
            ->method('hasAuthentication')
            ->willReturn(true)
        ;
        $io
            ->expects($this->once())
            ->method('getAuthentication')
            ->willReturn(['username' => 'login', 'password' => 'password'])
        ;

        $options = $this->callGetOptionsForUrl($io, ['http://example.org', []]);

        $found = false;
        foreach ($options['http']['header'] as $header) {
            if (0 === strpos($header, 'Authorization: Basic')) {
                $found = true;
            }
        }
        self::assertTrue($found, 'getOptions must have an Authorization header');
    }

    public function testGetOptionsForUrlWithStreamOptions(): void
    {
        $io = $this->getIOInterfaceMock();
        $io
            ->expects($this->once())
            ->method('hasAuthentication')
            ->willReturn(true)
        ;

        $io
            ->expects($this->once())
            ->method('getAuthentication')
            ->willReturn(['username' => null, 'password' => null])
        ;

        $streamOptions = ['ssl' => [
            'allow_self_signed' => true,
        ]];

        $res = $this->callGetOptionsForUrl($io, ['https://example.org', []], $streamOptions);
        self::assertTrue(
            isset($res['ssl'], $res['ssl']['allow_self_signed']) && true === $res['ssl']['allow_self_signed'],
            'getOptions must return an array with a allow_self_signed set to true'
        );
    }

    public function testGetOptionsForUrlWithCallOptionsKeepsHeader(): void
    {
        $io = $this->getIOInterfaceMock();
        $io
            ->expects($this->once())
            ->method('hasAuthentication')
            ->willReturn(true)
        ;

        $io
            ->expects($this->once())
            ->method('getAuthentication')
            ->willReturn(['username' => null, 'password' => null])
        ;

        $streamOptions = ['http' => [
            'header' => 'Foo: bar',
        ]];

        $res = $this->callGetOptionsForUrl($io, ['https://example.org', $streamOptions]);
        self::assertTrue(isset($res['http']['header']), 'getOptions must return an array with a http.header key');

        $found = false;
        foreach ($res['http']['header'] as $header) {
            if ($header === 'Foo: bar') {
                $found = true;
            }
        }

        self::assertTrue($found, 'getOptions must have a Foo: bar header');
        self::assertGreaterThan(1, count($res['http']['header']));
    }

    public function testCallbackGetFileSize(): void
    {
        $fs = new RemoteFilesystem($this->getIOInterfaceMock(), $this->getConfigMock());
        $this->callCallbackGet($fs, STREAM_NOTIFY_FILE_SIZE_IS, 0, '', 0, 0, 20);
        self::assertAttributeEqualsCustom(20, 'bytesMax', $fs);
    }

    public function testCallbackGetNotifyProgress(): void
    {
        $io = $this->getIOInterfaceMock();
        $io
            ->expects($this->once())
            ->method('overwriteError')
        ;

        $fs = new RemoteFilesystem($io, $this->getConfigMock());
        $this->setAttribute($fs, 'bytesMax', 20);
        $this->setAttribute($fs, 'progress', true);

        $this->callCallbackGet($fs, STREAM_NOTIFY_PROGRESS, 0, '', 0, 10, 20);
        self::assertAttributeEqualsCustom(50, 'lastProgress', $fs);
    }

    /**
     * @doesNotPerformAssertions
     */
    public function testCallbackGetPassesThrough404(): void
    {
        $fs = new RemoteFilesystem($this->getIOInterfaceMock(), $this->getConfigMock());

        $this->callCallbackGet($fs, STREAM_NOTIFY_FAILURE, 0, 'HTTP/1.1 404 Not Found', 404, 0, 0);
    }

    public function testGetContents(): void
    {
        $fs = new RemoteFilesystem($this->getIOInterfaceMock(), $this->getConfigMock());

        self::assertStringContainsString('testGetContents', (string) $fs->getContents('http://example.org', 'file://'.__FILE__));
    }

    public function testCopy(): void
    {
        $fs = new RemoteFilesystem($this->getIOInterfaceMock(), $this->getConfigMock());

        $file = $this->createTempFile();
        self::assertTrue($fs->copy('http://example.org', 'file://'.__FILE__, $file));
        self::assertFileExists($file);
        self::assertStringContainsString('testCopy', (string) file_get_contents($file));
        unlink($file);
    }

    public function testCopyWithNoRetryOnFailure(): void
    {
        self::expectException('Composer\Downloader\TransportException');
        $fs = $this->getRemoteFilesystemWithMockedMethods(['getRemoteContents']);

        $fs->expects($this->once())->method('getRemoteContents')
            ->willReturnCallback(static function ($originUrl, $fileUrl, $ctx, &$http_response_header): string {
                $http_response_header = ['http/1.1 401 unauthorized'];

                return '';
            });

        $file = $this->createTempFile();
        unlink($file);

        $fs->copy(
            'http://example.org',
            'file://' . __FILE__,
            $file,
            true,
            ['retry-auth-failure' => false]
        );
    }

    public function testCopyWithSuccessOnRetry(): void
    {
        $authHelper = $this->getAuthHelperWithMockedMethods(['promptAuthIfNeeded']);
        $fs = $this->getRemoteFilesystemWithMockedMethods(['getRemoteContents'], $authHelper);

        $authHelper->expects($this->once())
            ->method('promptAuthIfNeeded')
            ->willReturn([
                'storeAuth' => true,
                'retry' => true,
            ]);

        $counter = 0;
        $fs->expects($this->exactly(2))
            ->method('getRemoteContents')
            ->willReturnCallback(static function ($originUrl, $fileUrl, $ctx, &$http_response_header) use (&$counter) {
                if ($counter++ === 0) {
                    $http_response_header = ['http/1.1 401 unauthorized'];

                    return '';
                } else {
                    $http_response_header = ['http/1.1 200 OK'];

                    return '<?php $copied = "Copied"; ';
                }
            });

        $file = $this->createTempFile();

        $copyResult = $fs->copy(
            'http://example.org',
            'file://' . __FILE__,
            $file,
            true,
            ['retry-auth-failure' => true]
        );

        self::assertTrue($copyResult);
        self::assertFileExists($file);
        self::assertStringContainsString('Copied', (string) file_get_contents($file));

        unlink($file);
    }

    /**
     * @group TLS
     */
    public function testGetOptionsForUrlCreatesSecureTlsDefaults(): void
    {
        $io = $this->getIOInterfaceMock();

        $res = $this->callGetOptionsForUrl($io, ['example.org', ['ssl' => ['cafile' => '/some/path/file.crt']]], [], 'http://www.example.org');

        self::assertTrue(isset($res['ssl']['ciphers']));
        self::assertMatchesRegularExpression('|!aNULL:!eNULL:!EXPORT:!DES:!RC4:!MD5:!PSK:!aECDH:!EDH-DSS-DES-CBC3-SHA:!EDH-RSA-DES-CBC3-SHA:!KRB5-DES-CBC3-SHA|', $res['ssl']['ciphers']);
        self::assertTrue($res['ssl']['verify_peer']);
        self::assertTrue($res['ssl']['SNI_enabled']);
        self::assertEquals(7, $res['ssl']['verify_depth']);
        self::assertEquals('/some/path/file.crt', $res['ssl']['cafile']);
        if (version_compare(PHP_VERSION, '5.4.13') >= 0) {
            self::assertTrue($res['ssl']['disable_compression']);
        } else {
            self::assertFalse(isset($res['ssl']['disable_compression']));
        }
    }

    /**
     * Provides URLs to public downloads at BitBucket.
     *
     * @return string[][]
     */
    public static function provideBitbucketPublicDownloadUrls(): array
    {
        return [
            ['https://bitbucket.org/seldaek/composer-live-test-repo/downloads/composer-unit-test-download-me.txt', '1234'],
        ];
    }

    /**
     * Tests that a BitBucket public download is correctly retrieved.
     *
     * @dataProvider provideBitbucketPublicDownloadUrls
     * @param non-empty-string $url
     * @requires PHP 7.4.17
     */
    public function testBitBucketPublicDownload(string $url, string $contents): void
    {
        $io = $this
            ->getMockBuilder('Composer\IO\ConsoleIO')
            ->disableOriginalConstructor()
            ->getMock();

        $rfs = new RemoteFilesystem($io, $this->getConfigMock());
        $hostname = parse_url($url, PHP_URL_HOST);

        $result = $rfs->getContents($hostname, $url, false);

        self::assertEquals($contents, $result);
    }

    /**
     * Tests that a BitBucket public download is correctly retrieved when `bitbucket-oauth` is configured.
     *
     * @dataProvider provideBitbucketPublicDownloadUrls
     * @param non-empty-string $url
     * @requires PHP 7.4.17
     */
    public function testBitBucketPublicDownloadWithAuthConfigured(string $url, string $contents): void
    {
        /** @var MockObject|ConsoleIO $io */
        $io = $this
            ->getMockBuilder('Composer\IO\ConsoleIO')
            ->disableOriginalConstructor()
            ->getMock();

        $domains = [];
        $io
            ->method('hasAuthentication')
            ->willReturnCallback(static function ($arg) use (&$domains): bool {
                $domains[] = $arg;
                // first time is called with bitbucket.org, then it redirects to bbuseruploads.s3.amazonaws.com so next time we have no auth configured
                return $arg === 'bitbucket.org';
            });
        $io
        ->method('getAuthentication')
            ->with('bitbucket.org')
            ->willReturn([
                'username' => 'x-token-auth',
                // This token is fake, but it matches a valid token's pattern.
                'password' => '1A0yeK5Po3ZEeiiRiMWLivS0jirLdoGuaSGq9NvESFx1Fsdn493wUDXC8rz_1iKVRTl1GINHEUCsDxGh5lZ=',
            ]);

        $rfs = new RemoteFilesystem($io, $this->getConfigMock());
        $hostname = parse_url($url, PHP_URL_HOST);

        $result = $rfs->getContents($hostname, $url, false);

        self::assertEquals($contents, $result);
        self::assertEquals(['bitbucket.org', 'bbuseruploads.s3.amazonaws.com'], $domains);
    }

    /**
     * @param mixed[] $args
     * @param mixed[] $options
     *
     * @return mixed[]
     */
    private function callGetOptionsForUrl(IOInterface $io, array $args = [], array $options = [], string $fileUrl = ''): array
    {
        $fs = new RemoteFilesystem($io, $this->getConfigMock(), $options);
        $ref = new ReflectionMethod($fs, 'getOptionsForUrl');
        $prop = new ReflectionProperty($fs, 'fileUrl');
        $ref->setAccessible(true);
        $prop->setAccessible(true);

        $prop->setValue($fs, $fileUrl);

        return $ref->invokeArgs($fs, $args);
    }

    /**
     * @return MockObject|Config
     */
    private function getConfigMock()
    {
        $config = $this->getMockBuilder('Composer\Config')->getMock();
        $config
            ->method('get')
            ->willReturnCallback(static function ($key) {
                if ($key === 'github-domains' || $key === 'gitlab-domains') {
                    return [];
                }

                return null;
            });

        return $config;
    }

    private function callCallbackGet(RemoteFilesystem $fs, int $notificationCode, int $severity, string $message, int $messageCode, int $bytesTransferred, int $bytesMax): void
    {
        $ref = new ReflectionMethod($fs, 'callbackGet');
        $ref->setAccessible(true);
        $ref->invoke($fs, $notificationCode, $severity, $message, $messageCode, $bytesTransferred, $bytesMax);
    }

    /**
     * @param object|string $object
     * @param mixed         $value
     */
    private function setAttribute($object, string $attribute, $value): void
    {
        $attr = new ReflectionProperty($object, $attribute);
        $attr->setAccessible(true);
        $attr->setValue($object, $value);
    }

    /**
     * @param mixed         $value
     * @param object|string $object
     */
    private function assertAttributeEqualsCustom($value, string $attribute, $object): void
    {
        $attr = new ReflectionProperty($object, $attribute);
        $attr->setAccessible(true);
        self::assertSame($value, $attr->getValue($object));
    }

    /**
     * @return MockObject|IOInterface
     */
    private function getIOInterfaceMock()
    {
        return $this->getMockBuilder('Composer\IO\IOInterface')->getMock();
    }

    /**
     * @param string[] $mockedMethods
     *
     * @return RemoteFilesystem|MockObject
     */
    private function getRemoteFilesystemWithMockedMethods(array $mockedMethods, ?AuthHelper $authHelper = null)
    {
        return $this->getMockBuilder('Composer\Util\RemoteFilesystem')
            ->setConstructorArgs([
                $this->getIOInterfaceMock(),
                $this->getConfigMock(),
                [],
                false,
                $authHelper,
            ])
            ->onlyMethods($mockedMethods)
            ->getMock();
    }

    /**
     * @param string[] $mockedMethods
     *
     * @return AuthHelper|MockObject
     */
    private function getAuthHelperWithMockedMethods(array $mockedMethods)
    {
        return $this->getMockBuilder('Composer\Util\AuthHelper')
            ->setConstructorArgs([
                $this->getIOInterfaceMock(),
                $this->getConfigMock(),
            ])
            ->onlyMethods($mockedMethods)
            ->getMock();
    }
}
