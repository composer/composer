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
    protected $responseInfo = [];

    public function __construct(string $message = "", int $code = 400, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    /**
     * @param array<string> $headers
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

    public function setResponse(?string $response): void
    {
        $this->response = $response;
    }

    public function getResponse(): ?string
    {
        return $this->response;
    }

    /**
     * @param ?int $statusCode
     */
    public function setStatusCode($statusCode): void
    {
        $this->statusCode = $statusCode;
    }

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
     */
    public function setResponseInfo(array $responseInfo): void
    {
        $this->responseInfo = $responseInfo;
    }
}
