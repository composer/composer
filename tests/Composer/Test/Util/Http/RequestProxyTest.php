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

use Composer\Util\Http\RequestProxy;
use Composer\Test\TestCase;

class RequestProxyTest extends TestCase
{
    public function testFactoryNone(): void
    {
        $proxy = RequestProxy::none();

        $options = extension_loaded('curl') ? [CURLOPT_PROXY => ''] : [];
        self::assertSame($options, $proxy->getCurlOptions([]));
        self::assertNull($proxy->getContextOptions());
        self::assertSame('', $proxy->getStatus());
    }

    public function testFactoryNoProxy(): void
    {
        $proxy = RequestProxy::noProxy();

        $options = extension_loaded('curl') ? [CURLOPT_PROXY => ''] : [];
        self::assertSame($options, $proxy->getCurlOptions([]));
        self::assertNull($proxy->getContextOptions());
        self::assertSame('excluded by no_proxy', $proxy->getStatus());
    }

    /**
     * @dataProvider dataSecure
     *
     * @param ?non-empty-string $url
     */
    public function testIsSecure(?string $url, bool $expected): void
    {
        $proxy = new RequestProxy($url, null, null, null);
        self::assertSame($expected, $proxy->isSecure());
    }

    /**
     * @return array<string, array{0: ?non-empty-string, 1: bool}>
     */
    public static function dataSecure(): array
    {
        // url, expected
        return [
            'basic' => ['http://proxy.com:80', false],
            'secure' => ['https://proxy.com:443', true],
            'none' => [null, false],
        ];
    }

    public function testGetStatusThrowsOnBadFormatSpecifier(): void
    {
        $proxy = new RequestProxy('http://proxy.com:80', null, null, 'http://proxy.com:80');
        self::expectException('InvalidArgumentException');
        $proxy->getStatus('using proxy');
    }

    /**
     * @dataProvider dataStatus
     *
     * @param ?non-empty-string $url
     */
    public function testGetStatus(?string $url, ?string $format, string $expected): void
    {
        $proxy = new RequestProxy($url, null, null, $url);

        if ($format === null) {
            // try with and without optional param
            self::assertSame($expected, $proxy->getStatus());
            self::assertSame($expected, $proxy->getStatus($format));
        } else {
            self::assertSame($expected, $proxy->getStatus($format));
        }
    }

    /**
     * @return array<string, array{0: ?non-empty-string, 1: ?string, 2: string}>
     */
    public static function dataStatus(): array
    {
        $format = 'proxy (%s)';

        // url, format, expected
        return [
            'no-proxy' => [null, $format, ''],
            'null-format' => ['http://proxy.com:80', null, 'http://proxy.com:80'],
            'with-format' => ['http://proxy.com:80', $format, 'proxy (http://proxy.com:80)'],
        ];
    }

    /**
     * This test avoids HTTPS proxies so that it can be run on PHP < 7.3
     *
     * @requires extension curl
     * @dataProvider dataCurlOptions
     *
     * @param ?non-empty-string $url
     * @param ?non-empty-string $auth
     * @param array<int, string|int> $expected
     */
    public function testGetCurlOptions(?string $url, ?string $auth, array $expected): void
    {
        $proxy = new RequestProxy($url, $auth, null, null);
        self::assertSame($expected, $proxy->getCurlOptions([]));
    }

    /**
     * @return list<array{0: ?string, 1: ?string, 2: array<int, string|int>}>
     */
    public static function dataCurlOptions(): array
    {
        // url, auth, expected
        return [
            [null, null, [CURLOPT_PROXY => '']],
            ['http://proxy.com:80', null,
                [
                    CURLOPT_PROXY => 'http://proxy.com:80',
                    CURLOPT_NOPROXY => '',
                ],
            ],
            ['http://proxy.com:80', 'user:p%40ss',
                [
                    CURLOPT_PROXY => 'http://proxy.com:80',
                    CURLOPT_NOPROXY => '',
                    CURLOPT_PROXYAUTH => CURLAUTH_BASIC,
                    CURLOPT_PROXYUSERPWD => 'user:p%40ss',
                ],
            ],
        ];
    }

    /**
     * @requires PHP >= 7.3.0
     * @requires extension curl >= 7.52.0
     * @dataProvider dataCurlSSLOptions
     *
     * @param non-empty-string $url
     * @param ?non-empty-string $auth
     * @param array<string, string> $sslOptions
     * @param array<int, string|int> $expected
     */
    public function testGetCurlOptionsWithSSL(string $url, ?string $auth, array $sslOptions, array $expected): void
    {
        $proxy = new RequestProxy($url, $auth, null, null);
        self::assertSame($expected, $proxy->getCurlOptions($sslOptions));
    }

    /**
     * @return list<array{0: string, 1: ?string, 2: array<string, string>, 3: array<int, string|int>}>
     */
    public static function dataCurlSSLOptions(): array
    {
        // for PHPStan on PHP < 7.3
        $caInfo = 10246; // CURLOPT_PROXY_CAINFO
        $caPath = 10247; // CURLOPT_PROXY_CAPATH

        // url, auth, sslOptions, expected
        return [
            ['https://proxy.com:443', null, ['cafile' => '/certs/bundle.pem'],
                [
                    CURLOPT_PROXY => 'https://proxy.com:443',
                    CURLOPT_NOPROXY => '',
                    $caInfo => '/certs/bundle.pem',
                ],
            ],
            ['https://proxy.com:443', 'user:p%40ss', ['capath' => '/certs'],
                [
                    CURLOPT_PROXY => 'https://proxy.com:443',
                    CURLOPT_NOPROXY => '',
                    CURLOPT_PROXYAUTH => CURLAUTH_BASIC,
                    CURLOPT_PROXYUSERPWD => 'user:p%40ss',
                    $caPath => '/certs',
                ],
            ],
        ];
    }
}
