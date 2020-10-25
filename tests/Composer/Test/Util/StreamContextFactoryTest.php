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

namespace Composer\Test\Util;

use Composer\Util\Http\ProxyManager;
use Composer\Util\StreamContextFactory;
use Composer\Test\TestCase;

class StreamContextFactoryTest extends TestCase
{
    protected function setUp()
    {
        unset($_SERVER['HTTP_PROXY'], $_SERVER['http_proxy'], $_SERVER['HTTPS_PROXY'], $_SERVER['https_proxy'], $_SERVER['NO_PROXY'], $_SERVER['no_proxy']);
        ProxyManager::reset();
    }

    protected function tearDown()
    {
        unset($_SERVER['HTTP_PROXY'], $_SERVER['http_proxy'], $_SERVER['HTTPS_PROXY'], $_SERVER['https_proxy'], $_SERVER['NO_PROXY'], $_SERVER['no_proxy']);
        ProxyManager::reset();
    }

    /**
     * @dataProvider dataGetContext
     */
    public function testGetContext($expectedOptions, $defaultOptions, $expectedParams, $defaultParams)
    {
        $context = StreamContextFactory::getContext('http://example.org', $defaultOptions, $defaultParams);
        $options = stream_context_get_options($context);
        $params = stream_context_get_params($context);

        $this->assertEquals($expectedOptions, $options);
        $this->assertEquals($expectedParams, $params);
    }

    public function dataGetContext()
    {
        return array(
            array(
                $a = array('http' => array('follow_location' => 1, 'max_redirects' => 20, 'header' => array('User-Agent: foo'))), array('http' => array('header' => 'User-Agent: foo')),
                array('options' => $a), array(),
            ),
            array(
                $a = array('http' => array('method' => 'GET', 'max_redirects' => 20, 'follow_location' => 1, 'header' => array('User-Agent: foo'))), array('http' => array('method' => 'GET', 'header' => 'User-Agent: foo')),
                array('options' => $a, 'notification' => $f = function () {
                }), array('notification' => $f),
            ),
        );
    }

    public function testHttpProxy()
    {
        $_SERVER['http_proxy'] = 'http://username:p%40ssword@proxyserver.net:3128/';
        $_SERVER['HTTP_PROXY'] = 'http://proxyserver/';

        $context = StreamContextFactory::getContext('http://example.org', array('http' => array('method' => 'GET', 'header' => 'User-Agent: foo')));
        $options = stream_context_get_options($context);

        $this->assertEquals(array('http' => array(
            'proxy' => 'tcp://proxyserver.net:3128',
            'request_fulluri' => true,
            'method' => 'GET',
            'header' => array('User-Agent: foo', "Proxy-Authorization: Basic " . base64_encode('username:p@ssword')),
            'max_redirects' => 20,
            'follow_location' => 1,
        )), $options);
    }

    public function testHttpProxyWithNoProxy()
    {
        $_SERVER['http_proxy'] = 'http://username:password@proxyserver.net:3128/';
        $_SERVER['no_proxy'] = 'foo,example.org';

        $context = StreamContextFactory::getContext('http://example.org', array('http' => array('method' => 'GET', 'header' => 'User-Agent: foo')));
        $options = stream_context_get_options($context);

        $this->assertEquals(array('http' => array(
            'method' => 'GET',
            'max_redirects' => 20,
            'follow_location' => 1,
            'header' => array('User-Agent: foo'),
        )), $options);
    }

    public function testHttpProxyWithNoProxyWildcard()
    {
        $_SERVER['http_proxy'] = 'http://username:password@proxyserver.net:3128/';
        $_SERVER['no_proxy'] = '*';

        $context = StreamContextFactory::getContext('http://example.org', array('http' => array('method' => 'GET', 'header' => 'User-Agent: foo')));
        $options = stream_context_get_options($context);

        $this->assertEquals(array('http' => array(
            'method' => 'GET',
            'max_redirects' => 20,
            'follow_location' => 1,
            'header' => array('User-Agent: foo'),
        )), $options);
    }

    public function testOptionsArePreserved()
    {
        $_SERVER['http_proxy'] = 'http://username:password@proxyserver.net:3128/';

        $context = StreamContextFactory::getContext('http://example.org', array('http' => array('method' => 'GET', 'header' => array('User-Agent: foo', "X-Foo: bar"), 'request_fulluri' => false)));
        $options = stream_context_get_options($context);

        $this->assertEquals(array('http' => array(
            'proxy' => 'tcp://proxyserver.net:3128',
            'request_fulluri' => false,
            'method' => 'GET',
            'header' => array('User-Agent: foo', "X-Foo: bar", "Proxy-Authorization: Basic " . base64_encode('username:password')),
            'max_redirects' => 20,
            'follow_location' => 1,
        )), $options);
    }

