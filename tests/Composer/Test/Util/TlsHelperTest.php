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

use Composer\Util\TlsHelper;

class TlsHelperTest extends \PHPUnit_Framework_TestCase
{
    /** @dataProvider dataCheckCertificateHost */
    public function testCheckCertificateHost($expectedResult, $hostname, $certNames)
    {
        $certificate['subject']['commonName'] = $expectedCn = array_shift($certNames);
        $certificate['extensions']['subjectAltName'] = $certNames ? 'DNS:'.implode(',DNS:', $certNames) : '';

        $result = TlsHelper::checkCertificateHost($certificate, $hostname, $foundCn);

        if (true === $expectedResult) {
            $this->assertTrue($result);
            $this->assertSame($expectedCn, $foundCn);
        } else {
            $this->assertFalse($result);
            $this->assertNull($foundCn);
        }
    }

    public function dataCheckCertificateHost()
    {
        return array(
            array(true, 'getcomposer.org', array('getcomposer.org')),
            array(true, 'getcomposer.org', array('getcomposer.org', 'packagist.org')),
            array(true, 'getcomposer.org', array('packagist.org', 'getcomposer.org')),
            array(true, 'foo.getcomposer.org', array('*.getcomposer.org')),
            array(false, 'xyz.foo.getcomposer.org', array('*.getcomposer.org')),
            array(true, 'foo.getcomposer.org', array('getcomposer.org', '*.getcomposer.org')),
            array(true, 'foo.getcomposer.org', array('foo.getcomposer.org', 'foo*.getcomposer.org')),
            array(true, 'foo1.getcomposer.org', array('foo.getcomposer.org', 'foo*.getcomposer.org')),
            array(true, 'foo2.getcomposer.org', array('foo.getcomposer.org', 'foo*.getcomposer.org')),
            array(false, 'foo2.another.getcomposer.org', array('foo.getcomposer.org', 'foo*.getcomposer.org')),
            array(false, 'test.example.net', array('**.example.net', '**.example.net')),
            array(false, 'test.example.net', array('t*t.example.net', 't*t.example.net')),
            array(false, 'xyz.example.org', array('*z.example.org', '*z.example.org')),
            array(false, 'foo.bar.example.com', array('foo.*.example.com', 'foo.*.example.com')),
            array(false, 'example.com', array('example.*', 'example.*')),
            array(true, 'localhost', array('localhost')),
            array(false, 'localhost', array('*')),
            array(false, 'localhost', array('local*')),
            array(false, 'example.net', array('*.net', '*.org', 'ex*.net')),
            array(true, 'example.net', array('*.net', '*.org', 'example.net')),
        );
    }

    public function testGetCertificateNames()
    {
        $certificate['subject']['commonName'] = 'example.net';
        $certificate['extensions']['subjectAltName'] = 'DNS: example.com, IP: 127.0.0.1, DNS: getcomposer.org, Junk: blah, DNS: composer.example.org';

        $names = TlsHelper::getCertificateNames($certificate);

        $this->assertSame('example.net', $names['cn']);
        $this->assertSame(array(
            'example.com',
            'getcomposer.org',
            'composer.example.org',
        ), $names['san']);
    }
}
