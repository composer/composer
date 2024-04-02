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

use Composer\Util\Http\ProxyItem;
use Composer\Test\TestCase;

/**
 * @phpstan-import-type contextOptions from ProxyItem
 */
class ProxyItemTest extends TestCase
{
    /**
     * @dataProvider dataMalformed
     */
    public function testThrowsOnMalformedUrl(string $url): void
    {
        self::expectException('RuntimeException');
        self::expectExceptionMessage('unsupported `http_proxy` syntax');
        $proxy = new ProxyItem($url, 'http_proxy');
    }

    /**
     * @return array<string, array<string>>
     */
    public static function dataMalformed(): array
    {
        return [
            'ws-r' => ["http://user\rname@localhost:80"],
            'ws-n' => ["http://user\nname@localhost:80"],
            'ws-t' => ["http://user\tname@localhost:80"],
            'no-host' => ['localhost'],
            'no-port' => ['scheme://localhost'],
            'port-0' => ['http://localhost:0'],
            'port-big' => ['http://localhost:65536'],
        ];
    }

    /**
     * @dataProvider dataFormatting
     */
    public function testUrlFormatting(string $url, string $expected): void
    {
        $proxy = new ProxyItem($url, 'http_proxy');

        self::assertEquals($expected, $proxy->getProxyUrl());
    }

    /**
     * @return array<string, array<string>>
     */
    public static function dataFormatting(): array
    {
        // url, expected
        return [
            'none' => ['http://proxy.com:8888', 'http://proxy.com:8888'],
            'lowercases-scheme' => ['HTTP://proxy.com:8888', 'http://proxy.com:8888'],
            'adds-http-port' => ['http://proxy.com', 'http://proxy.com:80'],
            'adds-https-port' => ['https://proxy.com', 'https://proxy.com:443'],
            'keeps-user' => ['http://user@proxy.com:6180', 'http://user@proxy.com:6180'],
            'keeps-user-pass' => ['http://user:p%40ss@proxy.com:6180', 'http://user:p%40ss@proxy.com:6180'],
        ];
    }

    /**
     * @dataProvider dataSafe
     */
    public function testSafeUrl(string $url, string $expected): void
    {
        $proxy = new ProxyItem($url, 'http_proxy');

        self::assertEquals($expected, $proxy->getSafeUrl());
    }

    /**
     * @return array<string, array<string>>
     */
    public static function dataSafe(): array
    {
        // url, expected
        return [
            'no userinfo' => ['http://proxy.com', 'http://proxy.com:80'],
            'user' => ['http://user@proxy.com', 'http://***@proxy.com:80'],
            'user-pass' => ['http://user:p%40ss@proxy.com', 'http://***:***@proxy.com:80'],
        ];
    }

    /**
     * @dataProvider dataContextOptions
     *
     * @param contextOptions $expected
     */
    public function testGetContextOptions(string $url, string $schemel, array $expected): void
    {
        $proxy = new ProxyItem($url, 'http_proxy');
        self::assertEquals($expected, $proxy->getContextOptions($schemel));
    }

    /**
     * @return list<array{0: string, 1: string, 2: contextOptions}>
     */
    public static function dataContextOptions(): array
    {
        // url, scheme, expected
        return [
            ['http://proxy.com:6180', 'http', ['http' => [
                'proxy' => 'tcp://proxy.com:6180',
                'request_fulluri' => true,
            ]]],
            ['http://proxy.com:6180', 'https', ['http' => [
                'proxy' => 'tcp://proxy.com:6180',
            ]]],
            ['https://proxy.com:6180', 'http', ['http' => [
                'proxy' => 'ssl://proxy.com:6180',
                'request_fulluri' => true,
            ]]],
            ['http://user:p%40ss@proxy.com:6180', 'http', ['http' => [
                'proxy' => 'tcp://proxy.com:6180',
                'header' => 'Proxy-Authorization: Basic dXNlcjpwQHNz',
                'request_fulluri' => true,
            ]]],
        ];
    }
}
