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

namespace Composer\Test\Util\Transfer;

use Composer\Util\Transfer\CurlDriver;


class CurlDriverTest extends \PHPUnit_Framework_TestCase
{
        public function testGetOptionsForUrl()
    {
        $io = $this->getMock('Composer\IO\IOInterface');
        $io
            ->expects($this->once())
            ->method('hasAuthentication')
            ->will($this->returnValue(false))
        ;

        $res = $this->callGetOptionsForUrl($io, array('http://example.org', array()));
        $this->assertTrue(is_array($res), 'getOptions must return an array of cUrl options');
        
        $this->assertTrue(isset($res[CURLOPT_USERAGENT]), 'getOptions must have an User-Agent option');
        $this->assertTrue(isset($res[CURLOPT_ENCODING]), 'getOptions must have a Accept-Encoding option');
    }

    public function testGetOptionsForUrlWithAuthorization()
    {
        $io = $this->getMock('Composer\IO\IOInterface');
        $io
            ->expects($this->once())
            ->method('hasAuthentication')
            ->will($this->returnValue(true))
        ;
        $io
            ->expects($this->once())
            ->method('getAuthentication')
            ->will($this->returnValue(array('username' => 'login', 'password' => 'password')))
        ;

        $options = $this->callGetOptionsForUrl($io, array('http://example.org', array()));

        $found = false;
        foreach ($options['http']['header'] as $header) {
            if (0 === strpos($header, 'Authorization: Basic')) {
                $found = true;
            }
        }
        $this->assertTrue($found, 'getOptions must have an Authorization header');
    }

    public function testGetOptionsForUrlWithStreamOptions()
    {
        $io = $this->getMock('Composer\IO\IOInterface');
        $io
            ->expects($this->once())
            ->method('hasAuthentication')
            ->will($this->returnValue(true))
        ;

        $streamOptions = array('ssl' => array(
            'allow_self_signed' => true,
        ));

        $res = $this->callGetOptionsForUrl($io, array('https://example.org', array()), $streamOptions);
        $this->assertTrue(isset($res['ssl']) && isset($res['ssl']['allow_self_signed']) && true === $res['ssl']['allow_self_signed'], 'getOptions must return an array with a allow_self_signed set to true');
    }

    public function testGetOptionsForUrlWithCallOptionsKeepsHeader()
    {
        $io = $this->getMock('Composer\IO\IOInterface');
        $io
            ->expects($this->once())
            ->method('hasAuthentication')
            ->will($this->returnValue(true))
        ;

        $streamOptions = array('http' => array(
            'header' => 'Foo: bar',
        ));

        $res = $this->callGetOptionsForUrl($io, array('https://example.org', $streamOptions));
        $this->assertTrue(isset($res['http']['header']), 'getOptions must return an array with a http.header key');

        $found = false;
        foreach ($res['http']['header'] as $header) {
            if ($header === 'Foo: bar') {
                $found = true;
            }
        }

        $this->assertTrue($found, 'getOptions must have a Foo: bar header');
        $this->assertGreaterThan(1, count($res['http']['header']));
    }

    protected function callGetOptionsForUrl($io, array $args = array(), array $options = array())
    {
        $driver=new CurlDriver($io,null,$options,null);
        $ref = new \ReflectionMethod($driver, 'getOptionsForUrl');
        $ref->setAccessible(true);

        return $ref->invokeArgs($driver, $args);
    }

    protected function setAttribute($object, $attribute, $value)
    {
        $attr = new \ReflectionProperty($object, $attribute);
        $attr->setAccessible(true);
        $attr->setValue($object, $value);
    }

}
