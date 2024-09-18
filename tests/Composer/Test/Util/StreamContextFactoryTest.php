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

use Composer\Util\Http\ProxyManager;
use Composer\Util\StreamContextFactory;
use Composer\Test\TestCase;

class StreamContextFactoryTest extends TestCase
{
    protected function setUp(): void
    {
        unset($_SERVER['HTTP_PROXY'], $_SERVER['http_proxy'], $_SERVER['HTTPS_PROXY'], $_SERVER['https_proxy'], $_SERVER['NO_PROXY'], $_SERVER['no_proxy']);
        ProxyManager::reset();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        unset($_SERVER['HTTP_PROXY'], $_SERVER['http_proxy'], $_SERVER['HTTPS_PROXY'], $_SERVER['https_proxy'], $_SERVER['NO_PROXY'], $_SERVER['no_proxy']);
        ProxyManager::reset();
    }

    /**
     * @dataProvider dataGetContext
     *
     * @param mixed[] $expectedOptions
     * @param mixed[] $defaultOptions
     * @param mixed[] $expectedParams
     * @param mixed[] $defaultParams
     */
    public function testGetContext(array $expectedOptions, array $defaultOptions, array $expectedParams, array $defaultParams): void
    {
        $context = StreamContextFactory::getContext('http://example.org', $defaultOptions, $defaultParams);
        $options = stream_context_get_options($context);
        $params = stream_context_get_params($context);

        self::assertEquals($expectedOptions, $options);
        self::assertEquals($expectedParams, $params);
    }

    public static function dataGetContext(): array
    {
        return [
            [
                $a = ['http' => ['follow_location' => 1, 'max_redirects' => 20, 'header' => ['User-Agent: foo']]], ['http' => ['header' => 'User-Agent: foo']],
                ['options' => $a], [],
            ],
            [
                $a = ['http' => ['method' => 'GET', 'max_redirects' => 20, 'follow_location' => 1, 'header' => ['User-Agent: foo']]], ['http' => ['method' => 'GET', 'header' => 'User-Agent: foo']],
                ['options' => $a, 'notification' => $f = static function (): void {
                }], ['notification' => $f],
            ],
        ];
    }

    public function testHttpProxy(): void
    {
        $_SERVER['http_proxy'] = 'http://username:p%40ssword@proxyserver.net:3128/';
        $_SERVER['HTTP_PROXY'] = 'http://proxyserver/';

        $context = StreamContextFactory::getContext('http://example.org', ['http' => ['method' => 'GET', 'header' => 'User-Agent: foo']]);
        $options = stream_context_get_options($context);

        self::assertEquals(['http' => [
            'proxy' => 'tcp://proxyserver.net:3128',
            'request_fulluri' => true,
            'method' => 'GET',
            'header' => ['User-Agent: foo', "Proxy-Authorization: Basic " . base64_encode('username:p@ssword')],
            'max_redirects' => 20,
            'follow_location' => 1,
        ]], $options);
    }

    public function testHttpProxyWithNoProxy(): void
    {
        $_SERVER['http_proxy'] = 'http://username:password@proxyserver.net:3128/';
        $_SERVER['no_proxy'] = 'foo,example.org';

        $context = StreamContextFactory::getContext('http://example.org', ['http' => ['method' => 'GET', 'header' => 'User-Agent: foo']]);
        $options = stream_context_get_options($context);

        self::assertEquals(['http' => [
            'method' => 'GET',
            'max_redirects' => 20,
            'follow_location' => 1,
            'header' => ['User-Agent: foo'],
        ]], $options);
    }

    public function testHttpProxyWithNoProxyWildcard(): void
    {
        $_SERVER['http_proxy'] = 'http://username:password@proxyserver.net:3128/';
        $_SERVER['no_proxy'] = '*';

        $context = StreamContextFactory::getContext('http://example.org', ['http' => ['method' => 'GET', 'header' => 'User-Agent: foo']]);
        $options = stream_context_get_options($context);

        self::assertEquals(['http' => [
            'method' => 'GET',
            'max_redirects' => 20,
            'follow_location' => 1,
            'header' => ['User-Agent: foo'],
        ]], $options);
    }

