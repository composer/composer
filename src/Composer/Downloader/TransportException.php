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

namespace Composer\Downloader;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class TransportException extends \RuntimeException
{
    /** @var ?array<string> */
    protected $headers;
    /** @var ?string */
    protected $response;
    /** @var ?int */
    protected $statusCode;
    /** @var array<mixed> */
    protected $responseInfo = array();

    /**
     * @param array<string> $headers
     *
     * @return void
     */
    public function setHeaders($headers)
    {
        $this->headers = $headers;
    }

    /**
     * @return ?array<string>
     */
    public function getHeaders()
    {
        return $this->headers;
    }

    /**
     * @param ?string $response
     *
     * @return void
     */
    public function setResponse($response)
    {
        $this->response = $response;
    }

    /**
     * @return ?string
     */
    public function getResponse()
    {
        return $this->response;
    }

    /**
     * @param ?int $statusCode
     *
     * @return void
     */
    public function setStatusCode($statusCode)
    {
        $this->statusCode = $statusCode;
    }

    /**
     * @return ?int
     */
    public function getStatusCode()
    {
        return $this->statusCode;
    }

    /**
     * @return array<mixed>
     */
    public function getResponseInfo()
    {
        return $this->responseInfo;
    }

    /**
     * @param array<mixed> $responseInfo
     *
     * @return void
     */
    public function setResponseInfo(array $responseInfo)
    {
        $this->responseInfo = $responseInfo;
    }
}
