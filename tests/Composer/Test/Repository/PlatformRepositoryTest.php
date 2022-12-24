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

namespace Composer\Test\Repository;

use Composer\Composer;
use Composer\Package\Link;
use Composer\Package\PackageInterface;
use Composer\Repository\PlatformRepository;
use Composer\Test\TestCase;
use PHPUnit\Framework\Assert;

class PlatformRepositoryTest extends TestCase
{
    public function testHhvmPackage(): void
    {
        $hhvmDetector = $this->getMockBuilder('Composer\Platform\HhvmDetector')->getMock();
        $platformRepository = new PlatformRepository([], [], null, $hhvmDetector);

        $hhvmDetector
            ->method('getVersion')
            ->willReturn('2.1.0');

        $hhvm = $platformRepository->findPackage('hhvm', '*');
        self::assertNotNull($hhvm, 'hhvm found');

        self::assertSame('2.1.0', $hhvm->getPrettyVersion());
    }

    public static function providePhpFlavorTestCases(): array
    {
        return [
            [
                [
                    'PHP_VERSION' => '7.1.33',
                ],
                [
                    'php' => '7.1.33',
                ],
            ],
            [
                [
                    'PHP_VERSION' => '7.2.31-1+ubuntu16.04.1+deb.sury.org+1',
                    'PHP_DEBUG' => true,
                ],
                [
                    'php' => '7.2.31',
                    'php-debug' => '7.2.31',
                ],
            ],
            [
                [
                    'PHP_VERSION' => '7.2.31-1+ubuntu16.04.1+deb.sury.org+1',
                    'PHP_ZTS' => true,
                ],
                [
                    'php' => '7.2.31',
                    'php-zts' => '7.2.31',
                ],
            ],
            [
                [
                    'PHP_VERSION' => '7.2.31-1+ubuntu16.04.1+deb.sury.org+1',
                    'PHP_INT_SIZE' => 8,
                ],
                [
                    'php' => '7.2.31',
                    'php-64bit' => '7.2.31',
                ],
            ],
            [
                [
                    'PHP_VERSION' => '7.2.31-1+ubuntu16.04.1+deb.sury.org+1',
                    'AF_INET6' => 30,
                ],
                [
                    'php' => '7.2.31',
                    'php-ipv6' => '7.2.31',
                ],
            ],
            [
                [
                    'PHP_VERSION' => '7.2.31-1+ubuntu16.04.1+deb.sury.org+1',
                ],
                [
                    'php' => '7.2.31',
                    'php-ipv6' => '7.2.31',
                ],
                [
                    ['inet_pton', ['::'], ''],
                ],
            ],
            [
                [
                    'PHP_VERSION' => '7.2.31-1+ubuntu16.04.1+deb.sury.org+1',
                ],
                [
                    'php' => '7.2.31',
                ],
                [
                    ['inet_pton', ['::'], false],
                ],
            ],
        ];
    }

    /**
     * @dataProvider providePhpFlavorTestCases
     *
     * @param array<string, mixed>  $constants
     * @param array<string, string> $packages
     * @param list<array{string, list<string>, string|bool}>  $functions
     */
    public function testPhpVersion(array $constants, array $packages, array $functions = []): void
    {
        $runtime = $this->getMockBuilder('Composer\Platform\Runtime')->getMock();
        $runtime
            ->method('getExtensions')
            ->willReturn([]);
        $runtime
            ->method('hasConstant')
            ->willReturnCallback(static function ($constant, $class = null) use ($constants): bool {
                return isset($constants[ltrim($class.'::'.$constant, ':')]);
            });
        $runtime
            ->method('getConstant')
            ->willReturnCallback(static function ($constant, $class = null) use ($constants) {
                return $constants[ltrim($class.'::'.$constant, ':')] ?? null;
            });
        $runtime
            ->method('invoke')
            ->willReturnMap($functions);

        $repository = new PlatformRepository([], [], $runtime);
        foreach ($packages as $packageName => $version) {
            $package = $repository->findPackage($packageName, '*');
            self::assertNotNull($package, sprintf('Expected to find package "%s"', $packageName));
            self::assertSame($version, $package->getPrettyVersion(), sprintf('Expected package "%s" version to be %s, got %s', $packageName, $version, $package->getPrettyVersion()));
        }
    }

    public function testInetPtonRegression(): void
    {
        $runtime = $this->getMockBuilder('Composer\Platform\Runtime')->getMock();

        $runtime
            ->expects(self::once())
            ->method('invoke')
            ->with('inet_pton', ['::'])
            ->willReturn(false);
        $runtime
            ->method('hasConstant')
            ->willReturn(false); // suppressing PHP_ZTS & AF_INET6

        $constants = [
            'PHP_VERSION' => '7.0.0',
            'PHP_DEBUG' => false,
        ];
        $runtime
            ->method('getConstant')
            ->willReturnCallback(static function ($constant, $class = null) use ($constants) {
                return $constants[ltrim($class.'::'.$constant, ':')] ?? null;
            });
        $runtime
            ->method('getExtensions')
            ->willReturn([]);
        $repository = new PlatformRepository([], [], $runtime);
        $package = $repository->findPackage('php-ipv6', '*');
        self::assertNull($package);
    }

