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
 * BitTorrent Bencoding is used as a means of canonicalising JSON data into a
 * format which can be cryptographically signed. JSON itself has no standard
 * canonicalisation algorithm so bencode is a simple cross-platform alternative.
 *
 * @author Pádraic Brady <padraic.brady@gmail.com>
 */
class Bencode
{

    public function encode($data)
    {
        $buffer = null;
        if (is_array($data)) {
            if (array_values($data) !== $data) {
                ksort($data, SORT_STRING);
                $buffer = 'd';
                foreach ($data as $key => $value) {
                    $buffer .= $this->encode((string) $key) . $this->encode($value);
                }
                $buffer .= 'e';
            } else {
                ksort($data, SORT_NUMERIC);
                $buffer = 'l';
                foreach ($data as $value) {
                    $buffer .= $this->encode($value);
                }
                $buffer .= 'e';
            }
        } elseif (is_string($data)) {
            $buffer = sprintf('%d:%s', strlen($data), $data);
        } elseif (is_int($data) || is_float($data)) {
            $buffer = sprintf('i%.0fe', round($data));
        } elseif (is_null($data)) {
            $buffer = '0:';
        }
        return $buffer;
    }

    public function encodeJson($json)
    {
        return $this->encode(json_decode($json, true));
    }

    public function encodeArray(array $array)
    {
        return $this->encode($array);
    }

}