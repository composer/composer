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

use Composer\Pcre\Preg;
use JsonSchema\Validator;
use Seld\JsonLint\JsonParser;
use Seld\JsonLint\ParsingException;
use Composer\Util\HttpDownloader;
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

    const COMPOSER_SCHEMA_PATH = '/../../../res/composer-schema.json';

    /** @var string */
    private $path;
    /** @var ?HttpDownloader */
    private $httpDownloader;
    /** @var ?IOInterface */
    private $io;

    /**
     * Initializes json file reader/parser.
     *
     * @param  string                    $path           path to a lockfile
     * @param  ?HttpDownloader           $httpDownloader required for loading http/https json files
     * @param  ?IOInterface              $io
     * @throws \InvalidArgumentException
     */
    public function __construct($path, HttpDownloader $httpDownloader = null, IOInterface $io = null)
    {
        $this->path = $path;

        if (null === $httpDownloader && Preg::isMatch('{^https?://}i', $path)) {
            throw new \InvalidArgumentException('http urls require a HttpDownloader instance to be passed');
        }
        $this->httpDownloader = $httpDownloader;
        $this->io = $io;
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
        return is_file($this->path);
    }

    /**
     * Reads json file.
     *
     * @throws ParsingException
     * @throws \RuntimeException
     * @return mixed
     */
    public function read()
    {
        try {
            if ($this->httpDownloader) {
                $json = $this->httpDownloader->get($this->path)->getBody();
            } else {
                if ($this->io && $this->io->isDebug()) {
                    $realpathInfo = '';
                    $realpath = realpath($this->path);
                    if (false !== $realpath && $realpath !== $this->path) {
                        $realpathInfo = ' (' . $realpath . ')';
                    }
                    $this->io->writeError('Reading ' . $this->path . $realpathInfo);
                }
                $json = file_get_contents($this->path);
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
     * @param  mixed[]                          $hash    writes hash into json file
     * @param  int                                  $options json_encode options (defaults to JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
     * @throws \UnexpectedValueException|\Exception
     * @return void
     */
    public function write(array $hash, $options = 448)
    {
        if ($this->path === 'php://memory') {
            file_put_contents($this->path, static::encode($hash, $options));

            return;
        }

        $dir = dirname($this->path);
        if (!is_dir($dir)) {
            if (file_exists($dir)) {
                throw new \UnexpectedValueException(
                    realpath($dir).' exists and is not a directory.'
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
                $this->filePutContentsIfModified($this->path, static::encode($hash, $options). ($options & self::JSON_PRETTY_PRINT ? "\n" : ''));
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
     * Modify file properties only if content modified
     *
     * @param string $path
     * @param string $content
     * @return int|false
     */
    private function filePutContentsIfModified($path, $content)
    {
        $currentContent = @file_get_contents($path);
        if (!$currentContent || ($currentContent != $content)) {
            return file_put_contents($path, $content);
        }

        return 0;
    }

    /**
     * Validates the schema of the current json file according to composer-schema.json rules
     *
     * @param  int                     $schema     a JsonFile::*_SCHEMA constant
     * @param  string|null             $schemaFile a path to the schema file
     * @throws JsonValidationException
     * @throws ParsingException
     * @return bool                    true on success
     */
    public function validateSchema($schema = self::STRICT_SCHEMA, $schemaFile = null)
    {
        $content = file_get_contents($this->path);
        $data = json_decode($content);

        if (null === $data && 'null' !== $content) {
            self::validateSyntax($content, $this->path);
        }

        $isComposerSchemaFile = false;
        if (null === $schemaFile) {
            $isComposerSchemaFile = true;
            $schemaFile = __DIR__ . self::COMPOSER_SCHEMA_PATH;
        }

        // Prepend with file:// only when not using a special schema already (e.g. in the phar)
        if (false === strpos($schemaFile, '://')) {
            $schemaFile = 'file://' . $schemaFile;
        }

        $schemaData = (object) array('$ref' => $schemaFile);

        if ($schema === self::LAX_SCHEMA) {
            $schemaData->additionalProperties = true;
            $schemaData->required = array();
        } elseif ($schema === self::STRICT_SCHEMA && $isComposerSchemaFile) {
            $schemaData->additionalProperties = false;
            $schemaData->required = array('name', 'description');
        }

        $validator = new Validator();
        $validator->check($data, $schemaData);

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
                $json = Preg::replace('/\[\s+\]/', '[]', $json);
                $json = Preg::replace('/\{\s+\}/', '{}', $json);
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

        return JsonFormatter::format($json, $unescapeUnicode, $unescapeSlashes);
    }

    /**
     * Throws an exception according to a given code with a customized message
     *
     * @param  int               $code return code of json_last_error function
     * @throws \RuntimeException
     * @return void
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
     * @param ?string $json json string
     * @param string $file the json file
     *
     * @throws ParsingException
     * @return mixed
     */
    public static function parseJson($json, $file = null)
    {
        if (null === $json) {
            return null;
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