    public static function provideLibraryTestCases(): array
    {
        return [
            'amqp' => [
                'amqp',
                '

amqp

Version => 1.9.4
Revision => release
Compiled => Nov 19 2019 @ 08:44:26
AMQP protocol version => 0-9-1
librabbitmq version => 0.9.0
Default max channels per connection => 256
Default max frame size => 131072
Default heartbeats interval => 0',
                [
                    'lib-amqp-protocol' => '0.9.1',
                    'lib-amqp-librabbitmq' => '0.9.0',
                ],
            ],
            'bz2' => [
                'bz2',
                '
bz2

BZip2 Support => Enabled
Stream Wrapper support => compress.bzip2://
Stream Filter support => bzip2.decompress, bzip2.compress
BZip2 Version => 1.0.5, 6-Sept-2010',
                ['lib-bz2' => '1.0.5'],
            ],
            'curl' => [
                'curl',
                '
curl

cURL support => enabled
cURL Information => 7.38.0
Age => 3
Features
AsynchDNS => Yes
CharConv => No
Debug => No
GSS-Negotiate => No
IDN => Yes
IPv6 => Yes
krb4 => No
Largefile => Yes
libz => Yes
NTLM => Yes
NTLMWB => Yes
SPNEGO => Yes
SSL => Yes
SSPI => No
TLS-SRP => Yes
HTTP2 => No
GSSAPI => Yes
Protocols => dict, file, ftp, ftps, gopher, http, https, imap, imaps, ldap, ldaps, pop3, pop3s, rtmp, rtsp, scp, sftp, smtp, smtps, telnet, tftp
Host => x86_64-pc-linux-gnu
SSL Version => OpenSSL/1.0.1t
ZLib Version => 1.2.8
libSSH Version => libssh2/1.4.3

Directive => Local Value => Master Value
curl.cainfo => no value => no value',
                [
                    'lib-curl' => '2.0.0',
                    'lib-curl-openssl' => '1.0.1.20',
                    'lib-curl-zlib' => '1.2.8',
                    'lib-curl-libssh2' => '1.4.3',
                ],
                [['curl_version', [], ['version' => '2.0.0']]],
            ],

            'curl: OpenSSL fips version' => [
                'curl',
                '
curl

cURL support => enabled
cURL Information => 7.38.0
Age => 3
Features
AsynchDNS => Yes
CharConv => No
Debug => No
GSS-Negotiate => No
IDN => Yes
IPv6 => Yes
krb4 => No
Largefile => Yes
libz => Yes
NTLM => Yes
NTLMWB => Yes
SPNEGO => Yes
SSL => Yes
SSPI => No
TLS-SRP => Yes
HTTP2 => No
GSSAPI => Yes
Protocols => dict, file, ftp, ftps, gopher, http, https, imap, imaps, ldap, ldaps, pop3, pop3s, rtmp, rtsp, scp, sftp, smtp, smtps, telnet, tftp
Host => x86_64-pc-linux-gnu
SSL Version => OpenSSL/1.0.1t-fips
ZLib Version => 1.2.8
libSSH Version => libssh2/1.4.3

Directive => Local Value => Master Value
curl.cainfo => no value => no value',
                [
                    'lib-curl' => '2.0.0',
                    'lib-curl-openssl-fips' => ['1.0.1.20', [], ['lib-curl-openssl']],
                    'lib-curl-zlib' => '1.2.8',
                    'lib-curl-libssh2' => '1.4.3',
                ],
                [['curl_version', [], ['version' => '2.0.0']]],
            ],
            'curl: gnutls' => [
                'curl',
                '
curl

cURL support => enabled
cURL Information => 7.22.0
Age => 3
Features
AsynchDNS => No
CharConv => No
Debug => No
GSS-Negotiate => Yes
IDN => Yes
IPv6 => Yes
krb4 => No
Largefile => Yes
libz => Yes
NTLM => Yes
NTLMWB => Yes
SPNEGO => No
SSL => Yes
SSPI => No
TLS-SRP => Yes
Protocols => dict, file, ftp, ftps, gopher, http, https, imap, imaps, ldap, pop3, pop3s, rtmp, rtsp, smtp, smtps, telnet, tftp
Host => x86_64-pc-linux-gnu
SSL Version => GnuTLS/2.12.14
ZLib Version => 1.2.3.4',
                [
                    'lib-curl' => '7.22.0',
                    'lib-curl-zlib' => '1.2.3.4',
                    'lib-curl-gnutls' => ['2.12.14', ['lib-curl-openssl']],
                ],
                [['curl_version', [], ['version' => '7.22.0']]],
            ],
            'curl: NSS' => [
                'curl',
                '
curl

cURL support => enabled
cURL Information => 7.24.0
Age => 3
Features
AsynchDNS => Yes
Debug => No
GSS-Negotiate => Yes
IDN => Yes
IPv6 => Yes
Largefile => Yes
NTLM => Yes
SPNEGO => No
SSL => Yes
SSPI => No
krb4 => No
libz => Yes
CharConv => No
Protocols => dict, file, ftp, ftps, gopher, http, https, imap, imaps, ldap, ldaps, pop3, pop3s, rtsp, scp, sftp, smtp, smtps, telnet, tftp
Host => x86_64-redhat-linux-gnu
SSL Version => NSS/3.13.3.0
ZLib Version => 1.2.5
libSSH Version => libssh2/1.4.1',
                [
                    'lib-curl' => '7.24.0',
                    'lib-curl-nss' => ['3.13.3.0', ['lib-curl-openssl']],
                    'lib-curl-zlib' => '1.2.5',
                    'lib-curl-libssh2' => '1.4.1',
                ],
                [['curl_version', [], ['version' => '7.24.0']]],
            ],
            'curl: libssh not libssh2' => [
                'curl',
                '

curl

cURL support => enabled
cURL Information => 7.68.0
Age => 5
Features
AsynchDNS => Yes
CharConv => No
Debug => No
GSS-Negotiate => No
IDN => Yes
IPv6 => Yes
krb4 => No
Largefile => Yes
libz => Yes
NTLM => Yes
NTLMWB => Yes
SPNEGO => Yes
SSL => Yes
SSPI => No
TLS-SRP => Yes
HTTP2 => Yes
GSSAPI => Yes
KERBEROS5 => Yes
UNIX_SOCKETS => Yes
PSL => Yes
HTTPS_PROXY => Yes
MULTI_SSL => No
BROTLI => Yes
Protocols => dict, file, ftp, ftps, gopher, http, https, imap, imaps, ldap, ldaps, pop3, pop3s, rtmp, rtsp, scp, sftp, smb, smbs, smtp, smtps, telnet, tftp
Host => x86_64-pc-linux-gnu
SSL Version => OpenSSL/1.1.1g
ZLib Version => 1.2.11
libSSH Version => libssh/0.9.3/openssl/zlib',
                [
                    'lib-curl' => '7.68.0',
                    'lib-curl-openssl' => '1.1.1.7',
                    'lib-curl-zlib' => '1.2.11',
                    'lib-curl-libssh' => '0.9.3',
                ],
                [['curl_version', [], ['version' => '7.68.0']]],
            ],
            'date' => [
                'date',
                '
date

date/time support => enabled
timelib version => 2018.03
"Olson" Timezone Database Version => 2020.1
Timezone Database => external
Default timezone => Europe/Berlin',
                [
                    'lib-date-timelib' => '2018.03',
                    'lib-date-zoneinfo' => '2020.1',
                ],
            ],
            'date: before timelib was extracted' => [
                'date',
                '
date

date/time support => enabled
"Olson" Timezone Database Version => 2013.2
Timezone Database => internal
Default timezone => Europe/Amsterdam',
                [
                    'lib-date-zoneinfo' => '2013.2',
                    'lib-date-timelib' => false,
                ],
            ],
            'date: internal zoneinfo' => [
                ['date', 'timezonedb'],
                '
date

date/time support => enabled
"Olson" Timezone Database Version => 2020.1
Timezone Database => internal
Default timezone => UTC',
                ['lib-date-zoneinfo' => '2020.1'],
            ],
            'date: external zoneinfo' => [
                ['date', 'timezonedb'],
                '
date

date/time support => enabled
"Olson" Timezone Database Version => 2020.1
Timezone Database => external
Default timezone => UTC',
                ['lib-timezonedb-zoneinfo' => ['2020.1', ['lib-date-zoneinfo']]],
            ],
            'date: zoneinfo 0.system' => [
                'date',
                '


date/time support => enabled
timelib version => 2018.03
"Olson" Timezone Database Version => 0.system
Timezone Database => internal
Default timezone => Europe/Berlin

Directive => Local Value => Master Value
date.timezone => no value => no value
date.default_latitude => 31.7667 => 31.7667
date.default_longitude => 35.2333 => 35.2333
date.sunset_zenith => 90.583333 => 90.583333
date.sunrise_zenith => 90.583333 => 90.583333',
                [
                    'lib-date-zoneinfo' => '0',
                    'lib-date-timelib' => '2018.03',
                ],
            ],
            'fileinfo' => [
                'fileinfo',
                '
fileinfo

fileinfo support => enabled
libmagic => 537',
                ['lib-fileinfo-libmagic' => '537'],
            ],
            'gd' => [
                'gd',
                '
gd

GD Support => enabled
GD Version => bundled (2.1.0 compatible)
FreeType Support => enabled
FreeType Linkage => with freetype
FreeType Version => 2.10.0
GIF Read Support => enabled
GIF Create Support => enabled
JPEG Support => enabled
libJPEG Version => 9 compatible
PNG Support => enabled
libPNG Version => 1.6.34
WBMP Support => enabled
XBM Support => enabled
WebP Support => enabled

Directive => Local Value => Master Value
gd.jpeg_ignore_warning => 1 => 1',
                [
                    'lib-gd' => '1.2.3',
                    'lib-gd-freetype' => '2.10.0',
                    'lib-gd-libjpeg' => '9.0',
                    'lib-gd-libpng' => '1.6.34',
                ],
                [],
                [['GD_VERSION', null, '1.2.3']],
            ],
            'gd: libjpeg version variation' => [
                'gd',
                '
gd

GD Support => enabled
GD Version => bundled (2.1.0 compatible)
FreeType Support => enabled
FreeType Linkage => with freetype
FreeType Version => 2.9.1
GIF Read Support => enabled
GIF Create Support => enabled
JPEG Support => enabled
libJPEG Version => 6b
PNG Support => enabled
libPNG Version => 1.6.35
WBMP Support => enabled
XBM Support => enabled
WebP Support => enabled

Directive => Local Value => Master Value
gd.jpeg_ignore_warning => 1 => 1',
                [
                    'lib-gd' => '1.2.3',
                    'lib-gd-freetype' => '2.9.1',
                    'lib-gd-libjpeg' => '6.2',
                    'lib-gd-libpng' => '1.6.35',
                ],
                [],
                [['GD_VERSION', null, '1.2.3']],
            ],
            'gd: libxpm' => [
                'gd',
                '
gd

GD Support => enabled
GD headers Version => 2.2.5
GD library Version => 2.2.5
FreeType Support => enabled
FreeType Linkage => with freetype
FreeType Version => 2.6.3
GIF Read Support => enabled
GIF Create Support => enabled
JPEG Support => enabled
libJPEG Version => 6b
PNG Support => enabled
libPNG Version => 1.6.28
WBMP Support => enabled
XPM Support => enabled
libXpm Version => 30411
XBM Support => enabled
WebP Support => enabled

Directive => Local Value => Master Value
gd.jpeg_ignore_warning => 1 => 1',
                [
                    'lib-gd' => '2.2.5',
                    'lib-gd-freetype' => '2.6.3',
                    'lib-gd-libjpeg' => '6.2',
                    'lib-gd-libpng' => '1.6.28',
                    'lib-gd-libxpm' => '3.4.11',
                ],
                [],
                [['GD_VERSION', null, '2.2.5']],
            ],
            'iconv' => [
                'iconv',
                null,
                ['lib-iconv' => '1.2.4'],
                [],
                [['ICONV_VERSION', null, '1.2.4']],
            ],
            'gmp' => [
                'gmp',
                null,
                ['lib-gmp' => '6.1.0'],
                [],
                [['GMP_VERSION', null, '6.1.0']],
            ],
            'intl' => [
                'intl',
                '
intl

Internationalization support => enabled
ICU version => 57.1
ICU Data version => 57.1
ICU TZData version => 2016b
ICU Unicode version => 8.0

Directive => Local Value => Master Value
intl.default_locale => no value => no value
intl.error_level => 0 => 0
intl.use_exceptions => 0 => 0',
                [
                    'lib-icu' => '100',
                    'lib-icu-cldr' => ResourceBundleStub::STUB_VERSION,
                    'lib-icu-unicode' => '7.0.0',
                    'lib-icu-zoneinfo' => '2016.2',
                ],
                [
                    [['ResourceBundle', 'create'], ['root', 'ICUDATA', false], new ResourceBundleStub()],
                    [['IntlChar', 'getUnicodeVersion'], [], [7, 0, 0, 0]],
                ],
                [['INTL_ICU_VERSION', null, '100']],
                [
                    ['ResourceBundle'],
                    ['IntlChar'],
                ],
            ],
            'intl: INTL_ICU_VERSION not defined' => [
                'intl',
                '
intl

Internationalization support => enabled
version => 1.1.0
ICU version => 57.1
ICU Data version => 57.1',
                ['lib-icu' => '57.1'],
            ],
            'imagick: 6.x' => [
                'imagick',
                null,
                ['lib-imagick-imagemagick' => ['6.2.9', ['lib-imagick']]],
                [],
                [],
                [['Imagick', [], new ImagickStub('ImageMagick 6.2.9 Q16 x86_64 2018-05-18 http://www.imagemagick.org')]],
            ],
            'imagick: 7.x' => [
                'imagick',
                null,
                ['lib-imagick-imagemagick' => ['7.0.8.34', ['lib-imagick']]],
                [],
                [],
                [['Imagick', [], new ImagickStub('ImageMagick 7.0.8-34 Q16 x86_64 2019-03-23 https://imagemagick.org')]],
            ],
            'ldap' => [
                'ldap',
                '
ldap

LDAP Support => enabled
RCS Version => $Id: 5f1913de8e05a346da913956f81e0c0d8991c7cb $
Total Links => 0/unlimited
API Version => 3001
Vendor Name => OpenLDAP
Vendor Version => 20450
SASL Support => Enabled

Directive => Local Value => Master Value
ldap.max_links => Unlimited => Unlimited',
                ['lib-ldap-openldap' => '2.4.50'],
            ],
            'libxml' => [
                'libxml',
                null,
                ['lib-libxml' => '2.1.5'],
                [],
                [['LIBXML_DOTTED_VERSION', null, '2.1.5']],
            ],
            'libxml: related extensions' => [
                ['libxml', 'dom', 'simplexml', 'xml', 'xmlreader', 'xmlwriter'],
                null,
                ['lib-libxml' => ['2.1.5', [], ['lib-dom-libxml', 'lib-simplexml-libxml', 'lib-xml-libxml', 'lib-xmlreader-libxml', 'lib-xmlwriter-libxml']]],
                [],
                [['LIBXML_DOTTED_VERSION', null, '2.1.5']],
            ],
            'mbstring' => [
                'mbstring',
                '
mbstring

Multibyte Support => enabled
Multibyte string engine => libmbfl
HTTP input encoding translation => disabled
libmbfl version => 1.3.2

mbstring extension makes use of "streamable kanji code filter and converter", which is distributed under the GNU Lesser General Public License version 2.1.

Multibyte (japanese) regex support => enabled
Multibyte regex (oniguruma) version => 6.1.3',
                [
                    'lib-mbstring-libmbfl' => '1.3.2',
                    'lib-mbstring-oniguruma' => '7.0.0',
                ],
                [],
                [['MB_ONIGURUMA_VERSION', null, '7.0.0']],
            ],
            'mbstring: no MB_ONIGURUMA constant' => [
                'mbstring',
                '
mbstring

Multibyte Support => enabled
Multibyte string engine => libmbfl
HTTP input encoding translation => disabled
libmbfl version => 1.3.2

mbstring extension makes use of "streamable kanji code filter and converter", which is distributed under the GNU Lesser General Public License version 2.1.

Multibyte (japanese) regex support => enabled
Multibyte regex (oniguruma) version => 6.1.3',
                [
                    'lib-mbstring-libmbfl' => '1.3.2',
                    'lib-mbstring-oniguruma' => '6.1.3',
                ],
            ],
            'mbstring: no MB_ONIGURUMA constant <7.40' => [
                'mbstring',
                '
mbstring

Multibyte Support => enabled
Multibyte string engine => libmbfl
HTTP input encoding translation => disabled
libmbfl version => 1.3.2
oniguruma version => 6.9.4

mbstring extension makes use of "streamable kanji code filter and converter", which is distributed under the GNU Lesser General Public License version 2.1.

Multibyte (japanese) regex support => enabled
Multibyte regex (oniguruma) backtrack check => On',
                [
                    'lib-mbstring-libmbfl' => '1.3.2',
                    'lib-mbstring-oniguruma' => '6.9.4',
                ],
            ],
            'memcached' => [
                'memcached',
                '
memcached

memcached support => enabled
Version => 3.1.5
libmemcached version => 1.0.18
SASL support => yes
Session support => yes
igbinary support => yes
json support => yes
msgpack support => yes',
                ['lib-memcached-libmemcached' => '1.0.18'],
            ],
            'openssl' => [
                'openssl',
                null,
                ['lib-openssl' => '1.1.1.7'],
                [],
                [['OPENSSL_VERSION_TEXT', null, 'OpenSSL 1.1.1g  21 Apr 2020']],
            ],
            'openssl: distro peculiarities' => [
                'openssl',
                null,
                ['lib-openssl' => '1.1.1.7'],
                [],
                [['OPENSSL_VERSION_TEXT', null, 'OpenSSL 1.1.1g-freebsd  21 Apr 2020']],
            ],
            'openssl: two letters suffix' => [
                'openssl',
                null,
                ['lib-openssl' => '0.9.8.33'],
                [],
                [['OPENSSL_VERSION_TEXT', null, 'OpenSSL 0.9.8zg  21 Apr 2020']],
            ],
            'openssl: pre release is treated as alpha' => [
                'openssl',
                null,
                ['lib-openssl' => '1.1.1.7-alpha1'],
                [],
                [['OPENSSL_VERSION_TEXT', null, 'OpenSSL 1.1.1g-pre1  21 Apr 2020']],
            ],
            'openssl: beta release' => [
                'openssl',
                null,
                ['lib-openssl' => '1.1.1.7-beta2'],
                [],
                [['OPENSSL_VERSION_TEXT', null, 'OpenSSL 1.1.1g-beta2  21 Apr 2020']],
            ],
            'openssl: alpha release' => [
                'openssl',
                null,
                ['lib-openssl' => '1.1.1.7-alpha4'],
                [],
                [['OPENSSL_VERSION_TEXT', null, 'OpenSSL 1.1.1g-alpha4  21 Apr 2020']],
            ],
            'openssl: rc release' => [
                'openssl',
                null,
                ['lib-openssl' => '1.1.1.7-rc2'],
                [],
                [['OPENSSL_VERSION_TEXT', null, 'OpenSSL 1.1.1g-rc2  21 Apr 2020']],
            ],
            'openssl: fips' => [
                'openssl',
                null,
                ['lib-openssl-fips' => ['1.1.1.7', [], ['lib-openssl']]],
                [],
                [['OPENSSL_VERSION_TEXT', null, 'OpenSSL 1.1.1g-fips  21 Apr 2020']],
            ],
            'openssl: LibreSSL' => [
                'openssl',
                null,
                ['lib-openssl' => '2.0.1.0'],
                [],
                [['OPENSSL_VERSION_TEXT', null, 'LibreSSL 2.0.1']],
            ],
            'mysqlnd' => [
                'mysqlnd',
                '
                mysqlnd

mysqlnd => enabled
Version => mysqlnd 5.0.11-dev - 20150407 - $Id: 38fea24f2847fa7519001be390c98ae0acafe387 $
Compression => supported
core SSL => supported
extended SSL => supported
Command buffer size => 4096
Read buffer size => 32768
Read timeout => 31536000
Collecting statistics => Yes
Collecting memory statistics => Yes
Tracing => n/a
Loaded plugins => mysqlnd,debug_trace,auth_plugin_mysql_native_password,auth_plugin_mysql_clear_password,auth_plugin_sha256_password
API Extensions => pdo_mysql,mysqli',
                ['lib-mysqlnd-mysqlnd' => '5.0.11-dev'],
            ],
            'pdo_mysql' => [
                'pdo_mysql',
                '
                pdo_mysql

PDO Driver for MySQL => enabled
Client API version => mysqlnd 5.0.10-dev - 20150407 - $Id: 38fea24f2847fa7519001be390c98ae0acafe387 $

Directive => Local Value => Master Value
pdo_mysql.default_socket => /tmp/mysql.sock => /tmp/mysql.sock',
                ['lib-pdo_mysql-mysqlnd' => '5.0.10-dev'],
            ],
            'mongodb' => [
                'mongodb',
                '
                mongodb

MongoDB support => enabled
MongoDB extension version => 1.6.1
MongoDB extension stability => stable
libbson bundled version => 1.15.2
libmongoc bundled version => 1.15.2
libmongoc SSL => enabled
libmongoc SSL library => OpenSSL
libmongoc crypto => enabled
libmongoc crypto library => libcrypto
libmongoc crypto system profile => disabled
libmongoc SASL => disabled
libmongoc ICU => enabled
libmongoc compression => enabled
libmongoc compression snappy => disabled
libmongoc compression zlib => enabled

Directive => Local Value => Master Value
mongodb.debug => no value => no value',
                [
                    'lib-mongodb-libmongoc' => '1.15.2',
                    'lib-mongodb-libbson' => '1.15.2',
                ],
            ],
            'pcre' => [
                'pcre',
                '
pcre

PCRE (Perl Compatible Regular Expressions) Support => enabled
PCRE Library Version => 10.33 2019-04-16
PCRE Unicode Version => 11.0.0
PCRE JIT Support => enabled
PCRE JIT Target => x86 64bit (little endian + unaligned)',
                [
                    'lib-pcre' => '10.33',
                    'lib-pcre-unicode' => '11.0.0',
                ],
                [],
                [['PCRE_VERSION', null, '10.33 2019-04-16']],
            ],
            'pcre: no unicode version included' => [
                'pcre',
                '
pcre

PCRE (Perl Compatible Regular Expressions) Support => enabled
PCRE Library Version => 8.38 2015-11-23

Directive => Local Value => Master Value
pcre.backtrack_limit => 1000000 => 1000000
pcre.recursion_limit => 100000 => 100000
                ',
                [
                    'lib-pcre' => '8.38',
                ],
                [],
                [['PCRE_VERSION', null, '8.38 2015-11-23']],
            ],
            'pgsql' => [
                'pgsql',
                '
pgsql

PostgreSQL Support => enabled
PostgreSQL(libpq) Version => 12.2
PostgreSQL(libpq)  => PostgreSQL 12.3 on x86_64-apple-darwin18.7.0, compiled by Apple clang version 11.0.0 (clang-1100.0.33.17), 64-bit
Multibyte character support => enabled
SSL support => enabled
Active Persistent Links => 0
Active Links => 0

Directive => Local Value => Master Value
pgsql.allow_persistent => On => On
pgsql.max_persistent => Unlimited => Unlimited
pgsql.max_links => Unlimited => Unlimited
pgsql.auto_reset_persistent => Off => Off
pgsql.ignore_notice => Off => Off
pgsql.log_notice => Off => Off',
                ['lib-pgsql-libpq' => '12.2'],
            ],
            'pdo_pgsql' => [
                'pdo_pgsql',
                '
                pdo_pgsql

PDO Driver for PostgreSQL => enabled
PostgreSQL(libpq) Version => 12.1
Module version => 7.1.33
Revision =>  $Id: 9c5f356c77143981d2e905e276e439501fe0f419 $',
                ['lib-pdo_pgsql-libpq' => '12.1'],
            ],
            'libsodium' => [
                'libsodium',
                null,
                ['lib-libsodium' => '1.0.17'],
                [],
                [['SODIUM_LIBRARY_VERSION', null, '1.0.17']],
            ],
            'libsodium: different extension name' => [
                'sodium',
                null,
                ['lib-libsodium' => '1.0.15'],
                [],
                [['SODIUM_LIBRARY_VERSION', null, '1.0.15']],
            ],
            'pdo_sqlite' => [
                'pdo_sqlite',
                '
pdo_sqlite

PDO Driver for SQLite 3.x => enabled
SQLite Library => 3.32.3
                ',
                ['lib-pdo_sqlite-sqlite' => '3.32.3'],
            ],
            'sqlite3' => [
                'sqlite3',
                '
sqlite3

SQLite3 support => enabled
SQLite3 module version => 7.1.33
SQLite Library => 3.31.0

Directive => Local Value => Master Value
sqlite3.extension_dir => no value => no value
sqlite3.defensive => 1 => 1',
                ['lib-sqlite3-sqlite' => '3.31.0'],
            ],
            'ssh2' => [
                'ssh2',
                '
ssh2

SSH2 support => enabled
extension version => 1.2
libssh2 version => 1.8.0
banner => SSH-2.0-libssh2_1.8.0',
                ['lib-ssh2-libssh2' => '1.8.0'],
            ],
            'yaml' => [
                'yaml',
                '
                yaml

LibYAML Support => enabled
Module Version => 2.0.2
LibYAML Version => 0.2.2

Directive => Local Value => Master Value
yaml.decode_binary => 0 => 0
yaml.decode_timestamp => 0 => 0
yaml.decode_php => 0 => 0
yaml.output_canonical => 0 => 0
yaml.output_indent => 2 => 2
yaml.output_width => 80 => 80',
                ['lib-yaml-libyaml' => '0.2.2'],
            ],
            'xsl' => [
                'xsl',
                '
xsl

XSL => enabled
libxslt Version => 1.1.33
libxslt compiled against libxml Version => 2.9.8
EXSLT => enabled
libexslt Version => 1.1.29',
                [
                    'lib-libxslt' => ['1.1.29', ['lib-xsl']],
                    'lib-libxslt-libxml' => '2.9.8',
                ],
                [],
                [['LIBXSLT_DOTTED_VERSION', null, '1.1.29']],
            ],
            'zip' => [
                'zip',
                null,
                ['lib-zip-libzip' => ['1.5.0', ['lib-zip']]],
                [],
                [['LIBZIP_VERSION', 'ZipArchive', '1.5.0']],
            ],
            'zlib' => [
                'zlib',
                null,
                ['lib-zlib' => '1.2.10'],
                [],
                [['ZLIB_VERSION', null, '1.2.10']],
            ],
            'zlib: no constant present' => [
                'zlib',
                '
zlib

ZLib Support => enabled
Stream Wrapper => compress.zlib://
Stream Filter => zlib.inflate, zlib.deflate
Compiled Version => 1.2.8
Linked Version => 1.2.11',
                ['lib-zlib' => '1.2.11'],
            ],
        ];
    }

