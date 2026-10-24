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

namespace Composer\Test\Util\Http;

use Composer\Config;
use Composer\Downloader\TransportException;
use Composer\Exception\SecurityException;
use Composer\IO\NullIO;
use Composer\Test\TestCase;
use Composer\Util\Filesystem;
use Composer\Util\Http\CurlDownloader;
use Composer\Util\HttpDownloader;

class CurlDownloaderEncodingTest extends TestCase
{
    /** @var resource|null */
    private static $server;
    /** @var string */
    private static $serverDir;
    /** @var string */
    private static $baseUrl;

    public static function setUpBeforeClass(): void
    {
        if (!extension_loaded('curl') || !extension_loaded('zlib')) {
            return;
        }

        self::$serverDir = self::getUniqueTmpDirectory();
        file_put_contents(self::$serverDir.'/router.php', <<<'PHP'
<?php
// Content-Encoding is sent no matter what the client asked for, like a hostile server would
$encoding = (string) ($_GET['encoding'] ?? '');
header('Content-Encoding: '.$encoding);
echo $encoding === 'gzip' ? gzencode('decoded body') : "\xff\xff\xff\xff not a valid compressed stream";
PHP
        );

        $socket = stream_socket_server('tcp://127.0.0.1:0');
        if (false === $socket) {
            return;
        }
        $address = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        $server = proc_open(
            escapeshellarg(PHP_BINARY).' -S '.escapeshellarg($address).' '.escapeshellarg(self::$serverDir.'/router.php'),
            [0 => ['pipe', 'r'], 1 => ['file', self::$serverDir.'/server.log', 'a'], 2 => ['file', self::$serverDir.'/server.log', 'a']],
            $pipes
        );
        if (false === $server) {
            return;
        }
        self::$server = $server;
        self::$baseUrl = 'http://'.$address.'/router.php';

        for ($i = 0; $i < 100; $i++) {
            $connection = @fsockopen('127.0.0.1', (int) substr($address, (int) strrpos($address, ':') + 1));
            if (false !== $connection) {
                fclose($connection);

                return;
            }
            usleep(50000);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (null !== self::$server) {
            proc_terminate(self::$server);
            proc_close(self::$server);
            self::$server = null;
        }
        if (null !== self::$serverDir) {
            (new Filesystem())->removeDirectory(self::$serverDir);
        }
    }

    protected function setUp(): void
    {
        if (!extension_loaded('curl') || !extension_loaded('zlib')) {
            self::markTestSkipped('curl and zlib extensions are required');
        }
        if (null === self::$server) {
            self::markTestSkipped('Could not start the PHP built-in web server');
        }
    }

    public function testDisabledEncodingAbortsWithSecurityException(): void
    {
        self::expectException(SecurityException::class);
        self::expectExceptionMessage('uses the disabled "br" content-encoding');

        $this->createHttpDownloader()->get(self::$baseUrl.'?encoding=br', ['disabled_encodings' => ['br']]);
    }

    public function testDisabledEncodingIsMatchedCaseInsensitivelyWithinAList(): void
    {
        self::expectException(SecurityException::class);
        self::expectExceptionMessage('uses the disabled "br" content-encoding');

        $this->createHttpDownloader()->get(self::$baseUrl.'?encoding='.rawurlencode('gzip, BR'), ['disabled_encodings' => ['br']]);
    }

    public function testDisablingGzipAlsoRejectsXGzip(): void
    {
        self::expectException(SecurityException::class);
        self::expectExceptionMessage('uses the disabled "gzip" content-encoding');

        $this->createHttpDownloader()->get(self::$baseUrl.'?encoding=x-gzip', ['disabled_encodings' => ['GZIP']]);
    }

    public function testDisablingAllEncodingsStillRejectsThem(): void
    {
        self::expectException(SecurityException::class);

        $this->createHttpDownloader()->get(self::$baseUrl.'?encoding=gzip', ['disabled_encodings' => ['gzip', 'deflate', 'br', 'zstd']]);
    }

    public function testRequestOptionAddsToTheDefaultDisabledEncodings(): void
    {
        self::expectException(SecurityException::class);
        self::expectExceptionMessage('uses the disabled "br" content-encoding');

        $this->createHttpDownloader(['disabled_encodings' => ['br', 'zstd']])->get(self::$baseUrl.'?encoding=br', ['disabled_encodings' => ['gzip']]);
    }

    public function testEncodingWhichIsNotDisabledIsDecoded(): void
    {
        $response = $this->createHttpDownloader()->get(self::$baseUrl.'?encoding=gzip', ['disabled_encodings' => ['br']]);

        self::assertSame('decoded body', $response->getBody());
    }

    public function testWithoutTheOptionCurlDecodesTheBody(): void
    {
        $version = curl_version();
        if (false === $version || !\defined('CURL_VERSION_BROTLI') || ($version['features'] & CURL_VERSION_BROTLI) === 0) {
            self::markTestSkipped('libcurl has no brotli support');
        }

        // the invalid brotli stream reaching the decoder is what disabled_encodings prevents
        self::expectException(TransportException::class);
        self::expectExceptionMessage('curl error 61');

        $this->createHttpDownloader()->get(self::$baseUrl.'?encoding=br');
    }

    public function testInvalidOptionIsRejected(): void
    {
        self::expectException(\InvalidArgumentException::class);

        $this->createHttpDownloader()->get(self::$baseUrl.'?encoding=gzip', ['disabled_encodings' => 'br']);
    }

    public function testEmptyEncodingIsRejected(): void
    {
        self::expectException(\InvalidArgumentException::class);

        $this->createHttpDownloader()->get(self::$baseUrl.'?encoding='.rawurlencode('gzip,'), ['disabled_encodings' => [' ']]);
    }

    public function testAcceptEncodingLeavesOutDisabledEncodings(): void
    {
        $default = self::getAcceptEncoding([]);
        self::assertContains($default, ['', 'gzip']);

        $acceptEncoding = self::getAcceptEncoding(['br']);
        self::assertNotNull($acceptEncoding);
        self::assertNotContains('br', explode(', ', $acceptEncoding));
        self::assertContains('gzip', explode(', ', $acceptEncoding));

        self::assertNull(self::getAcceptEncoding(['gzip', 'deflate', 'br', 'zstd']));
    }

    /**
     * @param list<non-empty-string> $disabledEncodings
     */
    private static function getAcceptEncoding(array $disabledEncodings): ?string
    {
        $reflection = new \ReflectionMethod(CurlDownloader::class, 'getAcceptEncoding');
        if (\PHP_VERSION_ID < 80100) {
            $reflection->setAccessible(true);
        }

        $result = $reflection->invoke(null, $disabledEncodings);
        self::assertTrue(null === $result || is_string($result));

        return $result;
    }

    /**
     * @param mixed[] $options
     */
    private function createHttpDownloader(array $options = []): HttpDownloader
    {
        $config = new Config(false);
        $config->merge(['config' => ['secure-http' => false]]);

        return new HttpDownloader(new NullIO(), $config, $options);
    }
}
