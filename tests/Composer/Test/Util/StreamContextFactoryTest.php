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

use Composer\Util\StreamContextFactory;

class StreamContextFactoryTest extends \PHPUnit_Framework_TestCase
{
    protected function setUp()
    {
        unset($_SERVER['HTTP_PROXY']);
        unset($_SERVER['http_proxy']);
    }

    protected function tearDown()
    {
        unset($_SERVER['HTTP_PROXY']);
        unset($_SERVER['http_proxy']);
    }

    /**
     * @dataProvider dataGetContext
     */
    public function testGetContext($expectedOptions, $defaultOptions, $expectedParams, $defaultParams)
    {
        $context = StreamContextFactory::getContext($defaultOptions, $defaultParams);
        $options = stream_context_get_options($context);
        $params = stream_context_get_params($context);

        $this->assertEquals($expectedOptions, $options);
        $this->assertEquals($expectedParams, $params);
    }

    public function dataGetContext()
    {
        return array(
            array(
                array(), array(),
                array('options' => array()), array()
            ),
            array(
                $a = array('http' => array('method' => 'GET')), $a,
                array('options' => $a, 'notification' => $f = function() {}), array('notification' => $f)
            ),
        );
    }

    public function testHttpProxy()
    {
        $_SERVER['HTTP_PROXY'] = 'http://username:password@proxyserver.net:port/';
        $_SERVER['http_proxy'] = 'http://proxyserver/';

        $context = StreamContextFactory::getContext(array('http' => array('method' => 'GET')));
        $options = stream_context_get_options($context);

        $this->assertSame('http://proxyserver/', $_SERVER['http_proxy']);

        $this->assertEquals(array('http' => array(
            'proxy' => 'tcp://username:password@proxyserver.net:port/',
            'request_fulluri' => true,
            'method' => 'GET',
        )), $options);
    }

    public function testSSLProxy()
    {
        $_SERVER['http_proxy'] = 'https://proxyserver/';

        if (extension_loaded('openssl')) {
            $context = StreamContextFactory::getContext();
            $options = stream_context_get_options($context);

            $this->assertSame(array('http' => array(
                'proxy' => 'ssl://proxyserver/',
                'request_fulluri' => true,
            )), $options);
        } else {
            try {
                StreamContextFactory::getContext();
                $this->fail();
            } catch (\Exception $e) {
                $this->assertInstanceOf('RuntimeException', $e);
            }
        }
    }
}
