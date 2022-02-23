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

namespace Composer\Util;

use Composer\Pcre\Preg;

/**
 * Composer mirror utilities
 *
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class ComposerMirror
{
    /**
     * @param string      $mirrorUrl
     * @param string      $packageName
     * @param string      $version
     * @param string|null $reference
     * @param string|null $type
     * @param string|null $prettyVersion
     *
     * @return string
     */
    public static function processUrl(string $mirrorUrl, string $packageName, string $version, ?string $reference, ?string $type, ?string $prettyVersion = null): string
    {
        if ($reference) {
            $reference = Preg::isMatch('{^([a-f0-9]*|%reference%)$}', $reference) ? $reference : md5($reference);
        }
        $version = strpos($version, '/') === false ? $version : md5($version);

        $from = array('%package%', '%version%', '%reference%', '%type%');
        $to = array($packageName, $version, $reference, $type);
        if (null !== $prettyVersion) {
            $from[] = '%prettyVersion%';
            $to[] = $prettyVersion;
        }

        return str_replace($from, $to, $mirrorUrl);
    }

    /**
     * @param string      $mirrorUrl
     * @param string      $packageName
     * @param string      $url
     * @param string|null $type
     *
     * @return string
     */
    public static function processGitUrl(string $mirrorUrl, string $packageName, string $url, ?string $type): string
    {
        if (Preg::isMatch('#^(?:(?:https?|git)://github\.com/|git@github\.com:)([^/]+)/(.+?)(?:\.git)?$#', $url, $match)) {
            $url = 'gh-'.$match[1].'/'.$match[2];
        } elseif (Preg::isMatch('#^https://bitbucket\.org/([^/]+)/(.+?)(?:\.git)?/?$#', $url, $match)) {
            $url = 'bb-'.$match[1].'/'.$match[2];
        } else {
            $url = Preg::replace('{[^a-z0-9_.-]}i', '-', trim($url, '/'));
        }

        return str_replace(
            array('%package%', '%normalizedUrl%', '%type%'),
            array($packageName, $url, $type),
            $mirrorUrl
        );
    }

    /**
     * @param string $mirrorUrl
     * @param string $packageName
     * @param string $url
     * @param string $type
     *
     * @return string
     */
    public static function processHgUrl(string $mirrorUrl, string $packageName, string $url, string $type): string
    {
        return self::processGitUrl($mirrorUrl, $packageName, $url, $type);
    }
}
