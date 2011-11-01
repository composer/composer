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
use Composer\Composer;

/**
 * Reads/writes json files.
 *
 * @author Konstantin Kudryashiv <ever.zet@gmail.com>
 * @author Jordi Boggiano <j.boggiano@seld.be>
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

    public function getPath()
    {
        return $this->path;
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
        $context = stream_context_create(array(
            'http' => array('header' => 'User-Agent: Composer/'.Composer::VERSION."\r\n")
        ));

        $json = file_get_contents($this->path, false, $context);
        if (!$json) {
            throw new \RuntimeException('Could not read '.$this->path.', you are probably offline');
        }

        return static::parseJson($json);
    }

    /**
     * Writes json file.
     *
     * @param   array   $hash   writes hash into json file
     */
    public function write(array $hash)
    {
        $dir = dirname($this->path);
        if (!is_dir($dir)) {
            if (file_exists($dir)) {
                throw new \UnexpectedValueException(
                    $dir.' exists and is not a directory.'
                );
            }
            if (!mkdir($dir, 0777, true)) {
                throw new \UnexpectedValueException(
                    $dir.' does not exist and could not be created.'
                );
            }
        }
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
        $data = json_decode($json, true);

        if (null === $data && 'null' !== strtolower($json)) {
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
                $charOffset = 0;
                if (preg_match('#["}\]]\s*(,)\s*[}\]]#', $json, $match, PREG_OFFSET_CAPTURE)) {
                    $msg .= ', extra comma';
                } elseif (preg_match('#(\\\\(?!["\\/bfnrt]|u[a-f0-9]{4}))#i', $json, $match, PREG_OFFSET_CAPTURE)) {
                    $msg .= ', unescaped backslash (\\)';
                } elseif (preg_match('#(["}\]]) *\r?\n *"#', $json, $match, PREG_OFFSET_CAPTURE)) {
                    $msg .= ', missing comma';
                    $charOffset = 1;
                } elseif (preg_match('#^ *([a-z0-9_-]+) *:#mi', $json, $match, PREG_OFFSET_CAPTURE)) {
                    $msg .= ', you must use double quotes (") around keys';
                } elseif (preg_match('#(\'.+?\' *:|: *\'.+?\')#', $json, $match, PREG_OFFSET_CAPTURE)) {
                    $msg .= ', use double quotes (") instead of single quotes (\')';
                } elseif (preg_match('#(\[".*?":.*?\])#', $json, $match, PREG_OFFSET_CAPTURE)) {
                    $msg .= ', you must use the hash syntax (e.g. {"foo": "bar"}) instead of array syntax (e.g. ["foo", "bar"])';
                }
                if (isset($match[1][1])) {
                    $preError = substr($json, 0, $match[1][1]);
                    $msg .= ' on line '.(substr_count($preError, "\n")+1).', char '.abs(strrpos($preError, "\n") - strlen($preError) - $charOffset);
                }
                break;
            case JSON_ERROR_UTF8:
                $msg = 'Malformed UTF-8 characters, possibly incorrectly encoded';
                break;
            }
            throw new \UnexpectedValueException('JSON Parse Error: '.$msg);
        }

        return $data;
    }
}
