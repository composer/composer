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

use Composer\Util\Http\ProxyHelper;
use Composer\Test\TestCase;

class ProxyHelperTest extends TestCase
{
    protected function setUp(): void
    {
        unset(
            $_SERVER['HTTP_PROXY'],
            $_SERVER['http_proxy'],
            $_SERVER['HTTPS_PROXY'],
            $_SERVER['https_proxy'],
            $_SERVER['NO_PROXY'],
            $_SERVER['no_proxy'],
            $_SERVER['CGI_HTTP_PROXY']
        );
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        unset(
            $_SERVER['HTTP_PROXY'],
            $_SERVER['http_proxy'],
            $_SERVER['HTTPS_PROXY'],
            $_SERVER['https_proxy'],
            $_SERVER['NO_PROXY'],
            $_SERVER['no_proxy'],
            $_SERVER['CGI_HTTP_PROXY']
        );
    }

    /**
     * @dataProvider dataMalformed
     */
    public function testThrowsOnMalformedUrl(string $url): void
    {
        $_SERVER['http_proxy'] = $url;

        self::expectException('RuntimeException');
        ProxyHelper::getProxyData();
    }

    public function dataMalformed(): array
    {
        return [
            'no-host' => ['localhost'],
            'no-port' => ['scheme://localhost'],
        ];
    }

    /**
     * @dataProvider dataFormatting
     */
    public function testUrlFormatting(string $url, string $expected): void
    {
        $_SERVER['http_proxy'] = $url;

        [$httpProxy, $httpsProxy, $noProxy] = ProxyHelper::getProxyData();
        $this->assertSame($expected, $httpProxy);
    }

    public function dataFormatting(): array
    {
        // url, expected
        return [
            'lowercases-scheme' => ['HTTP://proxy.com:8888', 'http://proxy.com:8888'],
            'adds-http-port' => ['http://proxy.com', 'http://proxy.com:80'],
            'adds-https-port' => ['https://proxy.com', 'https://proxy.com:443'],
        ];
    }

    /**
     * @dataProvider dataCaseOverrides
     *
     * @param array<string, mixed> $server
     */
    public function testLowercaseOverridesUppercase(array $server, string $expected, int $index): void
    {
        $_SERVER = array_merge($_SERVER, $server);

        $list = ProxyHelper::getProxyData();
        $this->assertSame($expected, $list[$index]);
    }

    public function dataCaseOverrides(): array
    {
        // server, expected, list index
        return [
            [['HTTP_PROXY' => 'http://upper.com', 'http_proxy' => 'http://lower.com'], 'http://lower.com:80', 0],
            [['HTTPS_PROXY' => 'http://upper.com', 'https_proxy' => 'http://lower.com'], 'http://lower.com:80', 1],
            [['NO_PROXY' => 'upper.com', 'no_proxy' => 'lower.com'], 'lower.com', 2],
        ];
    }

    /**
     * @dataProvider dataCGIOverrides
     *
     * @param array<string, mixed> $server
     */
    public function testCGIUpperCaseOverridesHttp(array $server, string $expected, int $index): void
    {
        $_SERVER = array_merge($_SERVER, $server);

        $list = ProxyHelper::getProxyData();
        $this->assertSame($expected, $list[$index]);
    }

    public function dataCGIOverrides(): array
    {
        // server, expected, list index
        return [
            [['http_proxy' => 'http://http.com', 'CGI_HTTP_PROXY' => 'http://cgi.com'], 'http://cgi.com:80', 0],
            [['http_proxy' => 'http://http.com', 'cgi_http_proxy' => 'http://cgi.com'], 'http://http.com:80', 0],
        ];
    }

    public function testNoHttpsProxyUsesHttpProxy(): void
    {
        $_SERVER['http_proxy'] = 'http://http.com';

        [$httpProxy, $httpsProxy, $noProxy] = ProxyHelper::getProxyData();
        $this->assertSame('http://http.com:80', $httpsProxy);
    }

    public function testNoHttpProxyDoesNotUseHttpsProxy(): void
    {
        $_SERVER['https_proxy'] = 'http://https.com';

        [$httpProxy, $httpsProxy, $noProxy] = ProxyHelper::getProxyData();
        $this->assertSame(null, $httpProxy);
    }

    /**
     * @dataProvider dataContextOptions
     *
     * @param array<string, string> $expected
     *
     * @phpstan-param array{http: array{proxy: string, header?: string}} $expected
     */
    public function testGetContextOptions(string $url, array $expected): void
    {
        $this->assertEquals($expected, ProxyHelper::getContextOptions($url));
    }

    public function dataContextOptions(): array
    {
        // url, expected
        return [
            ['http://proxy.com', ['http' => [
                'proxy' => 'tcp://proxy.com:80',
            ]]],
            ['https://proxy.com', ['http' => [
                'proxy' => 'ssl://proxy.com:443',
            ]]],
            ['http://user:p%40ss@proxy.com', ['http' => [
                'proxy' => 'tcp://proxy.com:80',
                'header' => 'Proxy-Authorization: Basic dXNlcjpwQHNz',
            ]]],
        ];
    }

    /**
     * @dataProvider dataRequestFullUri
     *
     * @param mixed[] $expected
     */
    public function testSetRequestFullUri(string $requestUrl, array $expected): void
    {
        $options = [];
        ProxyHelper::setRequestFullUri($requestUrl, $options);

        $this->assertEquals($expected, $options);
    }

    public function dataRequestFullUri(): array
    {
        $options = ['http' => ['request_fulluri' => true]];

        // $requestUrl, expected
        return [
            'http' => ['http://repo.org', $options],
            'https' => ['https://repo.org', []],
            'no-scheme' => ['repo.org', []],
        ];
    }
}