    public function testHttpProxyWithoutPort()
    {
        $_SERVER['http_proxy'] = 'http://username:password@proxyserver.net';

        $context = StreamContextFactory::getContext('https://example.org', array('http' => array('method' => 'GET', 'header' => 'User-Agent: foo')));
        $options = stream_context_get_options($context);

        $this->assertEquals(array('http' => array(
            'proxy' => 'tcp://proxyserver.net:80',
            'method' => 'GET',
            'header' => array('User-Agent: foo', "Proxy-Authorization: Basic " . base64_encode('username:password')),
            'max_redirects' => 20,
            'follow_location' => 1,
        )), $options);
    }

    public function testHttpsProxyOverride()
    {
        if (!extension_loaded('openssl')) {
            $this->markTestSkipped('Requires openssl');
        }

        $_SERVER['http_proxy'] = 'http://username:password@proxyserver.net';
        $_SERVER['https_proxy'] = 'https://woopproxy.net';

        // Pointless test replaced by ProxyHelperTest.php
        $this->setExpectedException('Composer\Downloader\TransportException');
        $context = StreamContextFactory::getContext('https://example.org', array('http' => array('method' => 'GET', 'header' => 'User-Agent: foo')));
    }

    /**
     * @dataProvider dataSSLProxy
     */
    public function testSSLProxy($expected, $proxy)
    {
        $_SERVER['http_proxy'] = $proxy;

        if (extension_loaded('openssl')) {
            $context = StreamContextFactory::getContext('http://example.org', array('http' => array('header' => 'User-Agent: foo')));
            $options = stream_context_get_options($context);

            $this->assertEquals(array('http' => array(
                'proxy' => $expected,
                'request_fulluri' => true,
                'max_redirects' => 20,
                'follow_location' => 1,
                'header' => array('User-Agent: foo'),
            )), $options);
        } else {
            try {
                StreamContextFactory::getContext('http://example.org');
                $this->fail();
            } catch (\RuntimeException $e) {
                $this->assertInstanceOf('Composer\Downloader\TransportException', $e);
            }
        }
    }

    public function dataSSLProxy()
    {
        return array(
            array('ssl://proxyserver:443', 'https://proxyserver/'),
            array('ssl://proxyserver:8443', 'https://proxyserver:8443'),
        );
    }

    public function testEnsureThatfixHttpHeaderFieldMovesContentTypeToEndOfOptions()
    {
        $options = array(
            'http' => array(
                'header' => "User-agent: foo\r\nX-Foo: bar\r\nContent-Type: application/json\r\nAuthorization: Basic aW52YWxpZA==",
            ),
        );
        $expectedOptions = array(
            'http' => array(
                'header' => array(
                    "User-agent: foo",
                    "X-Foo: bar",
                    "Authorization: Basic aW52YWxpZA==",
                    "Content-Type: application/json",
                ),
            ),
        );
        $context = StreamContextFactory::getContext('http://example.org', $options);
        $ctxoptions = stream_context_get_options($context);
        $this->assertEquals(end($expectedOptions['http']['header']), end($ctxoptions['http']['header']));
    }

    public function testInitOptionsDoesIncludeProxyAuthHeaders()
    {
        $_SERVER['http_proxy'] = 'http://username:password@proxyserver.net:3128/';

        $options = array();
        $options = StreamContextFactory::initOptions('https://example.org', $options);
        $headers = implode(' ', $options['http']['header']);

        $this->assertTrue(false !== stripos($headers, 'Proxy-Authorization'));
    }

    public function testInitOptionsForCurlDoesNotIncludeProxyAuthHeaders()
    {
        if (!extension_loaded('curl')) {
            $this->markTestSkipped('The curl is not available.');
        }

        $_SERVER['http_proxy'] = 'http://username:password@proxyserver.net:3128/';

        $options = array();
        $options = StreamContextFactory::initOptions('https://example.org', $options, true);
        $headers = implode(' ', $options['http']['header']);

        $this->assertFalse(stripos($headers, 'Proxy-Authorization'));
    }
}
