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

namespace Composer\Package\Version;

/**
 * Version parser
 *
 * @author Konstantin Kudryashov <ever.zet@gmail.com>
 * @author Nils Adermann <naderman@naderman.de>
 */
class VersionParser
{
    /**
     * Parses a version string and returns an array with the version, its type (alpha, beta, RC, stable) and a dev flag (for development branches tracking)
     *
     * @param string $version
     * @return array
     */
    public function parse($version)
    {
        if (!preg_match('#^v?(\d+)(\.\d+)?(\.\d+)?-?((?:beta|RC|alpha)\d*)?-?(dev)?$#i', $version, $matches)) {
            throw new \UnexpectedValueException('Invalid version string '.$version);
        }

        return array(
            'version' => $matches[1]
                .(!empty($matches[2]) ? $matches[2] : '.0')
                .(!empty($matches[3]) ? $matches[3] : '.0'),
            'type' => strtolower(!empty($matches[4]) ? $matches[4] : 'stable'),
            'dev' => !empty($matches[5]),
        );
    }
}
