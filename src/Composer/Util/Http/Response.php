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
        $this->request = $request;
        $this->code = $code;
        $this->headers = $headers;
        $this->body = $body;
    }

    public function getStatusCode()
    {
        return $this->code;
    }

    public function getHeaders()
    {
        return $this->headers;
    }

    public function getHeader($name)
    {
        $value = null;
        foreach ($this->headers as $header) {
            if (preg_match('{^'.$name.':\s*(.+?)\s*$}i', $header, $match)) {
                $value = $match[1];
            } elseif (preg_match('{^HTTP/}i', $header)) {
                // TODO ideally redirects would be handled in CurlDownloader/RemoteFilesystem and this becomes unnecessary
                //
                // In case of redirects, http_response_headers contains the headers of all responses
                // so we reset the flag when a new response is being parsed as we are only interested in the last response
                $value = null;
            }
        }

        return $value;
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
}
