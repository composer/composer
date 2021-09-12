<?php

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
    public function testIsSecure($url, $expectedSecure)
    {
        $proxy = new RequestProxy($url, array(), '');

        $this->assertSame($expectedSecure, $proxy->isSecure());
    }

    public function dataSecure()
    {
        // url, secure
        return array(
            'basic' => array('http://proxy.com:80', false),
            'secure' => array('https://proxy.com:443', true),
            'none' => array('', false),
        );
    }

    /**
     * @dataProvider dataProxyUrl
     */
    public function testGetFormattedUrlFormat($url, $format, $expected)
    {
        $proxy = new RequestProxy($url, array(), $url);

        $message = $proxy->getFormattedUrl($format);
        $this->assertSame($expected, $message);
    }

    public function dataProxyUrl()
    {
        $format = 'proxy (%s)';

        // url, format, expected
        return array(
            array('', $format, ''),
            array('http://proxy.com:80', $format, 'proxy (http://proxy.com:80)'),
        );
    }
}
