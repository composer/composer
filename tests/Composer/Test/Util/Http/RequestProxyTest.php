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
    /**
     * @dataProvider dataSecure
     *
     * @param ?non-empty-string $url
     */
    public function testIsSecure(?string $url, bool $expectedSecure): void
    {
        $proxy = new RequestProxy($url, null, null);

        $this->assertSame($expectedSecure, $proxy->isSecure());
    }

    /**
     * @return array<string, array{0: ?non-empty-string, 1: bool}>
     */
    public static function dataSecure(): array
    {
        // url, expectedSecure
        return [
            'basic' => ['http://proxy.com:80', false],
            'secure' => ['https://proxy.com:443', true],
            'none' => [null, false],
        ];
    }

    public function testGetStatusThrowsOnBadFormat(): void
    {
        $proxy = new RequestProxy('http://proxy.com:80', null, 'http://proxy.com:80');
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
        $proxy = new RequestProxy($url, null, $url);

        if ($format === null) {
            // try with and without optional param
            $this->assertSame($expected, $proxy->getStatus());
            $this->assertSame($expected, $proxy->getStatus($format));
        } else {
            $this->assertSame($expected, $proxy->getStatus($format));
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
}
