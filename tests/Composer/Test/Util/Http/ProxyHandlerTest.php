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

use Composer\Util\Http\ProxyHandler;
use Composer\Test\TestCase;

/**
 * @phpstan-import-type contextOptions from \Composer\Util\Http\ProxyItem
 */
class ProxyHandlerTest extends TestCase
{
    // isTransitional can be removed after the transition period

    /** @var bool */
    private $isTransitional = true;

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
        ProxyHandler::reset();
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
        ProxyHandler::reset();
    }

    public function testInstantiation(): void
    {
        $originalInstance = ProxyHandler::getInstance();
        $sameInstance = ProxyHandler::getInstance();
        self::assertTrue($originalInstance === $sameInstance);

        ProxyHandler::reset();
        $newInstance = ProxyHandler::getInstance();
        self::assertFalse($sameInstance === $newInstance);
    }

    public function testGetProxyForRequestThrowsOnBadProxyUrl(): void
    {
        $_SERVER['http_proxy'] = 'localhost';
        $proxyHandler = ProxyHandler::getInstance();

        self::expectException('Composer\Downloader\TransportException');
        $proxyHandler->getProxyForRequest('http://example.com');
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
        $proxyHandler = ProxyHandler::getInstance();

        $proxy = $proxyHandler->getProxyForRequest($url);
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
        $proxyHandler = ProxyHandler::getInstance();

        $proxy = $proxyHandler->getProxyForRequest('http://repo.org');
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
        $proxyHandler = ProxyHandler::getInstance();

        $proxy = $proxyHandler->getProxyForRequest('http://repo.org');
        self::assertEquals('', $proxy->getStatus());
    }

    public function testNoHttpsProxyDoesNotUseHttpProxy(): void
    {
        $_SERVER['http_proxy'] = 'http://proxy.com:80';

        // This can be removed after the transition period.
        // An empty https_proxy value prevents using any http_proxy
        if ($this->isTransitional) {
            $_SERVER['https_proxy'] = '';
        }

        $proxyHandler = ProxyHandler::getInstance();
        $proxy = $proxyHandler->getProxyForRequest('https://repo.org');
        self::assertEquals('', $proxy->getStatus());
    }

    /**
     * This test can be removed after the transition period
     */
    public function testTransitional(): void
    {
        $_SERVER['http_proxy'] = 'http://proxy.com:80';
        $proxyHandler = ProxyHandler::getInstance();

        $proxy = $proxyHandler->getProxyForRequest('https://repo.org');
        self::assertSame('http://proxy.com:80', $proxy->getStatus());
        self::assertTrue($proxyHandler->needsTransitionWarning());
    }

    /**
     * @dataProvider dataRequest
     *
     * @param array<string, string> $server
     * @param non-empty-string      $url
     * @param ?contextOptions       $options
     */
    public function testGetProxyForRequest(array $server, string $url, ?array $options, bool $secure, string $info): void
    {
        $_SERVER = array_merge($_SERVER, $server);
        $proxyHandler = ProxyHandler::getInstance();

        $proxy = $proxyHandler->getProxyForRequest($url);
        self::assertSame($options, $proxy->getContextOptions());
        self::assertSame($secure, $proxy->isSecure());
        self::assertSame($info, $proxy->getStatus());
    }

    /**
     * @return list<array{0: array<string, string>, 1: string, 2: ?contextOptions, 3: bool, 4: string}>
     */
    public static function dataRequest(): array
    {
        $server = [
            'http_proxy' => 'http://user:p%40ss@proxy.com',
            'https_proxy' => 'https://proxy.com:443',
            'no_proxy' => 'other.repo.org',
        ];

        // server, url, options, secure, info
        return [
            [[], 'http://repo.org', null, false, ''],
            [$server, 'http://repo.org',
                ['http' => [
                    'proxy' => 'tcp://proxy.com:80',
                    'header' => 'Proxy-Authorization: Basic dXNlcjpwQHNz',
                    'request_fulluri' => true,
                ]],
                false,
                'http://***:***@proxy.com:80',
            ],
            [$server, 'https://repo.org',
                ['http' => [
                    'proxy' => 'ssl://proxy.com:443',
                ]],
                true,
                'https://proxy.com:443',
            ],
            [$server, 'https://other.repo.org', null, false, 'excluded by no_proxy'],
        ];
    }

    /**
     * @dataProvider dataCurlRequest
     *
     * @param array<string, string> $server
     * @param non-empty-string      $url
     */
    public function testGetProxyForCurlRequest(array $server, string $url, ?string $proxyUrl, ?string $auth): void
    {
        $_SERVER = array_merge($_SERVER, $server);
        $proxyHandler = ProxyHandler::getInstance();

        $proxy = $proxyHandler->getProxyForRequest($url);
        $reflectionClass = new \ReflectionClass($proxy);

        $property = $reflectionClass->getProperty('url');
        $property->setAccessible(true);
        self::assertSame($proxyUrl, $property->getValue($proxy));

        $property = $reflectionClass->getProperty('auth');
        $property->setAccessible(true);
        self::assertSame($auth, $property->getValue($proxy));
    }

    /**
     * @return list<array{0: array<string, string>, 1: string, 2: ?string, 3: ?string}>
     */
    public static function dataCurlRequest(): array
    {
        $server = [
            'http_proxy' => 'http://user:p%40ss@proxy.com',
            'https_proxy' => 'https://proxy.com:443',
            'no_proxy' => 'other.repo.org',
        ];

        // server, url, prxoyUrl, auth
        return [
            [[], 'http://repo.org', null, null],
            [$server, 'http://repo.org', 'http://proxy.com:80', 'user:p%40ss'],
            [$server, 'https://repo.org', 'https://proxy.com:443', null],
            [$server, 'https://other.repo.org', null, null],
        ];
    }
}
