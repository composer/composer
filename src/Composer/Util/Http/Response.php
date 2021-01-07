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

namespace Composer\Util\Http;

use Composer\Json\JsonFile;

class Response
{
    private $request;
    private $code;
    private $headers;
    private $body;

    public function __construct(array $request, $code, array $headers, $body)
    {
        if (!isset($request['url'])) {
            throw new \LogicException('url key missing from request array');
        }
        $this->request = $request;
        $this->code = (int) $code;
        $this->headers = $headers;
        $this->body = $body;
    }

    public function getStatusCode()
    {
        return $this->code;
    }

    /**
     * @return string|null
     */
    public function getStatusMessage()
    {
        $value = null;
        foreach ($this->headers as $header) {
            if (preg_match('{^HTTP/\S+ \d+}i', $header)) {
                // In case of redirects, headers contain the headers of all responses
                // so we can not return directly and need to keep iterating
                $value = $header;
            }
        }

        return $value;
    }

    public function getHeaders()
    {
        return $this->headers;
    }

    public function getHeader($name)
    {
        return self::findHeaderValue($this->headers, $name);
    }

    public function getBody()
    {
        return $this->body;
    }

    public function decodeJson()
    {
        return JsonFile::parseJson($this->body, $this->request['url']);
    }

    public function collect()
    {
        $this->request = $this->code = $this->headers = $this->body = null;
    }

    /**
     * @param  array       $headers array of returned headers like from getLastHeaders()
     * @param  string      $name    header name (case insensitive)
     * @return string|null
     */
    public static function findHeaderValue(array $headers, $name)
    {
        $value = null;
        foreach ($headers as $header) {
            if (preg_match('{^'.preg_quote($name).':\s*(.+?)\s*$}i', $header, $match)) {
                $value = $match[1];
            }
        }

        return $value;
    }
}
