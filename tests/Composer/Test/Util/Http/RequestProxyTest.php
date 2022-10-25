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
     */
    public function testIsSecure(string $url, bool $expectedSecure): void
    {
        $proxy = new RequestProxy($url, [], '');

        $this->assertSame($expectedSecure, $proxy->isSecure());
    }

    public function dataSecure(): array
    {
        // url, secure
        return [
            'basic' => ['http://proxy.com:80', false],
            'secure' => ['https://proxy.com:443', true],
            'none' => ['', false],
        ];
    }

    /**
     * @dataProvider dataProxyUrl
     */
    public function testGetFormattedUrlFormat(string $url, string $format, string $expected): void
    {
        $proxy = new RequestProxy($url, [], $url);

        $message = $proxy->getFormattedUrl($format);
        $this->assertSame($expected, $message);
    }

    public function dataProxyUrl(): array
    {
        $format = 'proxy (%s)';

        // url, format, expected
        return [
            ['', $format, ''],
            ['http://proxy.com:80', $format, 'proxy (http://proxy.com:80)'],
        ];
    }
}