    /**
     * @dataProvider provideLibraryTestCases
     *
     * @param string|string[]            $extensions
     * @param array<string,string|false|array{string|false, 1?: string[], 2?: string[]}> $expectations array of packageName => expected version (or false if expected to be msising), or packageName => array(expected version, expected replaced names, expected provided names)
     * @param list<mixed>                $functions
     * @param list<mixed>                $constants
     * @param list<mixed>                $classDefinitions
     */
    public function testLibraryInformation(
        $extensions,
        ?string $info,
        array $expectations,
        array $functions = [],
        array $constants = [],
        array $classDefinitions = []
    ): void {
        $extensions = (array) $extensions;

        $extensionVersion = '100.200.300';

        $runtime = $this->getMockBuilder('Composer\Platform\Runtime')->getMock();
        $runtime
            ->method('getExtensions')
            ->willReturn($extensions);

        $runtime
            ->method('getExtensionVersion')
            ->willReturnMap(
                array_map(static function ($extension) use ($extensionVersion): array {
                    return [$extension, $extensionVersion];
                }, $extensions)
            );

        $runtime
            ->method('getExtensionInfo')
            ->willReturnMap(
                array_map(static function ($extension) use ($info): array {
                    return [$extension, $info];
                }, $extensions)
            );

        $runtime
            ->method('invoke')
            ->willReturnMap($functions);

        $constants[] = ['PHP_VERSION', null, '7.1.0'];
        $runtime
            ->method('hasConstant')
            ->willReturnCallback(static function ($constant, $class = null) use ($constants): bool {
                foreach ($constants as $definition) {
                    if ($definition[0] === $constant && $definition[1] === $class) {
                        return true;
                    }
                }

                return false;
            });
        $runtime
            ->method('getConstant')
            ->willReturnMap($constants);

        $runtime
            ->method('hasClass')
            ->willReturnCallback(static function ($class) use ($classDefinitions): bool {
                foreach ($classDefinitions as $definition) {
                    if ($definition[0] === $class) {
                        return true;
                    }
                }

                return false;
            });
        $runtime
            ->method('construct')
            ->willReturnMap($classDefinitions);

        $platformRepository = new PlatformRepository([], [], $runtime);

        $libraries = array_map(
            static function ($package): string {
                return $package['name'];
            },
            array_filter(
                $platformRepository->search('lib', PlatformRepository::SEARCH_NAME),
                static function ($package): bool {
                    return strpos($package['name'], 'lib-') === 0;
                }
            )
        );
        $expectedLibraries = array_keys(array_filter($expectations, static function ($expectation): bool {
            return $expectation !== false;
        }));
        self::assertCount(count(array_filter($expectedLibraries)), $libraries, sprintf('Expected: %s, got %s', var_export($expectedLibraries, true), var_export($libraries, true)));

        foreach ($extensions as $extension) {
            $expectations['ext-'.$extension] = $extensionVersion;
        }

        foreach ($expectations as $expectedLibOrExt => $expectation) {
            $packageName = $expectedLibOrExt;
            if (!is_array($expectation)) {
                $expectation = [$expectation, [], []];
            }
            [$expectedVersion, $expectedReplaces, $expectedProvides] = array_pad($expectation, 3, []);

            $package = $platformRepository->findPackage($packageName, '*');
            if ($expectedVersion === false) {
                self::assertNull($package, sprintf('Expected to not find package "%s"', $packageName));
            } else {
                self::assertNotNull($package, sprintf('Expected to find package "%s"', $packageName));
                self::assertSame($expectedVersion, $package->getPrettyVersion(), sprintf('Expected version %s for %s', $expectedVersion, $packageName));
                $this->assertPackageLinks('replaces', $expectedReplaces, $package, $package->getReplaces());
                $this->assertPackageLinks('provides', $expectedProvides, $package, $package->getProvides());
            }
        }
    }

