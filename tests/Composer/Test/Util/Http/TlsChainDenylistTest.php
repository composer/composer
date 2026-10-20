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

use Composer\CaBundle\CaBundle;
use Composer\Test\TestCase;
use Composer\Util\Http\TlsChainDenylist;

class TlsChainDenylistTest extends TestCase
{
    /**
     * @return void
     */
    public function testFindDistrustedCertificateInCurlInfo()
    {
        $distrusted = (string) file_get_contents(__DIR__.'/../Fixtures/distrusted/em-ev-tls-ca-g3b.pem');
        $trusted = (string) file_get_contents(CaBundle::getBundledCaBundlePath());

        $this->assertNull(TlsChainDenylist::findDistrustedCertificateInCurlInfo(array()));
        $this->assertNull(TlsChainDenylist::findDistrustedCertificateInCurlInfo(array(array('Subject' => 'foo', 'Cert' => $trusted))));
        $this->assertSame('EM EV TLS CA - G3B', TlsChainDenylist::findDistrustedCertificateInCurlInfo(array(array('Cert' => $trusted), array('Cert' => $distrusted))));
    }

    /**
     * @dataProvider provideHostKeys
     *
     * @param string      $url
     * @param string|null $expected
     *
     * @return void
     */
    public function testGetHostKey($url, $expected)
    {
        $this->assertSame($expected, TlsChainDenylist::getHostKey($url));
    }

    /**
     * @return array<array{string, string|null}>
     */
    public function provideHostKeys()
    {
        return array(
            array('https://Example.org/foo', 'example.org:443'),
            array('https://example.org:8443/foo', 'example.org:8443'),
            array('http://example.org/foo', null),
            array('file:///tmp/foo', null),
        );
    }

    /**
     * @return void
     */
    public function testCreateException()
    {
        $message = TlsChainDenylist::createException('https://user:pass@example.org/', 'Foo CA', true)->getMessage();
        $this->assertTrue(false !== strpos($message, '"Foo CA"'));
        $this->assertTrue(false !== strpos($message, 'should be rotated'));
        $this->assertTrue(false === strpos($message, 'pass@'));

        $message = TlsChainDenylist::createException('https://example.org/', 'Foo CA', false)->getMessage();
        $this->assertTrue(false !== strpos($message, 'aborted before it was sent'));
    }
}
