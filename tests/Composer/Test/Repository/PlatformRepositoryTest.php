<?php

/*
 * This file is part of Composer.
 *
 * (c) Nils Adermann <naderman@naderman.de>
 *         Jordi Boggiano <j.boggiano@seld.be>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Composer\Test\Repository;

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

        self::assertSame('2.1.0.0', $hhvm->getVersion());
    }

    public static function getLibraryTestCases()
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
                )
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
                array(array('curl_version', array(), array('version' => '2.0.0')))
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
                    'lib-curl-openssl-fips' => '1.0.1.20',
                    'lib-curl-zlib' => '1.2.8',
                    'lib-curl-libssh2' => '1.4.3',
                ),
                array(array('curl_version', array(), array('version' => '2.0.0')))
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
                array('lib-date-timelib' => '2018.03')
            ),
            'date: before timelib was extracted' => array(
                'date',
                '
date

date/time support => enabled
"Olson" Timezone Database Version => 2013.2
Timezone Database => internal
Default timezone => Europe/Amsterdam',
                array('lib-date-timelib' => false)
            ),
            'fileinfo' => array(
                'fileinfo',
                '
fileinfo

fileinfo support => enabled
libmagic => 537',
                array('lib-fileinfo-libmagic' => '537')
            ),
            'gd' => array(
                'gd',
                null,
                array('lib-gd' => '1.2.3'),
                array(),
                array(array('GD_VERSION', null, '1.2.3'))
            ),
            'iconv' => array(
                'iconv',
                null,
                array('lib-iconv' => '1.2.4'),
                array(),
                array(array('ICONV_VERSION', null, '1.2.4'))
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
                ),
                array(
                    array(array('ResourceBundle', 'create'), array('root', 'ICUDATA', false), new ResourceBundleStub()),
                    array(array('IntlChar', 'getUnicodeVersion'), array(), array(7, 0, 0, 0)),
                ),
                array(array('INTL_ICU_VERSION', null, '100')),
                array(
                    array('ResourceBundle'),
                    array('IntlChar'),
                )
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
                array('lib-imagick-imagemagick' => '6.2.9'),
                array(),
                array(),
                array(array('Imagick', array(), new ImagickStub('ImageMagick 6.2.9 Q16 x86_64 2018-05-18 http://www.imagemagick.org')))
            ),
            'imagick: 7.x' => array(
                'imagick',
                null,
                array('lib-imagick-imagemagick' => '7.0.8.34'),
                array(),
                array(),
                array(array('Imagick', array(), new ImagickStub('ImageMagick 7.0.8-34 Q16 x86_64 2019-03-23 https://imagemagick.org')))
            ),
            'libxml' => array(
                'libxml',
                null,
                array('lib-libxml' => '2.1.5'),
                array(),
                array(array('LIBXML_DOTTED_VERSION', null, '2.1.5'))
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
                array(array('MB_ONIGURUMA_VERSION', null, '7.0.0'))
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
                )
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
                array('lib-memcached-libmemcached' => '1.0.18')
            ),
            'openssl' => array(
                'openssl',
                null,
                array('lib-openssl' => '1.1.1.7'),
                array(),
                array(array('OPENSSL_VERSION_TEXT', null, 'OpenSSL 1.1.1g  21 Apr 2020'))
            ),
            'openssl: two letters suffix' => array(
                'openssl',
                null,
                array('lib-openssl' => '0.9.8.33'),
                array(),
                array(array('OPENSSL_VERSION_TEXT', null, 'OpenSSL 0.9.8zg  21 Apr 2020'))
            ),
            'openssl: pre release is treated as alpha' => array(
                'openssl',
                null,
                array('lib-openssl' => '1.1.1.7-alpha1'),
                array(),
                array(array('OPENSSL_VERSION_TEXT', null, 'OpenSSL 1.1.1g-pre1  21 Apr 2020'))
            ),
            'openssl: beta release' => array(
                'openssl',
                null,
                array('lib-openssl' => '1.1.1.7-beta2'),
                array(),
                array(array('OPENSSL_VERSION_TEXT', null, 'OpenSSL 1.1.1g-beta2  21 Apr 2020'))
            ),
            'openssl: alpha release' => array(
                'openssl',
                null,
                array('lib-openssl' => '1.1.1.7-alpha4'),
                array(),
                array(array('OPENSSL_VERSION_TEXT', null, 'OpenSSL 1.1.1g-alpha4  21 Apr 2020'))
            ),
            'openssl: rc release' => array(
                'openssl',
                null,
                array('lib-openssl' => '1.1.1.7-rc2'),
                array(),
                array(array('OPENSSL_VERSION_TEXT', null, 'OpenSSL 1.1.1g-rc2  21 Apr 2020'))
            ),
            'openssl: fips' => array(
                'openssl',
                null,
                array('lib-openssl-fips' => '1.1.1.7'),
                array(),
                array(array('OPENSSL_VERSION_TEXT', null, 'OpenSSL 1.1.1g-fips  21 Apr 2020'))
            ),
            'openssl: LibreSSL' => array(
                'openssl',
                null,
                array('lib-openssl' => '2.0.1.0'),
                array(),
                array(array('OPENSSL_VERSION_TEXT', null, 'LibreSSL 2.0.1'))
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
                array(array('PCRE_VERSION', null, '10.33 2019-04-16'))
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
                    'lib-pcre-unicode' => false,
                ),
                array(),
                array(array('PCRE_VERSION', null, '8.38 2015-11-23'))
            ),
            'libsodium' => array(
                'libsodium',
                null,
                array('lib-libsodium' => '1.0.17'),
                array(),
                array(array('SODIUM_LIBRARY_VERSION', null, '1.0.17'))
            ),
            'libsodium: different extension name' => array(
                'sodium',
                null,
                array('lib-libsodium' => '1.0.15'),
                array(),
                array(array('SODIUM_LIBRARY_VERSION', null, '1.0.15'))
            ),
            'xsl' => array(
                'xsl',
                null,
                array('lib-libxslt' => '1.1.29'),
                array(),
                array(array('LIBXSLT_DOTTED_VERSION', null, '1.1.29'))
            ),
            'zip' => array(
                'zip',
                null,
                array('lib-zip' => '1.5.0'),
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
     * @dataProvider getLibraryTestCases
     *
     * @param string $extension
     * @param string|null $info
     * @param array<string,string|false> $expectations
     * @param array<string,mixed> $functions
     * @param array<string,mixed> $constants
     * @param array<string,class-string> $classes
     */
    public function testLibraryInformation(
        $extension,
        $info,
        array $expectations,
        array $functions = array(),
        array $constants = array(),
        array $classDefinitions = array()
    )
    {
        $extensionVersion = '100.200.300';

        $runtime = $this->getMockBuilder('Composer\Platform\Runtime')->getMock();
        $runtime
            ->method('getExtensions')
            ->willReturn(array($extension));

        $runtime
            ->method('getExtensionVersion')
            ->willReturn($extensionVersion);

        $runtime
            ->method('getExtensionInfo')
            ->with($extension)
            ->willReturn($info);

        $runtime
            ->method('invoke')
            ->willReturnMap($functions);

        $runtime
            ->method('hasConstant')
            ->willReturnMap(
                array_map(
                    function ($constantDefintion) { return array($constantDefintion[0], $constantDefintion[1], true); },
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
                    function ($classDefinition) { return array($classDefinition[0], true); },
                    $classDefinitions
                )
            );
        $runtime
            ->method('construct')
            ->willReturnMap($classDefinitions);

        $platformRepository = new PlatformRepository(array(), array(), $runtime);

        $expectations['ext-' . $extension] = '100.200.300';
        foreach ($expectations as $packageName => $version) {
            $package = $platformRepository->findPackage($packageName, '*');
            if ($version === false) {
                self::assertNull($package, sprintf('Expected to not find package "%s"', $packageName));
            } else {
                self::assertNotNull($packageName, sprintf('Expected to find package "%s"', $packageName));
                self::assertSame($version, $package->getPrettyVersion(), sprintf('Expected version %s for %s', $version, $packageName));
                foreach ($package->getReplaces() as $link) {
                    self::assertSame($package->getName(), $link->getSource());
                    self::assertTrue($link->getConstraint()->matches($this->getVersionConstraint('=', $package->getVersion())));
                }
            }
        }
    }
}

class ResourceBundleStub {
    const STUB_VERSION = '32.0.1';

    public static function create($locale, $bundleName, $fallback) {
        Assert::assertSame(3, func_num_args());
        Assert::assertSame('root', $locale);
        Assert::assertSame('ICUDATA', $bundleName);
        Assert::assertFalse($fallback);

        return new self();
    }

    public function get($field) {
        Assert::assertSame(1, func_num_args());
        Assert::assertSame('Version', $field);

        return self::STUB_VERSION;
    }
}

class ImagickStub {
    private $versionString;

    public function __construct($versionString) {
        $this->versionString = $versionString;
    }

    public function getVersion() {
        Assert::assertSame(0, func_num_args());

        return array('versionString' => $this->versionString);
    }
}
