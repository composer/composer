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

use Composer\Package\Version\VersionParser;
use Composer\Util\Version;
use Composer\Util\Zip;
use Composer\Test\TestCase;

/**
 * @author Lars Strojny <lars@strojny.net>
 */
class VersionTest extends TestCase
{
    /**
     * Create normalized test data set
     *
     * 1) Clone OpenSSL repository
     * 2) git log --pretty=%h --all -- crypto/opensslv.h include/openssl/opensslv.h | while read hash ; do (git show $hash:crypto/opensslv.h; git show $hash:include/openssl/opensslv.h)  | grep "define OPENSSL_VERSION_TEXT"  ; done > versions.txt
     * 3) cat versions.txt | awk -F "OpenSSL " '{print $2}'  | awk -F " " '{print $1}' | sed -e "s:\([0-9]*\.[0-9]*\.[0-9]*\):1.2.3:g" -e "s:1\.2\.3[a-z]\(-.*\)\{0,1\}$:1.2.3a\1:g"  -e "s:1\.2\.3[a-z]\{2\}\(-.*\)\{0,1\}$:1.2.3zh\1:g"  -e "s:beta[0-9]:beta3:g"  -e "s:pre[0-9]*:pre2:g" | sort | uniq
     */
    public static function getOpenSslVersions()
    {
        return array(
            // Generated
            array('1.2.3', '1.2.3.0'),
            array('1.2.3-beta3', '1.2.3.0-beta3'),
            array('1.2.3-beta3-dev', '1.2.3.0-beta3-dev'),
            array('1.2.3-beta3-fips', '1.2.3.0-beta3', true),
            array('1.2.3-beta3-fips-dev', '1.2.3.0-beta3-dev', true),
            array('1.2.3-dev', '1.2.3.0-dev'),
            array('1.2.3-fips', '1.2.3.0', true),
            array('1.2.3-fips-beta3', '1.2.3.0-beta3', true),
            array('1.2.3-fips-beta3-dev', '1.2.3.0-beta3-dev', true),
            array('1.2.3-fips-dev', '1.2.3.0-dev', true),
            array('1.2.3-pre2', '1.2.3.0-alpha2'),
            array('1.2.3-pre2-dev', '1.2.3.0-alpha2-dev'),
            array('1.2.3-pre2-fips', '1.2.3.0-alpha2', true),
            array('1.2.3-pre2-fips-dev', '1.2.3.0-alpha2-dev', true),
            array('1.2.3a', '1.2.3.1'),
            array('1.2.3a-beta3','1.2.3.1-beta3'),
            array('1.2.3a-beta3-dev', '1.2.3.1-beta3-dev'),
            array('1.2.3a-dev', '1.2.3.1-dev'),
            array('1.2.3a-dev-fips', '1.2.3.1-dev', true),
            array('1.2.3a-fips', '1.2.3.1', true),
            array('1.2.3a-fips-beta3', '1.2.3.1-beta3', true),
            array('1.2.3a-fips-dev', '1.2.3.1-dev', true),
            array('1.2.3beta3', '1.2.3.0-beta3'),
            array('1.2.3beta3-dev', '1.2.3.0-beta3-dev'),
            array('1.2.3zh', '1.2.3.34'),
            array('1.2.3zh-dev', '1.2.3.34-dev'),
            array('1.2.3zh-fips', '1.2.3.34',true),
            array('1.2.3zh-fips-dev', '1.2.3.34-dev', true),
            // Additional cases
            array('1.2.3zh-fips-rc3', '1.2.3.34-rc3', true, '1.2.3.34-RC3'),
            // Check that letters overflow correctly
            array('1.2.3', '1.2.3.0'),
            array('1.2.3a', '1.2.3.1'),
            array('1.2.3z', '1.2.3.26'),
            array('1.2.3za', '1.2.3.27'),
            array('1.2.3zy', '1.2.3.51'),
            array('1.2.3zz', '1.2.3.52'),
        );
    }

    /** @dataProvider getOpenSslVersions */
    public function testParseOpensslVersions($input, $parsedVersion, $fipsExpected = false, $normalizedVersion = null)
    {
        self::assertSame($parsedVersion, Version::parseOpenssl($input, $isFips));
        self::assertSame($fipsExpected, $isFips);

        $normalizedVersion = $normalizedVersion ? $normalizedVersion : $parsedVersion;
        $versionParser = new VersionParser();
        self::assertSame($normalizedVersion, $versionParser->normalize($parsedVersion));
    }
}
