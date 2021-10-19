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

namespace Composer\Util;

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
    public static function processUrl($mirrorUrl, $packageName, $version, $reference, $type, $prettyVersion = null)
    {
        if ($reference) {
            $reference = preg_match('{^([a-f0-9]*|%reference%)$}', $reference) ? $reference : md5($reference);
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
    public static function processGitUrl($mirrorUrl, $packageName, $url, $type)
    {
        if (preg_match('#^(?:(?:https?|git)://github\.com/|git@github\.com:)([^/]+)/(.+?)(?:\.git)?$#', $url, $match)) {
            $url = 'gh-'.$match[1].'/'.$match[2];
        } elseif (preg_match('#^https://bitbucket\.org/([^/]+)/(.+?)(?:\.git)?/?$#', $url, $match)) {
            $url = 'bb-'.$match[1].'/'.$match[2];
        } else {
            $url = preg_replace('{[^a-z0-9_.-]}i', '-', trim($url, '/'));
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
    public static function processHgUrl($mirrorUrl, $packageName, $url, $type)
    {
        return self::processGitUrl($mirrorUrl, $packageName, $url, $type);
    }
}
