<?php declare(strict_types=1);

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
    public function setHeaders(array $headers): void
    {
        $this->headers = $headers;
    }

    /**
     * @return ?array<string>
     */
    public function getHeaders(): ?array
    {
        return $this->headers;
    }

    /**
     * @param null|string $response
     *
     * @return void
     */
    public function setResponse(?string $response): void
    {
        $this->response = $response;
    }

    /**
     * @return ?string
     */
    public function getResponse(): ?string
    {
        return $this->response;
    }

    /**
     * @param ?int $statusCode
     *
     * @return void
     */
    public function setStatusCode($statusCode): void
    {
        $this->statusCode = $statusCode;
    }

    /**
     * @return ?int
     */
    public function getStatusCode(): ?int
    {
        return $this->statusCode;
    }

    /**
     * @return array<mixed>
     */
    public function getResponseInfo(): array
    {
        return $this->responseInfo;
    }

    /**
     * @param array<mixed> $responseInfo
     *
     * @return void
     */
    public function setResponseInfo(array $responseInfo): void
    {
        $this->responseInfo = $responseInfo;
    }
}
