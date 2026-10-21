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

use Composer\CaBundle\CaBundle;
use Composer\Test\TestCase;
use Composer\Util\Http\TlsChainDenylist;

class TlsChainDenylistTest extends TestCase
{
    public function testFindDistrustedCertificateInCurlInfo(): void
    {
        $distrusted = (string) file_get_contents(__DIR__.'/../Fixtures/distrusted/em-ev-tls-ca-g3b.pem');
        $trusted = (string) file_get_contents(CaBundle::getBundledCaBundlePath());

        self::assertNull(TlsChainDenylist::findDistrustedCertificateInCurlInfo([]));
        self::assertNull(TlsChainDenylist::findDistrustedCertificateInCurlInfo([['Subject' => 'foo', 'Cert' => $trusted]]));
        self::assertSame('EM EV TLS CA - G3B', TlsChainDenylist::findDistrustedCertificateInCurlInfo([['Cert' => $trusted], ['Cert' => $distrusted]]));
    }

    /**
     * @dataProvider provideHostKeys
     */
    public function testGetHostKey(string $url, ?string $expected): void
    {
        self::assertSame($expected, TlsChainDenylist::getHostKey($url));
    }

    /**
     * @return array<array{string, string|null}>
     */
    public static function provideHostKeys(): array
    {
        return [
            ['https://Example.org/foo', 'example.org:443'],
            ['https://example.org:8443/foo', 'example.org:8443'],
            ['http://example.org/foo', null],
            ['file:///tmp/foo', null],
        ];
    }

    public function testCreateException(): void
    {
        $e = TlsChainDenylist::createException('https://user:pass@example.org/', 'Foo CA', true);
        self::assertStringContainsString('"Foo CA"', $e->getMessage());
        self::assertStringContainsString('should be rotated', $e->getMessage());
        self::assertStringNotContainsString('pass@', $e->getMessage());

        self::assertStringContainsString('aborted before it was sent', TlsChainDenylist::createException('https://example.org/', 'Foo CA', false)->getMessage());
    }
}
