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
    public static function processUrl($mirrorUrl, $packageName, $version, $reference, $type)
    {
        $reference = preg_match('{^([a-f0-9]*|%reference%)$}', $reference) ? $reference : md5($reference);
        $version = strpos($version, '/') === false ? $version : md5($version);

        return str_replace(
            array('%package%', '%version%', '%reference%', '%type%'),
            array($packageName, $version, $reference, $type),
            $mirrorUrl
        );
    }
}
