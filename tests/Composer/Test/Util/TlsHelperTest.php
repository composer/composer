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

namespace Composer\Test\Util;

use Composer\Util\TlsHelper;
use Composer\Test\TestCase;

class TlsHelperTest extends TestCase
{
    /**
     * @dataProvider dataCheckCertificateHost
     *
     * @param string[] $certNames
     */
    public function testCheckCertificateHost(bool $expectedResult, string $hostname, array $certNames): void
    {
        $certificate['subject']['commonName'] = $expectedCn = array_shift($certNames);
        $certificate['extensions']['subjectAltName'] = $certNames ? 'DNS:'.implode(',DNS:', $certNames) : '';

        // @phpstan-ignore-next-line
        $result = TlsHelper::checkCertificateHost($certificate, $hostname, $foundCn);

        if (true === $expectedResult) {
            $this->assertTrue($result);
            $this->assertSame($expectedCn, $foundCn);
        } else {
            $this->assertFalse($result);
            $this->assertNull($foundCn);
        }
    }

    public function dataCheckCertificateHost(): array
    {
        return [
            [true, 'getcomposer.org', ['getcomposer.org']],
            [true, 'getcomposer.org', ['getcomposer.org', 'packagist.org']],
            [true, 'getcomposer.org', ['packagist.org', 'getcomposer.org']],
            [true, 'foo.getcomposer.org', ['*.getcomposer.org']],
            [false, 'xyz.foo.getcomposer.org', ['*.getcomposer.org']],
            [true, 'foo.getcomposer.org', ['getcomposer.org', '*.getcomposer.org']],
            [true, 'foo.getcomposer.org', ['foo.getcomposer.org', 'foo*.getcomposer.org']],
            [true, 'foo1.getcomposer.org', ['foo.getcomposer.org', 'foo*.getcomposer.org']],
            [true, 'foo2.getcomposer.org', ['foo.getcomposer.org', 'foo*.getcomposer.org']],
            [false, 'foo2.another.getcomposer.org', ['foo.getcomposer.org', 'foo*.getcomposer.org']],
            [false, 'test.example.net', ['**.example.net', '**.example.net']],
            [false, 'test.example.net', ['t*t.example.net', 't*t.example.net']],
            [false, 'xyz.example.org', ['*z.example.org', '*z.example.org']],
            [false, 'foo.bar.example.com', ['foo.*.example.com', 'foo.*.example.com']],
            [false, 'example.com', ['example.*', 'example.*']],
            [true, 'localhost', ['localhost']],
            [false, 'localhost', ['*']],
            [false, 'localhost', ['local*']],
            [false, 'example.net', ['*.net', '*.org', 'ex*.net']],
            [true, 'example.net', ['*.net', '*.org', 'example.net']],
        ];
    }

    public function testGetCertificateNames(): void
    {
        $certificate['subject']['commonName'] = 'example.net';
        $certificate['extensions']['subjectAltName'] = 'DNS: example.com, IP: 127.0.0.1, DNS: getcomposer.org, Junk: blah, DNS: composer.example.org';

        // @phpstan-ignore-next-line
        $names = TlsHelper::getCertificateNames($certificate);

        $this->assertSame('example.net', $names['cn']);
        $this->assertSame([
            'example.com',
            'getcomposer.org',
            'composer.example.org',
        ], $names['san']);
    }
}
