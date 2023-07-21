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

namespace Composer\Test\Repository;

use Composer\Composer;
use Composer\Package\Link;
use Composer\Package\PackageInterface;
use Composer\Repository\PlatformRepository;
use Composer\Test\TestCase;
use PHPUnit\Framework\Assert;

class PlatformRepositoryTest extends TestCase
{
    public function testHhvmPackage()
    {
        $hhvmDetector = $this->getMockBuilder('Composer\Platform\HhvmDetector')->getMock();
        $platformRepository = new PlatformRepository(array(), array(), null, $hhvmDetector);

        $hhvmDetector
            ->method('getVersion')
            ->willReturn('2.1.0');

        $hhvm = $platformRepository->findPackage('hhvm', '*');
        self::assertNotNull($hhvm, 'hhvm found');

        self::assertSame('2.1.0', $hhvm->getPrettyVersion());
    }

    public function providePhpFlavorTestCases()
    {
        return array(
            array(
                array(
                    'PHP_VERSION' => '7.1.33',
                ),
                array(
                    'php' => '7.1.33',
                ),
            ),
            array(
                array(
                    'PHP_VERSION' => '7.2.31-1+ubuntu16.04.1+deb.sury.org+1',
                    'PHP_DEBUG' => true,
                ),
                array(
                    'php' => '7.2.31',
                    'php-debug' => '7.2.31',
                ),
            ),
            array(
                array(
                    'PHP_VERSION' => '7.2.31-1+ubuntu16.04.1+deb.sury.org+1',
                    'PHP_ZTS' => true,
                ),
                array(
                    'php' => '7.2.31',
                    'php-zts' => '7.2.31',
                ),
            ),
            array(
                array(
                    'PHP_VERSION' => '7.2.31-1+ubuntu16.04.1+deb.sury.org+1',
                    'PHP_INT_SIZE' => 8,
                ),
                array(
                    'php' => '7.2.31',
                    'php-64bit' => '7.2.31',
                ),
            ),
            array(
                array(
                    'PHP_VERSION' => '7.2.31-1+ubuntu16.04.1+deb.sury.org+1',
                    'AF_INET6' => 30,
                ),
                array(
                    'php' => '7.2.31',
                    'php-ipv6' => '7.2.31',
                ),
            ),
            array(
                array(
                    'PHP_VERSION' => '7.2.31-1+ubuntu16.04.1+deb.sury.org+1',
                ),
                array(
                    'php' => '7.2.31',
                    'php-ipv6' => '7.2.31',
                ),
                array(
                    array('inet_pton', array('::'), ''),
                ),
            ),
            array(
                array(
                    'PHP_VERSION' => '7.2.31-1+ubuntu16.04.1+deb.sury.org+1',
                ),
                array(
                    'php' => '7.2.31',
                ),
                array(
                    array('inet_pton', array('::'), false),
                ),
            ),
        );
    }

    /**
     * @dataProvider providePhpFlavorTestCases
     *
     * @param array<string, mixed>  $constants
     * @param array<string, string> $packages
     * @param array<string, mixed>  $functions
     */
    public function testPhpVersion(array $constants, array $packages, array $functions = array())
    {
        $runtime = $this->getMockBuilder('Composer\Platform\Runtime')->getMock();
        $runtime
            ->method('getExtensions')
            ->willReturn(array());
        $runtime
            ->method('hasConstant')
            ->willReturnMap(
                array_map(function ($constant) {
                    return array($constant, null, true);
                }, array_keys($constants))
            );
        $runtime
            ->method('getConstant')
            ->willReturnMap(
                array_map(function ($constant, $value) {
                    return array($constant, null, $value);
                }, array_keys($constants), array_values($constants))
            );
        $runtime
            ->method('invoke')
            ->willReturnMap($functions);

        $repository = new PlatformRepository(array(), array(), $runtime);
        foreach ($packages as $packageName => $version) {
            $package = $repository->findPackage($packageName, '*');
            self::assertNotNull($package, sprintf('Expected to find package "%s"', $packageName));
            self::assertSame($version, $package->getPrettyVersion(), sprintf('Expected package "%s" version to be %s, got %s', $packageName, $version, $package->getPrettyVersion()));
        }
    }

