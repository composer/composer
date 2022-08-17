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

namespace Composer\Test\Platform;

use Composer\Platform\Version;
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
    public static function provideOpenSslVersions(): array
    {
        return [
            // Generated
            ['1.2.3', '1.2.3.0'],
            ['1.2.3-beta3', '1.2.3.0-beta3'],
            ['1.2.3-beta3-dev', '1.2.3.0-beta3-dev'],
            ['1.2.3-beta3-fips', '1.2.3.0-beta3', true],
            ['1.2.3-beta3-fips-dev', '1.2.3.0-beta3-dev', true],
            ['1.2.3-dev', '1.2.3.0-dev'],
            ['1.2.3-fips', '1.2.3.0', true],
            ['1.2.3-fips-beta3', '1.2.3.0-beta3', true],
            ['1.2.3-fips-beta3-dev', '1.2.3.0-beta3-dev', true],
            ['1.2.3-fips-dev', '1.2.3.0-dev', true],
            ['1.2.3-pre2', '1.2.3.0-alpha2'],
            ['1.2.3-pre2-dev', '1.2.3.0-alpha2-dev'],
            ['1.2.3-pre2-fips', '1.2.3.0-alpha2', true],
            ['1.2.3-pre2-fips-dev', '1.2.3.0-alpha2-dev', true],
            ['1.2.3a', '1.2.3.1'],
            ['1.2.3a-beta3','1.2.3.1-beta3'],
            ['1.2.3a-beta3-dev', '1.2.3.1-beta3-dev'],
            ['1.2.3a-dev', '1.2.3.1-dev'],
            ['1.2.3a-dev-fips', '1.2.3.1-dev', true],
            ['1.2.3a-fips', '1.2.3.1', true],
            ['1.2.3a-fips-beta3', '1.2.3.1-beta3', true],
            ['1.2.3a-fips-dev', '1.2.3.1-dev', true],
            ['1.2.3beta3', '1.2.3.0-beta3'],
            ['1.2.3beta3-dev', '1.2.3.0-beta3-dev'],
            ['1.2.3zh', '1.2.3.34'],
            ['1.2.3zh-dev', '1.2.3.34-dev'],
            ['1.2.3zh-fips', '1.2.3.34',true],
            ['1.2.3zh-fips-dev', '1.2.3.34-dev', true],
            // Additional cases
            ['1.2.3zh-fips-rc3', '1.2.3.34-rc3', true, '1.2.3.34-RC3'],
            ['1.2.3zh-alpha10-fips', '1.2.3.34-alpha10', true],
            ['1.1.1l (Schannel)', '1.1.1.12'],
            // Check that alphabetical patch levels overflow correctly
            ['1.2.3', '1.2.3.0'],
            ['1.2.3a', '1.2.3.1'],
            ['1.2.3z', '1.2.3.26'],
            ['1.2.3za', '1.2.3.27'],
            ['1.2.3zy', '1.2.3.51'],
            ['1.2.3zz', '1.2.3.52'],
            // 3.x
            ['3.0.0', '3.0.0', false, '3.0.0.0'],
            ['3.2.4-dev', '3.2.4-dev', false, '3.2.4.0-dev'],
        ];
    }

    /**
     * @dataProvider provideOpenSslVersions
     */
    public function testParseOpensslVersions(string $input, string $parsedVersion, bool $fipsExpected = false, ?string $normalizedVersion = null): void
    {
        self::assertSame($parsedVersion, Version::parseOpenssl($input, $isFips));
        self::assertSame($fipsExpected, $isFips);

        $normalizedVersion = $normalizedVersion ? $normalizedVersion : $parsedVersion;
        self::assertSame($normalizedVersion, $this->getVersionParser()->normalize($parsedVersion));
    }

    public function provideLibJpegVersions(): array
    {
        return [
            ['9', '9.0'],
            ['9a', '9.1'],
            ['9b', '9.2'],
            // Never seen in the wild, just for overflow correctness
            ['9za', '9.27'],
        ];
    }

    /**
     * @dataProvider provideLibJpegVersions
     */
    public function testParseLibjpegVersion(string $input, string $parsedVersion): void
    {
        self::assertSame($parsedVersion, Version::parseLibjpeg($input));
    }

    public function provideZoneinfoVersions(): array
    {
        return [
            ['2019c', '2019.3'],
            ['2020a', '2020.1'],
            // Never happened so far but fixate overflow behavior
            ['2020za', '2020.27'],
        ];
    }

    /**
     * @dataProvider provideZoneinfoVersions
     */
    public function testParseZoneinfoVersion(string $input, string $parsedVersion): void
    {
        self::assertSame($parsedVersion, Version::parseZoneinfoVersion($input));
    }
}