    public function testOptionsArePreserved(): void
    {
        $_SERVER['http_proxy'] = 'http://username:password@proxyserver.net:3128/';

        $context = StreamContextFactory::getContext('http://example.org', ['http' => ['method' => 'GET', 'header' => ['User-Agent: foo', "X-Foo: bar"], 'request_fulluri' => false]]);
        $options = stream_context_get_options($context);

        self::assertEquals(['http' => [
            'proxy' => 'tcp://proxyserver.net:3128',
            'request_fulluri' => false,
            'method' => 'GET',
            'header' => ['User-Agent: foo', "X-Foo: bar", "Proxy-Authorization: Basic " . base64_encode('username:password')],
            'max_redirects' => 20,
            'follow_location' => 1,
        ]], $options);
    }

    public function testHttpProxyWithoutPort(): void
    {
        $_SERVER['https_proxy'] = 'http://username:password@proxyserver.net';

        $context = StreamContextFactory::getContext('https://example.org', ['http' => ['method' => 'GET', 'header' => 'User-Agent: foo']]);
        $options = stream_context_get_options($context);

        self::assertEquals(['http' => [
            'proxy' => 'tcp://proxyserver.net:80',
            'method' => 'GET',
            'header' => ['User-Agent: foo', "Proxy-Authorization: Basic " . base64_encode('username:password')],
            'max_redirects' => 20,
            'follow_location' => 1,
        ]], $options);
    }

    public function testHttpsProxyOverride(): void
    {
        if (!extension_loaded('openssl')) {
            $this->markTestSkipped('Requires openssl');
        }

        $_SERVER['http_proxy'] = 'http://username:password@proxyserver.net';
        $_SERVER['https_proxy'] = 'https://woopproxy.net';

        // Pointless test replaced by ProxyHelperTest.php
        self::expectException('Composer\Downloader\TransportException');
        $context = StreamContextFactory::getContext('https://example.org', ['http' => ['method' => 'GET', 'header' => 'User-Agent: foo']]);
    }

    /**
     * @dataProvider dataSSLProxy
     */
    public function testSSLProxy(string $expected, string $proxy): void
    {
        $_SERVER['http_proxy'] = $proxy;

        if (extension_loaded('openssl')) {
            $context = StreamContextFactory::getContext('http://example.org', ['http' => ['header' => 'User-Agent: foo']]);
            $options = stream_context_get_options($context);

            self::assertEquals(['http' => [
                'proxy' => $expected,
                'request_fulluri' => true,
                'max_redirects' => 20,
                'follow_location' => 1,
                'header' => ['User-Agent: foo'],
            ]], $options);
        } else {
            try {
                StreamContextFactory::getContext('http://example.org');
                $this->fail();
            } catch (\RuntimeException $e) {
                self::assertInstanceOf('Composer\Downloader\TransportException', $e);
            }
        }
    }

    public static function dataSSLProxy(): array
    {
        return [
            ['ssl://proxyserver:443', 'https://proxyserver/'],
            ['ssl://proxyserver:8443', 'https://proxyserver:8443'],
        ];
    }

    public function testEnsureThatfixHttpHeaderFieldMovesContentTypeToEndOfOptions(): void
    {
        $options = [
            'http' => [
                'header' => "User-agent: foo\r\nX-Foo: bar\r\nContent-Type: application/json\r\nAuthorization: Basic aW52YWxpZA==",
            ],
        ];
        $expectedOptions = [
            'http' => [
                'header' => [
                    "User-agent: foo",
                    "X-Foo: bar",
                    "Authorization: Basic aW52YWxpZA==",
                    "Content-Type: application/json",
                ],
            ],
        ];
        $context = StreamContextFactory::getContext('http://example.org', $options);
        $ctxoptions = stream_context_get_options($context);
        self::assertEquals(end($expectedOptions['http']['header']), end($ctxoptions['http']['header']));
    }

    public function testInitOptionsDoesIncludeProxyAuthHeaders(): void
    {
        $_SERVER['https_proxy'] = 'http://username:password@proxyserver.net:3128/';

        $options = [];
        $options = StreamContextFactory::initOptions('https://example.org', $options);
        $headers = implode(' ', $options['http']['header']);

        self::assertTrue(false !== stripos($headers, 'Proxy-Authorization'));
    }

    public function testInitOptionsForCurlDoesNotIncludeProxyAuthHeaders(): void
    {
        if (!extension_loaded('curl')) {
            $this->markTestSkipped('The curl is not available.');
        }

        $_SERVER['http_proxy'] = 'http://username:password@proxyserver.net:3128/';

        $options = [];
        $options = StreamContextFactory::initOptions('https://example.org', $options, true);
        $headers = implode(' ', $options['http']['header']);

        self::assertFalse(stripos($headers, 'Proxy-Authorization'));
    }
}
