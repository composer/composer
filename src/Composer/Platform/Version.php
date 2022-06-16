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

namespace Composer\Platform;

use Composer\Pcre\Preg;

/**
 * @author Lars Strojny <lars@strojny.net>
 */
class Version
{
    /**
     * @param  string      $opensslVersion
     * @param  bool        $isFips Set by the method
     * @return string|null
     */
    public static function parseOpenssl(string $opensslVersion, ?bool &$isFips): ?string
    {
        $isFips = false;

        if (!Preg::isMatch('/^(?<version>[0-9.]+)(?<patch>[a-z]{0,2})?(?<suffix>(?:-?(?:dev|pre|alpha|beta|rc|fips)[\d]*)*)?(?<garbage>-\w+)?(?<garbage2> \(.+?\))?$/', $opensslVersion, $matches)) {
            return null;
        }

        // OpenSSL 1 used 1.2.3a style versioning, 3+ uses semver
        $patch = '';
        if (version_compare($matches['version'], '3.0.0', '<')) {
            $patch = '.'.self::convertAlphaVersionToIntVersion($matches['patch']);
        }

        $isFips = strpos($matches['suffix'], 'fips') !== false;
        $suffix = strtr('-'.ltrim($matches['suffix'], '-'), ['-fips' => '', '-pre' => '-alpha']);

        return rtrim($matches['version'].$patch.$suffix, '-');
    }

    /**
     * @param  string      $libjpegVersion
     * @return string|null
     */
    public static function parseLibjpeg(string $libjpegVersion): ?string
    {
        if (!Preg::isMatch('/^(?<major>\d+)(?<minor>[a-z]*)$/', $libjpegVersion, $matches)) {
            return null;
        }

        return $matches['major'].'.'.self::convertAlphaVersionToIntVersion($matches['minor']);
    }

    /**
     * @param  string      $zoneinfoVersion
     * @return string|null
     */
    public static function parseZoneinfoVersion(string $zoneinfoVersion): ?string
    {
        if (!Preg::isMatch('/^(?<year>\d{4})(?<revision>[a-z]*)$/', $zoneinfoVersion, $matches)) {
            return null;
        }

        return $matches['year'].'.'.self::convertAlphaVersionToIntVersion($matches['revision']);
    }

    /**
     * "" => 0, "a" => 1, "zg" => 33
     *
     * @param  string $alpha
     * @return int
     */
    private static function convertAlphaVersionToIntVersion(string $alpha): int
    {
        return strlen($alpha) * (-ord('a') + 1) + array_sum(array_map('ord', str_split($alpha)));
    }

    /**
     * @param  int    $versionId
     * @return string
     */
    public static function convertLibxpmVersionId(int $versionId): string
    {
        return self::convertVersionId($versionId, 100);
    }

    /**
     * @param  int    $versionId
     * @return string
     */
    public static function convertOpenldapVersionId(int $versionId): string
    {
        return self::convertVersionId($versionId, 100);
    }

    /**
     * @param int $versionId
     * @param int $base
     *
     * @return string
     */
    private static function convertVersionId(int $versionId, int $base): string
    {
        return sprintf(
            '%d.%d.%d',
            $versionId / ($base * $base),
            (int) ($versionId / $base) % $base,
            $versionId % $base
        );
    }
}