    public function testInetPtonRegression()
    {
        $runtime = $this->getMockBuilder('Composer\Platform\Runtime')->getMock();

        $runtime
            ->expects(self::once())
            ->method('invoke')
            ->with('inet_pton', array('::'))
            ->willReturn(false);
        $runtime
            ->method('hasConstant')
            ->willReturnMap(
                array(
                    array('PHP_ZTS', false),
                    array('AF_INET6', false),
                )
            );
        $runtime
            ->method('getExtensions')
            ->willReturn(array());
        $runtime
            ->method('getConstant')
            ->willReturnMap(
                array(
                    array('PHP_VERSION', null, '7.0.0'),
                    array('PHP_DEBUG', null, false),
                )
            );
        $repository = new PlatformRepository(array(), array(), $runtime);
        $package = $repository->findPackage('php-ipv6', '*');
        self::assertNull($package);
    }

    public static function provideLibraryTestCases()
    {
        return array(
            'amqp' => array(
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
                array(
                    'lib-amqp-protocol' => '0.9.1',
                    'lib-amqp-librabbitmq' => '0.9.0',
                ),
            ),
            'bz2' => array(
                'bz2',
                '
bz2

BZip2 Support => Enabled
Stream Wrapper support => compress.bzip2://
Stream Filter support => bzip2.decompress, bzip2.compress
BZip2 Version => 1.0.5, 6-Sept-2010',
                array('lib-bz2' => '1.0.5'),
            ),
            'curl' => array(
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
                array(
                    'lib-curl' => '2.0.0',
                    'lib-curl-openssl' => '1.0.1.20',
                    'lib-curl-zlib' => '1.2.8',
                    'lib-curl-libssh2' => '1.4.3',
                ),
                array(array('curl_version', array(), array('version' => '2.0.0'))),
            ),

            'curl: OpenSSL fips version' => array(
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
                array(
                    'lib-curl' => '2.0.0',
                    'lib-curl-openssl-fips' => array('1.0.1.20', array(), array('lib-curl-openssl')),
                    'lib-curl-zlib' => '1.2.8',
                    'lib-curl-libssh2' => '1.4.3',
                ),
                array(array('curl_version', array(), array('version' => '2.0.0'))),
            ),
            'curl: gnutls' => array(
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
                array(
                    'lib-curl' => '7.22.0',
                    'lib-curl-zlib' => '1.2.3.4',
                    'lib-curl-gnutls' => array('2.12.14', array('lib-curl-openssl')),
                ),
                array(array('curl_version', array(), array('version' => '7.22.0'))),
            ),
            'curl: NSS' => array(
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
                array(
                    'lib-curl' => '7.24.0',
                    'lib-curl-nss' => array('3.13.3.0', array('lib-curl-openssl')),
                    'lib-curl-zlib' => '1.2.5',
                    'lib-curl-libssh2' => '1.4.1',
                ),
                array(array('curl_version', array(), array('version' => '7.24.0'))),
            ),
            'curl: libssh not libssh2' => array(
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
                array(
                    'lib-curl' => '7.68.0',
                    'lib-curl-openssl' => '1.1.1.7',
                    'lib-curl-zlib' => '1.2.11',
                    'lib-curl-libssh' => '0.9.3',
                ),
                array(array('curl_version', array(), array('version' => '7.68.0'))),
            ),
            'date' => array(
                'date',
                '
date

date/time support => enabled
timelib version => 2018.03
"Olson" Timezone Database Version => 2020.1
Timezone Database => external
Default timezone => Europe/Berlin',
                array(
                    'lib-date-timelib' => '2018.03',
                    'lib-date-zoneinfo' => '2020.1',
                ),
            ),
            'date: before timelib was extracted' => array(
                'date',
                '
date

date/time support => enabled
"Olson" Timezone Database Version => 2013.2
Timezone Database => internal
Default timezone => Europe/Amsterdam',
                array(
                    'lib-date-zoneinfo' => '2013.2',
                    'lib-date-timelib' => false,
                ),
            ),
            'date: internal zoneinfo' => array(
                array('date', 'timezonedb'),
                '
date

date/time support => enabled
"Olson" Timezone Database Version => 2020.1
Timezone Database => internal
Default timezone => UTC',
                array('lib-date-zoneinfo' => '2020.1'),
            ),
            'date: external zoneinfo' => array(
                array('date', 'timezonedb'),
                '
date

date/time support => enabled
"Olson" Timezone Database Version => 2020.1
Timezone Database => external
Default timezone => UTC',
                array('lib-timezonedb-zoneinfo' => array('2020.1', array('lib-date-zoneinfo'))),
            ),
            'date: zoneinfo 0.system' => array(
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
                array(
                    'lib-date-zoneinfo' => '0',
                    'lib-date-timelib' => '2018.03',
                ),
            ),
            'fileinfo' => array(
                'fileinfo',
                '
fileinfo

fileinfo support => enabled
libmagic => 537',
                array('lib-fileinfo-libmagic' => '537'),
            ),
            'gd' => array(
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
                array(
                    'lib-gd' => '1.2.3',
                    'lib-gd-freetype' => '2.10.0',
                    'lib-gd-libjpeg' => '9.0',
                    'lib-gd-libpng' => '1.6.34',
                ),
                array(),
                array(array('GD_VERSION', null, '1.2.3')),
            ),
            'gd: libjpeg version variation' => array(
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
                array(
                    'lib-gd' => '1.2.3',
                    'lib-gd-freetype' => '2.9.1',
                    'lib-gd-libjpeg' => '6.2',
                    'lib-gd-libpng' => '1.6.35',
                ),
                array(),
                array(array('GD_VERSION', null, '1.2.3')),
            ),
            'gd: libxpm' => array(
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
                array(
                    'lib-gd' => '2.2.5',
                    'lib-gd-freetype' => '2.6.3',
                    'lib-gd-libjpeg' => '6.2',
                    'lib-gd-libpng' => '1.6.28',
                    'lib-gd-libxpm' => '3.4.11',
                ),
                array(),
                array(array('GD_VERSION', null, '2.2.5')),
            ),
            'iconv' => array(
                'iconv',
                null,
                array('lib-iconv' => '1.2.4'),
                array(),
                array(array('ICONV_VERSION', null, '1.2.4')),
            ),
            'gmp' => array(
                'gmp',
                null,
                array('lib-gmp' => '6.1.0'),
                array(),
                array(array('GMP_VERSION', null, '6.1.0')),
            ),
            'intl' => array(
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
                array(
                    'lib-icu' => '100',
                    'lib-icu-cldr' => ResourceBundleStub::STUB_VERSION,
                    'lib-icu-unicode' => '7.0.0',
                    'lib-icu-zoneinfo' => '2016.2',
                ),
                array(
                    array(array('ResourceBundle', 'create'), array('root', 'ICUDATA', false), new ResourceBundleStub()),
                    array(array('IntlChar', 'getUnicodeVersion'), array(), array(7, 0, 0, 0)),
                ),
                array(array('INTL_ICU_VERSION', null, '100')),
                array(
                    array('ResourceBundle'),
                    array('IntlChar'),
                ),
            ),
            'intl: INTL_ICU_VERSION not defined' => array(
                'intl',
                '
intl

Internationalization support => enabled
version => 1.1.0
ICU version => 57.1
ICU Data version => 57.1',
                array('lib-icu' => '57.1'),
            ),
            'imagick: 6.x' => array(
                'imagick',
                null,
                array('lib-imagick-imagemagick' => array('6.2.9', array('lib-imagick'))),
                array(),
                array(),
                array(array('Imagick', array(), new ImagickStub('ImageMagick 6.2.9 Q16 x86_64 2018-05-18 http://www.imagemagick.org'))),
            ),
            'imagick: 7.x' => array(
                'imagick',
                null,
                array('lib-imagick-imagemagick' => array('7.0.8.34', array('lib-imagick'))),
                array(),
                array(),
                array(array('Imagick', array(), new ImagickStub('ImageMagick 7.0.8-34 Q16 x86_64 2019-03-23 https://imagemagick.org'))),
            ),
            'ldap' => array(
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
                array('lib-ldap-openldap' => '2.4.50'),
            ),
            'libxml' => array(
                'libxml',
                null,
                array('lib-libxml' => '2.1.5'),
                array(),
                array(array('LIBXML_DOTTED_VERSION', null, '2.1.5')),
            ),
            'libxml: related extensions' => array(
                array('libxml', 'dom', 'simplexml', 'xml', 'xmlreader', 'xmlwriter'),
                null,
                array('lib-libxml' => array('2.1.5', array(), array('lib-dom-libxml', 'lib-simplexml-libxml', 'lib-xml-libxml', 'lib-xmlreader-libxml', 'lib-xmlwriter-libxml'))),
                array(),
                array(array('LIBXML_DOTTED_VERSION', null, '2.1.5')),
            ),
            'mbstring' => array(
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
                array(
                    'lib-mbstring-libmbfl' => '1.3.2',
                    'lib-mbstring-oniguruma' => '7.0.0',
                ),
                array(),
                array(array('MB_ONIGURUMA_VERSION', null, '7.0.0')),
            ),
            'mbstring: no MB_ONIGURUMA constant' => array(
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
                array(
                    'lib-mbstring-libmbfl' => '1.3.2',
                    'lib-mbstring-oniguruma' => '6.1.3',
                ),
            ),
            'mbstring: no MB_ONIGURUMA constant <7.40' => array(
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
                array(
                    'lib-mbstring-libmbfl' => '1.3.2',
                    'lib-mbstring-oniguruma' => '6.9.4',
                ),
            ),
            'memcached' => array(
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
                array('lib-memcached-libmemcached' => '1.0.18'),
            ),
            'openssl' => array(
                'openssl',
                null,
                array('lib-openssl' => '1.1.1.7'),
                array(),
                array(array('OPENSSL_VERSION_TEXT', null, 'OpenSSL 1.1.1g  21 Apr 2020')),
            ),
            'openssl: distro peculiarities' => array(
                'openssl',
                null,
                array('lib-openssl' => '1.1.1.7'),
                array(),
                array(array('OPENSSL_VERSION_TEXT', null, 'OpenSSL 1.1.1g-freebsd  21 Apr 2020')),
            ),
            'openssl: two letters suffix' => array(
                'openssl',
                null,
                array('lib-openssl' => '0.9.8.33'),
                array(),
                array(array('OPENSSL_VERSION_TEXT', null, 'OpenSSL 0.9.8zg  21 Apr 2020')),
            ),
            'openssl: pre release is treated as alpha' => array(
                'openssl',
                null,
                array('lib-openssl' => '1.1.1.7-alpha1'),
                array(),
                array(array('OPENSSL_VERSION_TEXT', null, 'OpenSSL 1.1.1g-pre1  21 Apr 2020')),
            ),
            'openssl: beta release' => array(
                'openssl',
                null,
                array('lib-openssl' => '1.1.1.7-beta2'),
                array(),
                array(array('OPENSSL_VERSION_TEXT', null, 'OpenSSL 1.1.1g-beta2  21 Apr 2020')),
            ),
            'openssl: alpha release' => array(
                'openssl',
                null,
                array('lib-openssl' => '1.1.1.7-alpha4'),
                array(),
                array(array('OPENSSL_VERSION_TEXT', null, 'OpenSSL 1.1.1g-alpha4  21 Apr 2020')),
            ),
            'openssl: rc release' => array(
                'openssl',
                null,
                array('lib-openssl' => '1.1.1.7-rc2'),
                array(),
                array(array('OPENSSL_VERSION_TEXT', null, 'OpenSSL 1.1.1g-rc2  21 Apr 2020')),
            ),
            'openssl: fips' => array(
                'openssl',
                null,
                array('lib-openssl-fips' => array('1.1.1.7', array(), array('lib-openssl'))),
                array(),
                array(array('OPENSSL_VERSION_TEXT', null, 'OpenSSL 1.1.1g-fips  21 Apr 2020')),
            ),
            'openssl: LibreSSL' => array(
                'openssl',
                null,
                array('lib-openssl' => '2.0.1.0'),
                array(),
                array(array('OPENSSL_VERSION_TEXT', null, 'LibreSSL 2.0.1')),
            ),
            'mysqlnd' => array(
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
                array('lib-mysqlnd-mysqlnd' => '5.0.11-dev'),
            ),
            'pdo_mysql' => array(
                'pdo_mysql',
                '
                pdo_mysql

PDO Driver for MySQL => enabled
Client API version => mysqlnd 5.0.10-dev - 20150407 - $Id: 38fea24f2847fa7519001be390c98ae0acafe387 $

Directive => Local Value => Master Value
pdo_mysql.default_socket => /tmp/mysql.sock => /tmp/mysql.sock',
                array('lib-pdo_mysql-mysqlnd' => '5.0.10-dev'),
            ),
            'mongodb' => array(
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
                array(
                    'lib-mongodb-libmongoc' => '1.15.2',
                    'lib-mongodb-libbson' => '1.15.2',
                ),
            ),
            'pcre' => array(
                'pcre',
                '
pcre

PCRE (Perl Compatible Regular Expressions) Support => enabled
PCRE Library Version => 10.33 2019-04-16
PCRE Unicode Version => 11.0.0
PCRE JIT Support => enabled
PCRE JIT Target => x86 64bit (little endian + unaligned)',
                array(
                    'lib-pcre' => '10.33',
                    'lib-pcre-unicode' => '11.0.0',
                ),
                array(),
                array(array('PCRE_VERSION', null, '10.33 2019-04-16')),
            ),
            'pcre: no unicode version included' => array(
                'pcre',
                '
pcre

PCRE (Perl Compatible Regular Expressions) Support => enabled
PCRE Library Version => 8.38 2015-11-23

Directive => Local Value => Master Value
pcre.backtrack_limit => 1000000 => 1000000
pcre.recursion_limit => 100000 => 100000
                ',
                array(
                    'lib-pcre' => '8.38',
                ),
                array(),
                array(array('PCRE_VERSION', null, '8.38 2015-11-23')),
            ),
            'pgsql' => array(
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
                array('lib-pgsql-libpq' => '12.2'),
            ),
            'pdo_pgsql' => array(
                'pdo_pgsql',
                '
                pdo_pgsql

PDO Driver for PostgreSQL => enabled
PostgreSQL(libpq) Version => 12.1
Module version => 7.1.33
Revision =>  $Id: 9c5f356c77143981d2e905e276e439501fe0f419 $',
                array('lib-pdo_pgsql-libpq' => '12.1'),
            ),
            'libsodium' => array(
                'libsodium',
                null,
                array('lib-libsodium' => '1.0.17'),
                array(),
                array(array('SODIUM_LIBRARY_VERSION', null, '1.0.17')),
            ),
            'libsodium: different extension name' => array(
                'sodium',
                null,
                array('lib-libsodium' => '1.0.15'),
                array(),
                array(array('SODIUM_LIBRARY_VERSION', null, '1.0.15')),
            ),
            'pdo_sqlite' => array(
                'pdo_sqlite',
                '
pdo_sqlite

PDO Driver for SQLite 3.x => enabled
SQLite Library => 3.32.3
                ',
                array('lib-pdo_sqlite-sqlite' => '3.32.3'),
            ),
            'sqlite3' => array(
                'sqlite3',
                '
sqlite3

SQLite3 support => enabled
SQLite3 module version => 7.1.33
SQLite Library => 3.31.0

Directive => Local Value => Master Value
sqlite3.extension_dir => no value => no value
sqlite3.defensive => 1 => 1',
                array('lib-sqlite3-sqlite' => '3.31.0'),
            ),
            'ssh2' => array(
                'ssh2',
                '
ssh2

SSH2 support => enabled
extension version => 1.2
libssh2 version => 1.8.0
banner => SSH-2.0-libssh2_1.8.0',
                array('lib-ssh2-libssh2' => '1.8.0'),
            ),
            'yaml' => array(
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
                array('lib-yaml-libyaml' => '0.2.2'),
            ),
            'xsl' => array(
                'xsl',
                '
xsl

XSL => enabled
libxslt Version => 1.1.33
libxslt compiled against libxml Version => 2.9.8
EXSLT => enabled
libexslt Version => 1.1.29',
                array(
                    'lib-libxslt' => array('1.1.29', array('lib-xsl')),
                    'lib-libxslt-libxml' => '2.9.8',
                ),
                array(),
                array(array('LIBXSLT_DOTTED_VERSION', null, '1.1.29')),
            ),
            'zip' => array(
                'zip',
                null,
                array('lib-zip-libzip' => array('1.5.0', array('lib-zip'))),
                array(),
                array(array('LIBZIP_VERSION', 'ZipArchive', '1.5.0')),
            ),
            'zlib' => array(
                'zlib',
                null,
                array('lib-zlib' => '1.2.10'),
                array(),
                array(array('ZLIB_VERSION', null, '1.2.10')),
            ),
            'zlib: no constant present' => array(
                'zlib',
                '
zlib

ZLib Support => enabled
Stream Wrapper => compress.zlib://
Stream Filter => zlib.inflate, zlib.deflate
Compiled Version => 1.2.8
Linked Version => 1.2.11',
                array('lib-zlib' => '1.2.11'),
            ),
        );
    }