    /**
     * @param string[]         $expectedLinks
     * @param Link[]           $links
     */
    private function assertPackageLinks(string $context, array $expectedLinks, PackageInterface $sourcePackage, array $links): void
    {
        self::assertCount(count($expectedLinks), $links, sprintf('%s: expected package count to match', $context));

        foreach ($links as $link) {
            self::assertSame($sourcePackage->getName(), $link->getSource());
            self::assertContains($link->getTarget(), $expectedLinks, sprintf('%s: package %s not in %s', $context, $link->getTarget(), var_export($expectedLinks, true)));
            self::assertTrue($link->getConstraint()->matches(self::getVersionConstraint('=', $sourcePackage->getVersion())));
        }
    }

    public function testComposerPlatformVersion(): void
    {
        $runtime = $this->getMockBuilder('Composer\Platform\Runtime')->getMock();
        $runtime
            ->method('getExtensions')
            ->willReturn([]);
        $runtime
            ->method('getConstant')
            ->willReturnMap(
                [
                    ['PHP_VERSION', null, '7.0.0'],
                    ['PHP_DEBUG', null, false],
                ]
            );

        $platformRepository = new PlatformRepository([], [], $runtime);

        $package = $platformRepository->findPackage('composer', '='.Composer::getVersion());
        self::assertNotNull($package, 'Composer package exists');
    }

