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

use Composer\Semver\VersionParser as BaseVersionParser;

class VersionParser extends BaseVersionParser
{
    /**
     * @param array $pairs
     *
     * @return array[]
     */
    public function parseNameVersionPairs(array $pairs)
    {
        $pairs = array_values($pairs);
        $result = array();

        for ($i = 0, $count = count($pairs); $i < $count; $i++) {
            $pair = preg_replace('{^([^=: ]+)[=: ](.*)$}', '$1 $2', trim($pairs[$i]));
            if (false === strpos($pair, ' ') && isset($pairs[$i + 1]) && false === strpos($pairs[$i + 1], '/')) {
                $pair .= ' '.$pairs[$i + 1];
                $i++;
            }

            if (strpos($pair, ' ')) {
                list($name, $version) = explode(" ", $pair, 2);
                $result[] = array('name' => $name, 'version' => $version);
            } else {
                $result[] = array('name' => $pair);
            }
        }

        return $result;
    }
}
