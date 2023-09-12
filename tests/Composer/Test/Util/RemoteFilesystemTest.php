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
    public function testGetOptionsForUrl()
    {
        $io = $this->getIOInterfaceMock();
        $io
            ->expects($this->once())
            ->method('hasAuthentication')
            ->willReturn(false)
        ;

        $res = $this->callGetOptionsForUrl($io, array('http://example.org', array()));
        $this->assertTrue(isset($res['http']['header']) && is_array($res['http']['header']), 'getOptions must return an array with headers');
    }

    public function testGetOptionsForUrlWithAuthorization()
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
            ->willReturn(array('username' => 'login', 'password' => 'password'))
        ;

        $options = $this->callGetOptionsForUrl($io, array('http://example.org', array()));

        $found = false;
        foreach ($options['http']['header'] as $header) {
            if (0 === strpos($header, 'Authorization: Basic')) {
                $found = true;
            }
        }
        $this->assertTrue($found, 'getOptions must have an Authorization header');
    }

    public function testGetOptionsForUrlWithStreamOptions()
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
            ->willReturn(array('username' => null, 'password' => null))
        ;

        $streamOptions = array('ssl' => array(
            'allow_self_signed' => true,
        ));

        $res = $this->callGetOptionsForUrl($io, array('https://example.org', array()), $streamOptions);
        $this->assertTrue(
            isset($res['ssl'], $res['ssl']['allow_self_signed']) && true === $res['ssl']['allow_self_signed'],
            'getOptions must return an array with a allow_self_signed set to true'
        );
    }

    public function testGetOptionsForUrlWithCallOptionsKeepsHeader()
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
            ->willReturn(array('username' => null, 'password' => null))
        ;

        $streamOptions = array('http' => array(
            'header' => 'Foo: bar',
        ));

        $res = $this->callGetOptionsForUrl($io, array('https://example.org', $streamOptions));
        $this->assertTrue(isset($res['http']['header']), 'getOptions must return an array with a http.header key');

        $found = false;
        foreach ($res['http']['header'] as $header) {
            if ($header === 'Foo: bar') {
                $found = true;
            }
        }

        $this->assertTrue($found, 'getOptions must have a Foo: bar header');
        $this->assertGreaterThan(1, count($res['http']['header']));
    }

    public function testCallbackGetFileSize()
    {
        $fs = new RemoteFilesystem($this->getIOInterfaceMock(), $this->getConfigMock());
        $this->callCallbackGet($fs, STREAM_NOTIFY_FILE_SIZE_IS, 0, '', 0, 0, 20);
        $this->assertAttributeEqualsCustom(20, 'bytesMax', $fs);
    }

    public function testCallbackGetNotifyProgress()
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
        $this->assertAttributeEqualsCustom(50, 'lastProgress', $fs);
    }

    public function testCallbackGetPassesThrough404()
    {
        $fs = new RemoteFilesystem($this->getIOInterfaceMock(), $this->getConfigMock());

        $this->callCallbackGet($fs, STREAM_NOTIFY_FAILURE, 0, 'HTTP/1.1 404 Not Found', 404, 0, 0);
        $this->assertTrue(true, 'callbackGet must pass through 404');
    }

    public function testGetContents()
    {
        $fs = new RemoteFilesystem($this->getIOInterfaceMock(), $this->getConfigMock());

        $this->assertStringContainsString('testGetContents', $fs->getContents('http://example.org', 'file://'.__FILE__));
    }

    public function testCopy()
    {
        $fs = new RemoteFilesystem($this->getIOInterfaceMock(), $this->getConfigMock());

        $file = tempnam(sys_get_temp_dir(), 'c');
        $this->assertTrue($fs->copy('http://example.org', 'file://'.__FILE__, $file));
        $this->assertFileExists($file);
        $this->assertStringContainsString('testCopy', file_get_contents($file));
        unlink($file);
    }

    public function testCopyWithNoRetryOnFailure()
    {
        $this->setExpectedException('Composer\Downloader\TransportException');
        $fs = $this->getRemoteFilesystemWithMockedMethods(array('getRemoteContents'));

        $fs->expects($this->once())->method('getRemoteContents')
            ->willReturnCallback(function ($originUrl, $fileUrl, $ctx, &$http_response_header) {
                $http_response_header = array('http/1.1 401 unauthorized');

                return '';
            });

        $file = tempnam(sys_get_temp_dir(), 'z');
        unlink($file);

        $fs->copy(
            'http://example.org',
            'file://' . __FILE__,
            $file,
            true,
            array('retry-auth-failure' => false)
        );
    }

    public function testCopyWithSuccessOnRetry()
    {
        $authHelper = $this->getAuthHelperWithMockedMethods(array('promptAuthIfNeeded'));
        $fs = $this->getRemoteFilesystemWithMockedMethods(array('getRemoteContents'), $authHelper);

        $authHelper->expects($this->once())
            ->method('promptAuthIfNeeded')
            ->willReturn(array(
                'storeAuth' => true,
                'retry' => true,
            ));

        $fs->expects($this->at(0))
            ->method('getRemoteContents')
            ->willReturnCallback(function ($originUrl, $fileUrl, $ctx, &$http_response_header) {
                $http_response_header = array('http/1.1 401 unauthorized');

                return '';
            });

        $fs->expects($this->at(1))
            ->method('getRemoteContents')
            ->willReturnCallback(function ($originUrl, $fileUrl, $ctx, &$http_response_header) {
                $http_response_header = array('http/1.1 200 OK');

                return '<?php $copied = "Copied"; ';
            });

        $file = tempnam(sys_get_temp_dir(), 'z');

        $copyResult = $fs->copy(
            'http://example.org',
            'file://' . __FILE__,
            $file,
            true,
            array('retry-auth-failure' => true)
        );

        $this->assertTrue($copyResult);
        $this->assertFileExists($file);
        $this->assertStringContainsString('Copied', file_get_contents($file));

        unlink($file);
    }

    /**
     * @group TLS
     */
    public function testGetOptionsForUrlCreatesSecureTlsDefaults()
    {
        $io = $this->getIOInterfaceMock();

        $res = $this->callGetOptionsForUrl($io, array('example.org', array('ssl' => array('cafile' => '/some/path/file.crt'))), array(), 'http://www.example.org');

        $this->assertTrue(isset($res['ssl']['ciphers']));
        $this->assertMatchesRegularExpression('|!aNULL:!eNULL:!EXPORT:!DES:!RC4:!MD5:!PSK:!aECDH:!EDH-DSS-DES-CBC3-SHA:!EDH-RSA-DES-CBC3-SHA:!KRB5-DES-CBC3-SHA|', $res['ssl']['ciphers']);
        $this->assertTrue($res['ssl']['verify_peer']);
        $this->assertTrue($res['ssl']['SNI_enabled']);
        $this->assertEquals(7, $res['ssl']['verify_depth']);
        if (PHP_VERSION_ID < 50600) {
            $this->assertEquals('www.example.org', $res['ssl']['CN_match']);
            $this->assertEquals('www.example.org', $res['ssl']['SNI_server_name']);
        }
        $this->assertEquals('/some/path/file.crt', $res['ssl']['cafile']);
        if (version_compare(PHP_VERSION, '5.4.13') >= 0) {
            $this->assertTrue($res['ssl']['disable_compression']);
        } else {
            $this->assertFalse(isset($res['ssl']['disable_compression']));
        }
    }

    /**
     * Provides URLs to public downloads at BitBucket.
     *
     * @return string[][]
     */
    public function provideBitbucketPublicDownloadUrls()
    {
        return array(
            array('https://bitbucket.org/seldaek/composer-live-test-repo/downloads/composer-unit-test-download-me.txt', '1234'),
        );
    }

    /**
     * Tests that a BitBucket public download is correctly retrieved.
     *
     * @param string $url
     * @param string $contents
     * @dataProvider provideBitbucketPublicDownloadUrls
     * @requires PHP 7.4.17
     */
    public function testBitBucketPublicDownload($url, $contents)
    {
        /** @var ConsoleIO $io */
        $io = $this
            ->getMockBuilder('Composer\IO\ConsoleIO')
            ->disableOriginalConstructor()
            ->getMock();

        $rfs = new RemoteFilesystem($io, $this->getConfigMock());
        $hostname = parse_url($url, PHP_URL_HOST);

        $result = $rfs->getContents($hostname, $url, false);

        $this->assertEquals($contents, $result);
    }

    /**
     * Tests that a BitBucket public download is correctly retrieved when `bitbucket-oauth` is configured.
     *
     * @param string $url
     * @param string $contents
     * @dataProvider provideBitbucketPublicDownloadUrls
     * @requires PHP 7.4.17
     */
    public function testBitBucketPublicDownloadWithAuthConfigured($url, $contents)
    {
        /** @var MockObject|ConsoleIO $io */
        $io = $this
            ->getMockBuilder('Composer\IO\ConsoleIO')
            ->disableOriginalConstructor()
            ->getMock();

        $domains = array();
        $io
            ->method('hasAuthentication')
            ->willReturnCallback(function ($arg) use (&$domains) {
                $domains[] = $arg;
                // first time is called with bitbucket.org, then it redirects to bbuseruploads.s3.amazonaws.com so next time we have no auth configured
                return $arg === 'bitbucket.org';
            });
        $io
            ->expects($this->at(1))
            ->method('getAuthentication')
            ->with('bitbucket.org')
            ->willReturn(array(
                'username' => 'x-token-auth',
                // This token is fake, but it matches a valid token's pattern.
                'password' => '1A0yeK5Po3ZEeiiRiMWLivS0jirLdoGuaSGq9NvESFx1Fsdn493wUDXC8rz_1iKVRTl1GINHEUCsDxGh5lZ=',
            ));

        $rfs = new RemoteFilesystem($io, $this->getConfigMock());
        $hostname = parse_url($url, PHP_URL_HOST);

        $result = $rfs->getContents($hostname, $url, false);

        $this->assertEquals($contents, $result);
        $this->assertEquals(array('bitbucket.org', 'bbuseruploads.s3.amazonaws.com'), $domains);
    }

    /**
     * @param mixed[] $args
     * @param mixed[] $options
     * @param string  $fileUrl
     *
     * @return mixed[]
     */
    private function callGetOptionsForUrl(IOInterface $io, array $args = array(), array $options = array(), $fileUrl = '')
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
            ->willReturnCallback(function ($key) {
                if ($key === 'github-domains' || $key === 'gitlab-domains') {
                    return array();
                }

                return null;
            });

        return $config;
    }

    /**
     * @param int    $notificationCode
     * @param int    $severity
     * @param string $message
     * @param int    $messageCode
     * @param int    $bytesTransferred
     * @param int    $bytesMax
     *
     * @return void
     */
    private function callCallbackGet(RemoteFilesystem $fs, $notificationCode, $severity, $message, $messageCode, $bytesTransferred, $bytesMax)
    {
        $ref = new ReflectionMethod($fs, 'callbackGet');
        $ref->setAccessible(true);
        $ref->invoke($fs, $notificationCode, $severity, $message, $messageCode, $bytesTransferred, $bytesMax);
    }

    /**
     * @param object|string $object
     * @param string        $attribute
     * @param mixed         $value
     *
     * @return void
     */
    private function setAttribute($object, $attribute, $value)
    {
        $attr = new ReflectionProperty($object, $attribute);
        $attr->setAccessible(true);
        $attr->setValue($object, $value);
    }

    /**
     * @param mixed         $value
     * @param string        $attribute
     * @param object|string $object
     *
     * @return void
     */
    private function assertAttributeEqualsCustom($value, $attribute, $object)
    {
        $attr = new ReflectionProperty($object, $attribute);
        $attr->setAccessible(true);
        $this->assertSame($value, $attr->getValue($object));
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
    private function getRemoteFilesystemWithMockedMethods(array $mockedMethods, AuthHelper $authHelper = null)
    {
        return $this->getMockBuilder('Composer\Util\RemoteFilesystem')
            ->setConstructorArgs(array(
                $this->getIOInterfaceMock(),
                $this->getConfigMock(),
                array(),
                false,
                $authHelper,
            ))
            ->setMethods($mockedMethods)
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
            ->setConstructorArgs(array(
                $this->getIOInterfaceMock(),
                $this->getConfigMock(),
            ))
            ->setMethods($mockedMethods)
            ->getMock();
    }
}