    public static function providePlatformPackages(): array
    {
        return [
            ['php', true],
            ['php-debug', true],
            ['php-ipv6', true],
            ['php-64bit', true],
            ['php-zts', true],
            ['hhvm', true],
            ['hhvm-foo', false],
            ['ext-foo', true],
            ['ext-123', true],
            ['extfoo', false],
            ['ext', false],
            ['lib-foo', true],
            ['lib-123', true],
            ['libfoo', false],
            ['lib', false],
            ['composer', true],
            ['composer-foo', false],
            ['composer-plugin-api', true],
            ['composer-plugin', false],
            ['composer-runtime-api', true],
            ['composer-runtime', false],
        ];
    }

    /**
     * @dataProvider providePlatformPackages
     */
    public function testValidPlatformPackages(string $packageName, bool $expectation): void
    {
        self::assertSame($expectation, PlatformRepository::isPlatformPackage($packageName));
    }
}

class ResourceBundleStub
{
    public const STUB_VERSION = '32.0.1';

    public static function create(string $locale, string $bundleName, bool $fallback): ResourceBundleStub
    {
        Assert::assertSame(3, func_num_args());
        Assert::assertSame('root', $locale);
        Assert::assertSame('ICUDATA', $bundleName);
        Assert::assertFalse($fallback);

        return new self();
    }

    /**
     * @param string|int $field
     */
    public function get($field): string
    {
        Assert::assertSame(1, func_num_args());
        Assert::assertSame('Version', $field);

        return self::STUB_VERSION;
    }
}

class ImagickStub
{
    /**
     * @var string
     */
    private $versionString;

    public function __construct(string $versionString)
    {
        $this->versionString = $versionString;
    }

    /**
     * @return array<string, string>
     * @phpstan-return array{versionString: string}
     */
    public function getVersion(): array
    {
        Assert::assertSame(0, func_num_args());

        return ['versionString' => $this->versionString];
    }
}
