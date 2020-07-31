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
use Composer\Semver\Constraint\Constraint;
use Composer\Test\TestCase;
use Composer\Util\ProcessExecutor;
use Composer\Package\Version\VersionParser;
use Composer\Util\Platform;
use PHPUnit\Framework\Assert;
use Symfony\Component\Process\ExecutableFinder;

class PlatformRepositoryTest extends TestCase
{
    public function testHHVMVersionWhenExecutingInHHVM()
    {
        if (!defined('HHVM_VERSION_ID')) {
            $this->markTestSkipped('Not running with HHVM');
            return;
        }
        $repository = new PlatformRepository();
        $package = $repository->findPackage('hhvm', '*');
        $this->assertNotNull($package, 'failed to find HHVM package');
        $this->assertSame(
            sprintf('%d.%d.%d',
                HHVM_VERSION_ID / 10000,
                (HHVM_VERSION_ID / 100) % 100,
                HHVM_VERSION_ID % 100
            ),
            $package->getPrettyVersion()
        );
    }

    public function testHHVMVersionWhenExecutingInPHP()
    {
        if (defined('HHVM_VERSION_ID')) {
            $this->markTestSkipped('Running with HHVM');
            return;
        }
        if (PHP_VERSION_ID < 50400) {
            $this->markTestSkipped('Test only works on PHP 5.4+');
            return;
        }
        if (Platform::isWindows()) {
            $this->markTestSkipped('Test does not run on Windows');
            return;
        }
        $finder = new ExecutableFinder();
        $hhvm = $finder->find('hhvm');
        if ($hhvm === null) {
            $this->markTestSkipped('HHVM is not installed');
        }
        $repository = new PlatformRepository(array(), array());
        $package = $repository->findPackage('hhvm', '*');
        $this->assertNotNull($package, 'failed to find HHVM package');

        $process = new ProcessExecutor();
        $exitCode = $process->execute(
            ProcessExecutor::escape($hhvm).
            ' --php -d hhvm.jit=0 -r "echo HHVM_VERSION;" 2>/dev/null',
            $version
        );
        $parser = new VersionParser;

        $this->assertSame($parser->normalize($version), $package->getVersion());
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
                array('curl_version' => array('version' => '2.0.0'))
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
                array('curl_version' => array('version' => '2.0.0'))
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
            'date: extension before timelib was extracted' => array(
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
                array('GD_VERSION' => '1.2.3')
            ),
            'iconv' => array(
                'iconv',
                null,
                array('lib-iconv' => '1.2.4'),
                array(),
                array('ICONV_VERSION' => '1.2.4')
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
                    'lib-icu-unicode' => IntlCharStub::STUB_VERSION,
                ),
                array(),
                array('INTL_ICU_VERSION' => '100'),
                array(
                    'ResourceBundle' => 'Composer\\Test\\Repository\\ResourceBundleStub',
                    'IntlChar' => 'Composer\\Test\\Repository\\IntlCharStub',
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
                array(),
                array('INTL_ICU_VERSION' => false)
            ),
            'imagick: 6.x' => array(
                'imagick',
                null,
                array('lib-imagick-imagemagick' => Imagick6Stub::STUB_VERSION),
                array(),
                array(),
                array('Imagick' => 'Composer\\Test\\Repository\\Imagick6Stub')
            ),
            'imagick: 7.x' => array(
                'imagick',
                null,
                array('lib-imagick-imagemagick' => Imagick7Stub::STUB_VERSION),
                array(),
                array(),
                array('Imagick' => 'Composer\\Test\\Repository\\Imagick7Stub')
            ),
            'libxml' => array(
                'libxml',
                null,
                array('lib-libxml' => '2.1.5'),
                array(),
                array('LIBXML_DOTTED_VERSION' => '2.1.5')
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
                array('MB_ONIGURUMA_VERSION' => '7.0.0')
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
                array(),
                array('MB_ONIGURUMA_VERSION' => false)
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
                array(),
                array('MB_ONIGURUMA_VERSION' => false)
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
                array('OPENSSL_VERSION_TEXT' => 'OpenSSL 1.1.1g  21 Apr 2020')
            ),
            'openssl: two letters suffix' => array(
                'openssl',
                null,
                array('lib-openssl' => '0.9.8.33'),
                array(),
                array('OPENSSL_VERSION_TEXT' => 'OpenSSL 0.9.8zg  21 Apr 2020')
            ),
            'openssl: pre release is treated as alpha' => array(
                'openssl',
                null,
                array('lib-openssl' => '1.1.1.7-alpha1'),
                array(),
                array('OPENSSL_VERSION_TEXT' => 'OpenSSL 1.1.1g-pre1  21 Apr 2020')
            ),
            'openssl: beta release' => array(
                'openssl',
                null,
                array('lib-openssl' => '1.1.1.7-beta2'),
                array(),
                array('OPENSSL_VERSION_TEXT' => 'OpenSSL 1.1.1g-beta2  21 Apr 2020')
            ),
            'openssl: alpha release' => array(
                'openssl',
                null,
                array('lib-openssl' => '1.1.1.7-alpha4'),
                array(),
                array('OPENSSL_VERSION_TEXT' => 'OpenSSL 1.1.1g-alpha4  21 Apr 2020')
            ),
            'openssl: rc release' => array(
                'openssl',
                null,
                array('lib-openssl' => '1.1.1.7-rc2'),
                array(),
                array('OPENSSL_VERSION_TEXT' => 'OpenSSL 1.1.1g-rc2  21 Apr 2020')
            ),
            'openssl: fips' => array(
                'openssl',
                null,
                array('lib-openssl-fips' => '1.1.1.7'),
                array(),
                array('OPENSSL_VERSION_TEXT' => 'OpenSSL 1.1.1g-fips  21 Apr 2020')
            ),
            'openssl: LibreSSL' => array(
                'openssl',
                null,
                array('lib-openssl' => '2.0.1.0'),
                array(),
                array('OPENSSL_VERSION_TEXT' => 'LibreSSL 2.0.1')
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
                array('PCRE_VERSION' => '10.33 2019-04-16')
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
                array('PCRE_VERSION' => '8.38 2015-11-23')
            ),
            'libsodium' => array(
                'libsodium',
                null,
                array('lib-libsodium' => '1.0.17'),
                array(),
                array('SODIUM_LIBRARY_VERSION' => '1.0.17')
            ),
            'libsodium: different extension name' => array(
                'sodium',
                null,
                array('lib-libsodium' => '1.0.15'),
                array(),
                array('SODIUM_LIBRARY_VERSION' => '1.0.15')
            ),
            'uuid' => array(
                'uuid',
                null,
                array('lib-uuid' => '1.0.4'),
                array('phpversion' => '1.0.4'),
            ),
            'xsl' => array(
                'xsl',
                null,
                array('lib-libxslt' => '1.1.29'),
                array(),
                array('LIBXSLT_DOTTED_VERSION' => '1.1.29')
            ),
            'zip' => array(
                'zip',
                null,
                array('lib-zip' => ZipArchiveStub::LIBZIP_VERSION),
                array(),
                array(),
                array('ZipArchive' => 'Composer\\Test\\Repository\\ZipArchiveStub')
            ),
            'zlib' => array(
                'zlib',
                null,
                array('lib-zlib' => '1.2.10'),
                array(),
                array('ZLIB_VERSION' => '1.2.10'),
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
                array(),
                array('ZLIB_VERSION' => false)
            ),
        );
    }

    /**
     * @dataProvider getLibraryTestCases
     * @runInSeparateProcess
     * @preserveGlobalState
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
        array $classes = array()
    )
    {
        PlatformRepository::setLoadedExtensionsForTesting(array($extension => '100.200.300'));
        if ($info !== null) {
            PlatformRepository::setExtensionInfoForTesting($extension, $info);
        }

        foreach ($functions as $function => $returnValue) {
            eval(sprintf(
                'namespace Composer\Repository { function %s() { return %s; } }',
                $function,
                var_export($returnValue, true)
            ));
        }

        foreach ($constants as $constant => $constantValue) {
            if ($constantValue === false) {
                PlatformRepository::setConstantDefinedForTesting($constant, false);
            } else {
                PlatformRepository::setConstantDefinedForTesting($constant, true);
                eval(sprintf(
                    'namespace Composer\Repository { const %s = %s; }',
                    $constant,
                    var_export($constantValue, true)
                ));
            }
        }

        foreach ($classes as $class => $stub) {
            PlatformRepository::setExtensionClassForTesting($class, $stub);
        }

        $platformRepository = new PlatformRepository();
        $packages = $platformRepository->getPackages();

        $expectations['ext-' . $extension] = '100.200.300';
        foreach ($expectations as $packageName => $version) {
            $foundLibrary = false;
            foreach ($packages as $package) {
                if ($package->getName() === $packageName) {
                    $foundLibrary = true;
                    self::assertSame($version, $package->getPrettyVersion(), sprintf('Expected version %s for %s', $version, $packageName));
                    foreach ($package->getReplaces() as $link) {
                        self::assertSame($package->getName(), $link->getSource());
                        self::assertTrue($link->getConstraint()->matches(new Constraint('=', $package->getVersion())));
                    }
                }
            }
            self::assertTrue($version === false || $foundLibrary, sprintf('Could not find library %s', $packageName));
        }
    }
}

class ResourceBundleStub {
    const STUB_VERSION = '32.0.1';

    public static function create($locale, $bundleName, $fallback) {
        Assert::assertSame('root', $locale);
        Assert::assertSame('ICUDATA', $bundleName);
        Assert::assertFalse($fallback);

        return new self();
    }

    public function get($field) {
        Assert::assertSame('Version', $field);

        return self::STUB_VERSION;
    }
}

class IntlCharStub {
    const STUB_VERSION = '7.0.0';

    public static function getUnicodeVersion()
    {
        return array(7, 0, 0, 0);
    }
}

class Imagick6Stub {
    const STUB_VERSION = '6.8.9.9';

    public function getVersion()
    {
        return array('versionString' => 'ImageMagick 6.8.9-9 Q16 x86_64 2018-05-18 http://www.imagemagick.org');
    }
}

class Imagick7Stub {
    const STUB_VERSION = '7.0.8.34';

    public function getVersion()
    {
        return array('versionString' => 'ImageMagick 7.0.8-34 Q16 x86_64 2019-03-23 https://imagemagick.org');
    }
}

class ZipArchiveStub {
    const LIBZIP_VERSION = '1.5.0';
}
