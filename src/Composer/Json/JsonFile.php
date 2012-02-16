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
use Composer\Util\StreamContextFactory;

if (!defined('JSON_UNESCAPED_SLASHES')) {
    define('JSON_UNESCAPED_SLASHES', 64);
}
if (!defined('JSON_PRETTY_PRINT')) {
    define('JSON_PRETTY_PRINT', 128);
}
if (!defined('JSON_UNESCAPED_UNICODE')) {
    define('JSON_UNESCAPED_UNICODE', 256);
}

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
        $ctx = StreamContextFactory::getContext(array(
            'http' => array(
                'header' => 'User-Agent: Composer/'.Composer::VERSION."\r\n"
        )));

        $json = file_get_contents($this->path, false, $ctx);
        if (!$json) {
            throw new \RuntimeException('Could not read '.$this->path.', you are probably offline');
        }

        return static::parseJson($json);
    }

    /**
     * Writes json file.
     *
     * @param   array   $hash   writes hash into json file
     * @param int $options json_encode options
     */
    public function write(array $hash, $options = 448)
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
        file_put_contents($this->path, static::encode($hash, $options));
    }

    /**
     * Encodes an array into (optionally pretty-printed) JSON
     *
     * Original code for this function can be found at:
     *  http://recursive-design.com/blog/2008/03/11/format-json-with-php/
     *
     * @param array $hash Data to encode into a formatted JSON string
     * @param int $options json_encode options
     * @return string Encoded json
     */
    static public function encode(array $hash, $options = 448)
    {
        if (version_compare(PHP_VERSION, '5.4', '>=')) {
            return json_encode($hash, $options);
        }

        $json = json_encode($hash);

        $prettyPrint = (Boolean) ($options & JSON_PRETTY_PRINT);
        $unescapeUnicode = (Boolean) ($options & JSON_UNESCAPED_UNICODE);

        if (!$prettyPrint && !$unescapeUnicode) {
            return $json;
        }

        $result = '';
        $pos = 0;
        $strLen = strlen($json);
        $indentStr = '    ';
        $newLine = "\n";
        $outOfQuotes = true;
        $buffer = '';
        $noescape = true;

        for ($i = 0; $i <= $strLen; $i++) {
            // Grab the next character in the string
            $char = substr($json, $i, 1);

            // Are we inside a quoted string?
            if ('"' === $char && $noescape) {
                $outOfQuotes = !$outOfQuotes;
            }

            if (!$outOfQuotes) {
                $buffer .= $char;
                $noescape = '\\' === $char ? !$noescape : true;
                continue;
            } elseif ('' !== $buffer) {
                if ($unescapeUnicode && function_exists('mb_convert_encoding')) {
                    // http://stackoverflow.com/questions/2934563/how-to-decode-unicode-escape-sequences-like-u00ed-to-proper-utf-8-encoded-cha
                    $result .= preg_replace_callback('/\\\\u([0-9a-f]{4})/i', function($match) {
                        return mb_convert_encoding(pack('H*', $match[1]), 'UTF-8', 'UCS-2BE');
                    }, $buffer.$char);
                } else {
                    $result .= $buffer.$char;
                }

                $buffer = '';
                continue;
            }

            if (':' === $char) {
                // Add a space after the : character
                $char .= ' ';
            } elseif (('}' === $char || ']' === $char)) {
                $pos--;
                $prevChar = substr($json, $i - 1, 1);

                if ('{' !== $prevChar && '[' !== $prevChar) {
                    // If this character is the end of an element,
                    // output a new line and indent the next line
                    $result .= $newLine;
                    for ($j = 0; $j < $pos; $j++) {
                        $result .= $indentStr;
                    }
                } else {
                    // Collapse empty {} and []
                    $result = rtrim($result);
                }
            }

            $result .= $char;

            // If the last character was the beginning of an element,
            // output a new line and indent the next line
            if (',' === $char || '{' === $char || '[' === $char) {
                $result .= $newLine;

                if ('{' === $char || '[' === $char) {
                    $pos++;
                }

                for ($j = 0; $j < $pos; $j++) {
                    $result .= $indentStr;
                }
            }
        }

        return $result;
    }

    /**
     * Parses json string and returns hash.
     *
     * @param   string  $json   json string
     *
     * @return  array
     */
    static public function parseJson($json)
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
                } elseif (preg_match('#((?<=[^\\\\])\\\\(?!["\\\\/bfnrt]|u[a-f0-9]{4}))#i', $json, $match, PREG_OFFSET_CAPTURE)) {
                    $msg .= ', unescaped backslash (\\)';
                } elseif (preg_match('#(["}\]])(?: *\r?\n *)+"#', $json, $match, PREG_OFFSET_CAPTURE)) {
                    $msg .= ', missing comma';
                    $charOffset = 1;
                } elseif (preg_match('#^ *([a-z0-9_-]+) *:#mi', $json, $match, PREG_OFFSET_CAPTURE)) {
                    $msg .= ', you must use double quotes (") around keys';
                } elseif (preg_match('#(\'.+?\' *:|: *\'.+?\')#', $json, $match, PREG_OFFSET_CAPTURE)) {
                    $msg .= ', use double quotes (") instead of single quotes (\')';
                } elseif (preg_match('#(\[".*?":.*?\])#', $json, $match, PREG_OFFSET_CAPTURE)) {
                    $msg .= ', you must use the hash syntax (e.g. {"foo": "bar"}) instead of array syntax (e.g. ["foo", "bar"])';
                } elseif (preg_match('#".*?"( *["{\[])#', $json, $match, PREG_OFFSET_CAPTURE)) {
                    $msg .= ', missing colon';
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
