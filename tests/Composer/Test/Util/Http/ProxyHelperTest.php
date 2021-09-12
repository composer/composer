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

use Composer\Util\Http\ProxyHelper;
use Composer\Test\TestCase;

class ProxyHelperTest extends TestCase
{
    protected function setUp()
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

    protected function tearDown()
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

    /**
     * @dataProvider dataMalformed
     */
    public function testThrowsOnMalformedUrl($url)
    {
        $_SERVER['http_proxy'] = $url;

        $this->setExpectedException('RuntimeException');
        ProxyHelper::getProxyData();
    }

    public function dataMalformed()
    {
        return array(
            'no-host' => array('localhost'),
            'no-port' => array('scheme://localhost'),
        );
    }

    /**
     * @dataProvider dataFormatting
     */
    public function testUrlFormatting($url, $expected)
    {
        $_SERVER['http_proxy'] = $url;

        list($httpProxy, $httpsProxy, $noProxy) = ProxyHelper::getProxyData();
        $this->assertSame($expected, $httpProxy);
    }

    public function dataFormatting()
    {
        // url, expected
        return array(
            'lowercases-scheme' => array('HTTP://proxy.com:8888', 'http://proxy.com:8888'),
            'adds-http-port' => array('http://proxy.com', 'http://proxy.com:80'),
            'adds-https-port' => array('https://proxy.com', 'https://proxy.com:443'),
        );
    }

    /**
     * @dataProvider dataCaseOverrides
     */
    public function testLowercaseOverridesUppercase(array $server, $expected, $index)
    {
        $_SERVER = array_merge($_SERVER, $server);

        $list = ProxyHelper::getProxyData();
        $this->assertSame($expected, $list[$index]);
    }

    public function dataCaseOverrides()
    {
        // server, expected, list index
        return array(
            array(array('HTTP_PROXY' => 'http://upper.com', 'http_proxy' => 'http://lower.com'), 'http://lower.com:80', 0),
            array(array('HTTPS_PROXY' => 'http://upper.com', 'https_proxy' => 'http://lower.com'), 'http://lower.com:80', 1),
            array(array('NO_PROXY' => 'upper.com', 'no_proxy' => 'lower.com'), 'lower.com', 2),
        );
    }

    /**
     * @dataProvider dataCGIOverrides
     */
    public function testCGIUpperCaseOverridesHttp(array $server, $expected, $index)
    {
        $_SERVER = array_merge($_SERVER, $server);

        $list = ProxyHelper::getProxyData();
        $this->assertSame($expected, $list[$index]);
    }

    public function dataCGIOverrides()
    {
        // server, expected, list index
        return array(
            array(array('http_proxy' => 'http://http.com', 'CGI_HTTP_PROXY' => 'http://cgi.com'), 'http://cgi.com:80', 0),
            array(array('http_proxy' => 'http://http.com', 'cgi_http_proxy' => 'http://cgi.com'), 'http://http.com:80', 0),
        );
    }

    public function testNoHttpsProxyUsesHttpProxy()
    {
        $_SERVER['http_proxy'] = 'http://http.com';

        list($httpProxy, $httpsProxy, $noProxy) = ProxyHelper::getProxyData();
        $this->assertSame('http://http.com:80', $httpsProxy);
    }

    public function testNoHttpProxyDoesNotUseHttpsProxy()
    {
        $_SERVER['https_proxy'] = 'http://https.com';

        list($httpProxy, $httpsProxy, $noProxy) = ProxyHelper::getProxyData();
        $this->assertSame(null, $httpProxy);
    }

    /**
     * @dataProvider dataContextOptions
     */
    public function testGetContextOptions($url, $expected)
    {
        $this->assertEquals($expected, ProxyHelper::getContextOptions($url));
    }

    public function dataContextOptions()
    {
        // url, expected
        return array(
            array('http://proxy.com', array('http' => array(
                'proxy' => 'tcp://proxy.com:80',
            ))),
            array('https://proxy.com', array('http' => array(
                'proxy' => 'ssl://proxy.com:443',
            ))),
            array('http://user:p%40ss@proxy.com', array('http' => array(
                'proxy' => 'tcp://proxy.com:80',
                'header' => 'Proxy-Authorization: Basic dXNlcjpwQHNz',
            ))),
        );
    }

    /**
     * @dataProvider dataRequestFullUri
     */
    public function testSetRequestFullUri($requestUrl, $expected)
    {
        $options = array();
        ProxyHelper::setRequestFullUri($requestUrl, $options);

        $this->assertEquals($expected, $options);
    }

    public function dataRequestFullUri()
    {
        $options = array('http' => array('request_fulluri' => true));

        // $requestUrl, expected
        return array(
            'http' => array('http://repo.org', $options),
            'https' => array('https://repo.org', array()),
            'no-scheme' => array('repo.org', array()),
        );
    }
}