    /**
     * @dataProvider provideLibraryTestCases
     *
     * @param string|string[]            $extensions
     * @param string|null                $info
     * @param array<string,string|false> $expectations
     * @param array<string,mixed>        $functions
     * @param array<string,mixed>        $constants
     * @param array<string,class-string> $classDefinitions
     */
    public function testLibraryInformation(
        $extensions,
        $info,
        array $expectations,
        array $functions = array(),
        array $constants = array(),
        array $classDefinitions = array()
    ) {
        $extensions = (array) $extensions;

        $extensionVersion = '100.200.300';

        $runtime = $this->getMockBuilder('Composer\Platform\Runtime')->getMock();
        $runtime
            ->method('getExtensions')
            ->willReturn($extensions);

        $runtime
            ->method('getExtensionVersion')
            ->willReturnMap(
                array_map(function ($extension) use ($extensionVersion) {
                    return array($extension, $extensionVersion);
                }, $extensions)
            );

        $runtime
            ->method('getExtensionInfo')
            ->willReturnMap(
                array_map(function ($extension) use ($info) {
                    return array($extension, $info);
                }, $extensions)
            );

        $runtime
            ->method('invoke')
            ->willReturnMap($functions);

        $constants[] = array('PHP_VERSION', null, '7.1.0');
        $runtime
            ->method('hasConstant')
            ->willReturnMap(
                array_map(
                    function ($constantDefintion) {
                        return array($constantDefintion[0], $constantDefintion[1], true);
                    },
                    $constants
                )
            );
        $runtime
            ->method('getConstant')
            ->willReturnMap($constants);

        $runtime
            ->method('hasClass')
            ->willReturnMap(
                array_map(
                    function ($classDefinition) {
                        return array($classDefinition[0], true);
                    },
                    $classDefinitions
                )
            );
        $runtime
            ->method('construct')
            ->willReturnMap($classDefinitions);

        $platformRepository = new PlatformRepository(array(), array(), $runtime);

        $expectations = array_map(function ($expectation) {
            return array_replace(array(null, array(), array()), (array) $expectation);
        }, $expectations);

        $libraries = array_map(
            function ($package) {
                return $package['name'];
            },
            array_filter(
                $platformRepository->search('lib', PlatformRepository::SEARCH_NAME),
                function ($package) {
                    return strpos($package['name'], 'lib-') === 0;
                }
            )
        );
        $expectedLibraries = array_merge(array_keys(array_filter($expectations, function ($expectation) {
            return $expectation[0] !== false;
        })));
        self::assertCount(count(array_filter($expectedLibraries)), $libraries, sprintf('Expected: %s, got %s', var_export($expectedLibraries, true), var_export($libraries, true)));

        $expectations = array_merge($expectations, array_combine(array_map(function ($extension) {
            return 'ext-'.$extension;
        }, $extensions), array_fill(0, count($extensions), array($extensionVersion, array(), array()))));

        foreach ($expectations as $packageName => $expectation) {
            list($expectedVersion, $expectedReplaces, $expectedProvides) = $expectation;

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
     * @param string           $context
     * @param string[]         $expectedLinks
     * @param Link[]           $links
     *
     * @return void
     */
    private function assertPackageLinks($context, array $expectedLinks, PackageInterface $sourcePackage, array $links)
    {
        self::assertCount(count($expectedLinks), $links, sprintf('%s: expected package count to match', $context));

        foreach ($links as $link) {
            self::assertSame($sourcePackage->getName(), $link->getSource());
            self::assertContains($link->getTarget(), $expectedLinks, sprintf('%s: package %s not in %s', $context, $link->getTarget(), var_export($expectedLinks, true)));
            self::assertTrue($link->getConstraint()->matches($this->getVersionConstraint('=', $sourcePackage->getVersion())));
        }
    }

    public function testComposerPlatformVersion()
    {
        $runtime = $this->getMockBuilder('Composer\Platform\Runtime')->getMock();
        $runtime
            ->method('getExtensions')
            ->willReturn(array());
        $runtime
            ->method('getConstant')
            ->willReturnMap(
                array(
                    array('PHP_VERSION', null, '7.0.0'),
                    array('PHP_DEBUG', null, false),
                )
            );

        $platformRepository = new PlatformRepository(array(), array(), $runtime);

        $package = $platformRepository->findPackage('composer', '='.Composer::getVersion());
        self::assertNotNull($package, 'Composer package exists');
    }

    public static function providePlatformPackages()
    {
        return array(
            array('php', true),
            array('php-debug', true),
            array('php-ipv6', true),
            array('php-64bit', true),
            array('php-zts', true),
            array('hhvm', true),
            array('hhvm-foo', false),
            array('ext-foo', true),
            array('ext-123', true),
            array('extfoo', false),
            array('ext', false),
            array('lib-foo', true),
            array('lib-123', true),
            array('libfoo', false),
            array('lib', false),
            array('composer', true),
            array('composer-foo', false),
            array('composer-plugin-api', true),
            array('composer-plugin', false),
            array('composer-runtime-api', true),
            array('composer-runtime', false),
        );
    }

    /**
     * @param string $packageName
     * @param bool $expectation
     * @dataProvider providePlatformPackages
     */
    public function testValidPlatformPackages($packageName, $expectation)
    {
        self::assertSame($expectation, PlatformRepository::isPlatformPackage($packageName));
    }
}

class ResourceBundleStub
{
    const STUB_VERSION = '32.0.1';

    /**
     * @param string $locale
     * @param string $bundleName
     * @param bool   $fallback
     *
     * @return ResourceBundleStub
     */
    public static function create($locale, $bundleName, $fallback)
    {
        Assert::assertSame(3, func_num_args());
        Assert::assertSame('root', $locale);
        Assert::assertSame('ICUDATA', $bundleName);
        Assert::assertFalse($fallback);

        return new self();
    }

    /**
     * @param string|int $field
     *
     * @return string
     */
    public function get($field)
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

    /**
     * @param string $versionString
     */
    public function __construct($versionString)
    {
        $this->versionString = $versionString;
    }

    /**
     * @return array<string, string>
     * @phpstan-return array{versionString: string}
     */
    public function getVersion()
    {
        Assert::assertSame(0, func_num_args());

        return array('versionString' => $this->versionString);
    }
}
