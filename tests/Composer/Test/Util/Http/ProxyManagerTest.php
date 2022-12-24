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
            $_SERVER['CGI_HTTP_PROXY']
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
            $_SERVER['CGI_HTTP_PROXY']
        );
        ProxyManager::reset();
    }

    public function testInstantiation(): void
    {
        $originalInstance = ProxyManager::getInstance();
        $this->assertInstanceOf('Composer\Util\Http\ProxyManager', $originalInstance);

        $sameInstance = ProxyManager::getInstance();
        $this->assertTrue($originalInstance === $sameInstance);

        ProxyManager::reset();
        $newInstance = ProxyManager::getInstance();
        $this->assertFalse($sameInstance === $newInstance);
    }

    public function testGetProxyForRequestThrowsOnBadProxyUrl(): void
    {
        $_SERVER['http_proxy'] = 'localhost';
        $proxyManager = ProxyManager::getInstance();
        self::expectException('Composer\Downloader\TransportException');
        $proxyManager->getProxyForRequest('http://example.com');
    }

    /**
     * @dataProvider dataRequest
     *
     * @param array<string, mixed> $server
     * @param mixed[]              $expectedOptions
     * @param non-empty-string     $url
     */
    public function testGetProxyForRequest(array $server, string $url, string $expectedUrl, array $expectedOptions, bool $expectedSecure, string $expectedMessage): void
    {
        $_SERVER = array_merge($_SERVER, $server);
        $proxyManager = ProxyManager::getInstance();

        $proxy = $proxyManager->getProxyForRequest($url);
        $this->assertInstanceOf('Composer\Util\Http\RequestProxy', $proxy);

        $this->assertSame($expectedUrl, $proxy->getUrl());
        $this->assertSame($expectedOptions, $proxy->getContextOptions());
        $this->assertSame($expectedSecure, $proxy->isSecure());

        $message = $proxy->getFormattedUrl();

        if ($expectedMessage) {
            $condition = stripos($message, $expectedMessage) !== false;
        } else {
            $condition = $expectedMessage === $message;
        }

        $this->assertTrue($condition, 'lastProxy check');
    }

    public static function dataRequest(): array
    {
        $server = [
            'http_proxy' => 'http://user:p%40ss@proxy.com',
            'https_proxy' => 'https://proxy.com:443',
            'no_proxy' => 'other.repo.org',
        ];

        // server, url, expectedUrl, expectedOptions, expectedSecure, expectedMessage
        return [
            [[], 'http://repo.org', '', [], false, ''],
            [$server, 'http://repo.org', 'http://user:p%40ss@proxy.com:80',
                ['http' => [
                    'proxy' => 'tcp://proxy.com:80',
                    'header' => 'Proxy-Authorization: Basic dXNlcjpwQHNz',
                    'request_fulluri' => true,
                ]],
                false,
                'http://user:***@proxy.com:80',
            ],
            [
                $server, 'https://repo.org', 'https://proxy.com:443',
                ['http' => [
                    'proxy' => 'ssl://proxy.com:443',
                ]],
                true,
                'https://proxy.com:443',
            ],
            [$server, 'https://other.repo.org', '', [], false, 'no_proxy'],
        ];
    }

    /**
     * @dataProvider dataStatus
     *
     * @param array<string, mixed> $server
     */
    public function testGetStatus(array $server, bool $expectedStatus, ?string $expectedMessage): void
    {
        $_SERVER = array_merge($_SERVER, $server);
        $proxyManager = ProxyManager::getInstance();
        $status = $proxyManager->isProxying();
        $message = $proxyManager->getFormattedProxy();

        $this->assertSame($expectedStatus, $status);

        if ($expectedMessage !== null) {
            $condition = stripos($message, $expectedMessage) !== false;
        } else {
            $condition = $expectedMessage === $message;
        }
        $this->assertTrue($condition, 'message check');
    }

    public static function dataStatus(): array
    {
        // server, expectedStatus, expectedMessage
        return [
            [[], false, null],
            [['http_proxy' => 'localhost'], false, 'malformed'],
            [
                ['http_proxy' => 'http://user:p%40ss@proxy.com:80'],
                true,
                'http=http://user:***@proxy.com:80',
            ],
            [
                ['http_proxy' => 'proxy.com:80', 'https_proxy' => 'proxy.com:80'],
                true,
                'http=proxy.com:80, https=proxy.com:80',
            ],
        ];
    }
}
