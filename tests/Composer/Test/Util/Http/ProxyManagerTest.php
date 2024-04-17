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

use Composer\Util\Http\ProxyManager;
use Composer\Test\TestCase;

/**
 * @phpstan-import-type contextOptions from \Composer\Util\Http\RequestProxy
 */
class ProxyManagerTest extends TestCase
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
            $_SERVER['CGI_HTTP_PROXY'],
            $_SERVER['cgi_http_proxy']
        );
        ProxyManager::reset();
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
            $_SERVER['CGI_HTTP_PROXY'],
            $_SERVER['cgi_http_proxy']
        );
        ProxyManager::reset();
    }

    public function testInstantiation(): void
    {
        $originalInstance = ProxyManager::getInstance();
        $sameInstance = ProxyManager::getInstance();
        self::assertTrue($originalInstance === $sameInstance);

        ProxyManager::reset();
        $newInstance = ProxyManager::getInstance();
        self::assertFalse($sameInstance === $newInstance);
    }

    public function testGetProxyForRequestThrowsOnBadProxyUrl(): void
    {
        $_SERVER['http_proxy'] = 'localhost';
        $proxyManager = ProxyManager::getInstance();

        self::expectException('Composer\Downloader\TransportException');
        $proxyManager->getProxyForRequest('http://example.com');
    }

    /**
     * @dataProvider dataCaseOverrides
     *
     * @param array<string, string> $server
     * @param non-empty-string      $url
     */
    public function testLowercaseOverridesUppercase(array $server, string $url, string $expectedUrl): void
    {
        $_SERVER = array_merge($_SERVER, $server);
        $proxyManager = ProxyManager::getInstance();

        $proxy = $proxyManager->getProxyForRequest($url);
        self::assertSame($expectedUrl, $proxy->getStatus());
    }

    /**
     * @return list<array{0: array<string, string>, 1: string, 2: string}>
     */
    public static function dataCaseOverrides(): array
    {
        // server, url, expectedUrl
        return [
            [['HTTP_PROXY' => 'http://upper.com', 'http_proxy' => 'http://lower.com'], 'http://repo.org', 'http://lower.com:80'],
            [['CGI_HTTP_PROXY' => 'http://upper.com', 'cgi_http_proxy' => 'http://lower.com'], 'http://repo.org', 'http://lower.com:80'],
            [['HTTPS_PROXY' => 'http://upper.com', 'https_proxy' => 'http://lower.com'], 'https://repo.org', 'http://lower.com:80'],
        ];
    }

    /**
     * @dataProvider dataCGIProxy
     *
     * @param array<string, string> $server
     */
    public function testCGIProxyIsOnlyUsedWhenNoHttpProxy(array $server, string $expectedUrl): void
    {
        $_SERVER = array_merge($_SERVER, $server);
        $proxyManager = ProxyManager::getInstance();

        $proxy = $proxyManager->getProxyForRequest('http://repo.org');
        self::assertSame($expectedUrl, $proxy->getStatus());
    }

    /**
     * @return list<array{0: array<string, string>, 1: string}>
     */
    public static function dataCGIProxy(): array
    {
        // server, expectedUrl
        return [
            [['CGI_HTTP_PROXY' => 'http://cgi.com:80'], 'http://cgi.com:80'],
            [['http_proxy' => 'http://http.com:80', 'CGI_HTTP_PROXY' => 'http://cgi.com:80'], 'http://http.com:80'],
        ];
    }

    public function testNoHttpProxyDoesNotUseHttpsProxy(): void
    {
        $_SERVER['https_proxy'] = 'https://proxy.com:443';
        $proxyManager = ProxyManager::getInstance();

        $proxy = $proxyManager->getProxyForRequest('http://repo.org');
        self::assertSame('', $proxy->getStatus());
    }

    public function testNoHttpsProxyDoesNotUseHttpProxy(): void
    {
        $_SERVER['http_proxy'] = 'http://proxy.com:80';
        $proxyManager = ProxyManager::getInstance();

        $proxy = $proxyManager->getProxyForRequest('https://repo.org');
        self::assertSame('', $proxy->getStatus());
    }

    /**
     * @dataProvider dataRequest
     *
     * @param array<string, string> $server
     * @param non-empty-string      $url
     * @param ?contextOptions       $options
     */
    public function testGetProxyForRequest(array $server, string $url, ?array $options, string $status, bool $excluded): void
    {
        $_SERVER = array_merge($_SERVER, $server);
        $proxyManager = ProxyManager::getInstance();

        $proxy = $proxyManager->getProxyForRequest($url);
        self::assertSame($options, $proxy->getContextOptions());
        self::assertSame($status, $proxy->getStatus());
        self::assertSame($excluded, $proxy->isExcludedByNoProxy());
    }

    /**
     * Tests context options. curl options are tested in RequestProxyTest.php
     *
     * @return list<array{0: array<string, string>, 1: string, 2: ?contextOptions, 3: string, 4: bool}>
     */
    public static function dataRequest(): array
    {
        $server = [
            'http_proxy' => 'http://user:p%40ss@proxy.com',
            'https_proxy' => 'https://proxy.com:443',
            'no_proxy' => 'other.repo.org',
        ];

        // server, url, options, status, excluded
        return [
            [[], 'http://repo.org', null, '', false],
            [$server, 'http://repo.org',
                ['http' => [
                    'proxy' => 'tcp://proxy.com:80',
                    'header' => 'Proxy-Authorization: Basic dXNlcjpwQHNz',
                    'request_fulluri' => true,
                ]],
                'http://***:***@proxy.com:80',
                false,
            ],
            [$server, 'https://repo.org',
                ['http' => [
                    'proxy' => 'ssl://proxy.com:443',
                ]],
                'https://proxy.com:443',
                false,
            ],
            [$server, 'https://other.repo.org', null, 'excluded by no_proxy', true],
        ];
    }
}
