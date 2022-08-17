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

namespace Composer\Util\Http;

/**
 * @phpstan-type CurlInfo array{url: mixed, content_type: mixed, http_code: mixed, header_size: mixed, request_size: mixed, filetime: mixed, ssl_verify_result: mixed, redirect_count: mixed, total_time: mixed, namelookup_time: mixed, connect_time: mixed, pretransfer_time: mixed, size_upload: mixed, size_download: mixed, speed_download: mixed, speed_upload: mixed, download_content_length: mixed, upload_content_length: mixed, starttransfer_time: mixed, redirect_time: mixed, certinfo: mixed, primary_ip: mixed, primary_port: mixed, local_ip: mixed, local_port: mixed, redirect_url: mixed}
 */
class CurlResponse extends Response
{
    /**
     * @see https://www.php.net/curl_getinfo
     * @var array
     * @phpstan-var CurlInfo
     */
    private $curlInfo;

    /**
     * @phpstan-param CurlInfo $curlInfo
     */
    public function __construct(array $request, ?int $code, array $headers, ?string $body, array $curlInfo)
    {
        parent::__construct($request, $code, $headers, $body);
        $this->curlInfo = $curlInfo;
    }

    /**
     * @phpstan-return CurlInfo
     */
    public function getCurlInfo(): array
    {
        return $this->curlInfo;
    }
}
