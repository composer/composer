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

namespace Composer\Json;

use Composer\Repository\RepositoryManager;

/**
 * Reads/writes json files.
 *
 * @author Konstantin Kudryashiv <ever.zet@gmail.com>
 */
class JsonFile
{
    private $path;

    /**
     * Initializes json file reader/parser.
     *
     * @param   string  $lockFile   path to a lockfile
     */
    public function __construct($path)
    {
        $this->path = $path;
    }

    /**
     * Checks whether json file exists.
     *
     * @return  Boolean
     */
    public function exists()
    {
        return is_file($this->path);
    }

    /**
     * Reads json file.
     *
     * @param   string  $json   path or json string
     *
     * @return  array
     */
    public function read()
    {
        $json = file_get_contents($this->path);

        return static::parseJson($json);
    }

    /**
     * Writes json file.
     *
     * @param   array   $hash   writes hash into json file
     */
    public function write(array $hash)
    {
        file_put_contents($this->path, json_encode($hash));
    }

    /**
     * Parses json string and returns hash.
     *
     * @param   string  $json   json string
     *
     * @return  array
     */
    public static function parseJson($json)
    {
        $hash = json_decode($json, true);
        if (!$hash) {
            switch (json_last_error()) {
            case JSON_ERROR_NONE:
                $msg = 'No error has occurred, is your composer.json file empty?';
                break;
            case JSON_ERROR_DEPTH:
                $msg = 'The maximum stack depth has been exceeded';
                break;
            case JSON_ERROR_STATE_MISMATCH:
                $msg = 'Invalid or malformed JSON';
                break;
            case JSON_ERROR_CTRL_CHAR:
                $msg = 'Control character error, possibly incorrectly encoded';
                break;
            case JSON_ERROR_SYNTAX:
                $msg = 'Syntax error';
                break;
            case JSON_ERROR_UTF8:
                $msg = 'Malformed UTF-8 characters, possibly incorrectly encoded';
                break;
            }
            throw new \UnexpectedValueException('Incorrect composer.json file: '.$msg);
        }

        return $hash;
    }
}
