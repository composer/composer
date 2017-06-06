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

use Composer\Config;
use JsonSchema\Validator;
use Seld\JsonLint\JsonParser;
use Seld\JsonLint\ParsingException;
use Composer\Util\RemoteFilesystem;
use Composer\IO\IOInterface;
use Composer\Downloader\TransportException;

/**
 * Reads/writes json files.
 *
 * @author Konstantin Kudryashiv <ever.zet@gmail.com>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class JsonFile
{
    const LAX_SCHEMA = 1;
    const STRICT_SCHEMA = 2;

    const JSON_UNESCAPED_SLASHES = 64;
    const JSON_PRETTY_PRINT = 128;
    const JSON_UNESCAPED_UNICODE = 256;

    private $path;
    private $rfs;
    private $io;
    private $redis;
    private $usingRedis = false;

    /**
     * Initializes json file reader/parser.
     *
     * @param  string                    $path path to a lockfile
     * @param  RemoteFilesystem          $rfs  required for loading http/https json files
     * @param  IOInterface               $io
     * @throws \InvalidArgumentException
     */
    public function __construct($path, RemoteFilesystem $rfs = null, IOInterface $io = null)
    {
        $this->path = $path;

        if (null === $rfs && preg_match('{^https?://}i', $path)) {
            throw new \InvalidArgumentException('http urls require a RemoteFilesystem instance to be passed');
        }
        $this->rfs = $rfs;
        $this->io = $io;

        $config = new Config(true, getcwd());
        $this->usingRedis = $config->get('redis-store');
        if ($this->usingRedis) {
            $this->redis = new \Redis();
            $this->redis->connect($config->get('redis-host'), $config->get('redis-port'), $config->get('redis-timeout'));
            $this->redis->select($config->get('redis-db-index'));
            $redisPassword = $config->get('redis-password');
            if ($redisPassword) {
                $this->redis->auth($redisPassword);
            }
        }
    }

    /**
     * @return string
     */
    public function getPath()
    {
        return $this->path;
    }

    /**
     * Checks whether json file exists.
     *
     * @return bool
     */
    public function exists()
    {
        if (!$this->usingRedis) {
            return is_file($this->path);
        }

        $redisKey = realpath($this->path);

        if ($this->redis->exists($redisKey)) {
            return true;
        }

        if (is_file($this->path)) {
            return $this->redis->set($redisKey, file_get_contents($this->path));
        }

        return false;
    }

    /**
     * Reads json file.
     *
     * @throws \RuntimeException
     * @return mixed
     */
    public function read()
    {
        try {
            if ($this->rfs) {
                $json = $this->rfs->getContents($this->path, $this->path, false);
            } else {
                if ($this->io && $this->io->isDebug()) {
                    $this->io->writeError('Reading ' . $this->path);
                }
                if (!$this->usingRedis) {
                    $json = file_get_contents($this->path);
                } else {
                    $json = $this->redis->get(realpath($this->path));
                }
            }
        } catch (TransportException $e) {
            throw new \RuntimeException($e->getMessage(), 0, $e);
        } catch (\Exception $e) {
            throw new \RuntimeException('Could not read '.$this->path."\n\n".$e->getMessage());
        }

        return static::parseJson($json, $this->path);
    }

    /**
     * Writes json file.
     *
     * @param  array                                $hash    writes hash into json file
     * @param  int                                  $options json_encode options (defaults to JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
     * @throws \UnexpectedValueException|\Exception
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
            if (!@mkdir($dir, 0777, true)) {
                throw new \UnexpectedValueException(
                    $dir.' does not exist and could not be created.'
                );
            }
        }

        $retries = 3;
        while ($retries--) {
            try {
                $jsonContent = static::encode($hash, $options). ($options & self::JSON_PRETTY_PRINT ? "\n" : '');
                if (!$this->usingRedis) {
                    file_put_contents($this->path, $jsonContent);
                } else {
                    $this->redis->set(realpath($this->path), $jsonContent);
                }
                break;
            } catch (\Exception $e) {
                if ($retries) {
                    usleep(500000);
                    continue;
                }

                throw $e;
            }
        }
    }

    /**
     * Validates the schema of the current json file according to composer-schema.json rules
     *
     * @param  int                     $schema a JsonFile::*_SCHEMA constant
     * @throws JsonValidationException
     * @return bool                    true on success
     */
    public function validateSchema($schema = self::STRICT_SCHEMA)
    {
        if (!$this->usingRedis) {
            $content = file_get_contents($this->path);
        } else {
            $content = $this->redis->get(realpath($this->path));
        }
        $data = json_decode($content);

        if (null === $data && 'null' !== $content) {
            self::validateSyntax($content, $this->path);
        }

        $schemaFile = __DIR__ . '/../../../res/composer-schema.json';

        // Prepend with file:// only when not using a special schema already (e.g. in the phar)
        if (false === strpos($schemaFile, '://')) {
            $schemaFile = 'file://' . $schemaFile;
        }

        $schemaData = (object) array('$ref' => $schemaFile);

        if ($schema === self::LAX_SCHEMA) {
            $schemaData->additionalProperties = true;
            $schemaData->required = array();
        }

        $validator = new Validator();
        $validator->check($data, $schemaData);

        // TODO add more validation like check version constraints and such, perhaps build that into the arrayloader?

        if (!$validator->isValid()) {
            $errors = array();
            foreach ((array) $validator->getErrors() as $error) {
                $errors[] = ($error['property'] ? $error['property'].' : ' : '').$error['message'];
            }
            throw new JsonValidationException('"'.$this->path.'" does not match the expected JSON schema', $errors);
        }

        return true;
    }

    /**
     * Encodes an array into (optionally pretty-printed) JSON
     *
     * @param  mixed  $data    Data to encode into a formatted JSON string
     * @param  int    $options json_encode options (defaults to JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
     * @return string Encoded json
     */
    public static function encode($data, $options = 448)
    {
        if (PHP_VERSION_ID >= 50400) {
            $json = json_encode($data, $options);
            if (false === $json) {
                self::throwEncodeError(json_last_error());
            }

            //  compact brackets to follow recent php versions
            if (PHP_VERSION_ID < 50428 || (PHP_VERSION_ID >= 50500 && PHP_VERSION_ID < 50512) || (defined('JSON_C_VERSION') && version_compare(phpversion('json'), '1.3.6', '<'))) {
                $json = preg_replace('/\[\s+\]/', '[]', $json);
                $json = preg_replace('/\{\s+\}/', '{}', $json);
            }

            return $json;
        }

        $json = json_encode($data);
        if (false === $json) {
            self::throwEncodeError(json_last_error());
        }

        $prettyPrint = (bool) ($options & self::JSON_PRETTY_PRINT);
        $unescapeUnicode = (bool) ($options & self::JSON_UNESCAPED_UNICODE);
        $unescapeSlashes = (bool) ($options & self::JSON_UNESCAPED_SLASHES);

        if (!$prettyPrint && !$unescapeUnicode && !$unescapeSlashes) {
            return $json;
        }

        $result = JsonFormatter::format($json, $unescapeUnicode, $unescapeSlashes);

        return $result;
    }

    /**
     * Throws an exception according to a given code with a customized message
     *
     * @param  int               $code return code of json_last_error function
     * @throws \RuntimeException
     */
    private static function throwEncodeError($code)
    {
        switch ($code) {
            case JSON_ERROR_DEPTH:
                $msg = 'Maximum stack depth exceeded';
                break;
            case JSON_ERROR_STATE_MISMATCH:
                $msg = 'Underflow or the modes mismatch';
                break;
            case JSON_ERROR_CTRL_CHAR:
                $msg = 'Unexpected control character found';
                break;
            case JSON_ERROR_UTF8:
                $msg = 'Malformed UTF-8 characters, possibly incorrectly encoded';
                break;
            default:
                $msg = 'Unknown error';
        }

        throw new \RuntimeException('JSON encoding failed: '.$msg);
    }

    /**
     * Parses json string and returns hash.
     *
     * @param string $json json string
     * @param string $file the json file
     *
     * @return mixed
     */
    public static function parseJson($json, $file = null)
    {
        if (null === $json) {
            return;
        }
        $data = json_decode($json, true);
        if (null === $data && JSON_ERROR_NONE !== json_last_error()) {
            self::validateSyntax($json, $file);
        }

        return $data;
    }

    /**
     * Validates the syntax of a JSON string
     *
     * @param  string                    $json
     * @param  string                    $file
     * @throws \UnexpectedValueException
     * @throws JsonValidationException
     * @throws ParsingException
     * @return bool                      true on success
     */
    protected static function validateSyntax($json, $file = null)
    {
        $parser = new JsonParser();
        $result = $parser->lint($json);
        if (null === $result) {
            if (defined('JSON_ERROR_UTF8') && JSON_ERROR_UTF8 === json_last_error()) {
                throw new \UnexpectedValueException('"'.$file.'" is not UTF-8, could not parse as JSON');
            }

            return true;
        }

        throw new ParsingException('"'.$file.'" does not contain valid JSON'."\n".$result->getMessage(), $result->getDetails());
    }
}
