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
     * @param non-empty-string $mirrorUrl
     * @return non-empty-string
     */
    public static function processUrl(string $mirrorUrl, string $packageName, string $version, ?string $reference, ?string $type, ?string $prettyVersion = null): string
    {
        if ($reference) {
            $reference = Preg::isMatch('{^([a-f0-9]*|%reference%)$}', $reference) ? $reference : hash('md5', $reference);
        }
        $version = strpos($version, '/') === false ? $version : hash('md5', $version);

        $from = ['%package%', '%version%', '%reference%', '%type%'];
        $to = [$packageName, $version, $reference, $type];
        if (null !== $prettyVersion) {
            $from[] = '%prettyVersion%';
            $to[] = $prettyVersion;
        }

        $url = str_replace($from, $to, $mirrorUrl);
        assert($url !== '');

        return $url;
    }

    /**
     * @param non-empty-string $mirrorUrl
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
            ['%package%', '%normalizedUrl%', '%type%'],
            [$packageName, $url, $type],
            $mirrorUrl
        );
    }

    /**
     * @param non-empty-string $mirrorUrl
     * @return string
     */
    public static function processHgUrl(string $mirrorUrl, string $packageName, string $url, string $type): string
    {
        return self::processGitUrl($mirrorUrl, $packageName, $url, $type);
    }
}
