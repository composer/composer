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

use Composer\Downloader\TransportException;

/**
 * @internal
 * @author John Stevenson <john-stevenson@blueyonder.co.uk>
 *
 * @phpstan-type contextOptions array{http: array{proxy: string, header?: string, request_fulluri?: bool}}
 */
class RequestProxy
{
    /** @var ?contextOptions */
    private $contextOptions;
    /** @var ?non-empty-string */
    private $status;
    /** @var ?non-empty-string */
    private $url;
    /** @var ?non-empty-string */
    private $auth;

    /**
     * @param ?non-empty-string $url The proxy url, without authorization
     * @param ?non-empty-string $auth Authorization for curl
     * @param ?contextOptions $contextOptions
     * @param ?non-empty-string $status
     */
    public function __construct(?string $url, ?string $auth, ?array $contextOptions, ?string $status)
    {
        $this->url = $url;
        $this->auth = $auth;
        $this->contextOptions = $contextOptions;
        $this->status = $status;
    }

    public static function none(): RequestProxy
    {
        return new self(null, null, null, null);
    }

    public static function noProxy(): RequestProxy
    {
        return new self(null, null, null, 'excluded by no_proxy');
    }

    /**
     * Returns the context options to use for this request, otherwise null
     *
     * @return ?contextOptions
     */
    public function getContextOptions(): ?array
    {
        return $this->contextOptions;
    }

    /**
     * Returns an array of curl proxy options
     *
     * @param array<string, string|int> $sslOptions
     * @return array<int, string|int>
     */
    public function getCurlOptions(array $sslOptions): array
    {
        if ($this->isSecure() && !$this->supportsSecureProxy()) {
            throw new TransportException('Cannot use an HTTPS proxy. PHP >= 7.3 and cUrl >= 7.52.0 are required.');
        }

        // Always set a proxy url, even an empty value, because it tells curl
        // to ignore proxy environment variables
        $options = [CURLOPT_PROXY => (string) $this->url];

        // If using a proxy, tell curl to ignore no_proxy environment variables
        if ($this->url !== null) {
            $options[CURLOPT_NOPROXY] = '';
        }

        // Set any authorization
        if ($this->auth !== null) {
            $options[CURLOPT_PROXYAUTH] = CURLAUTH_BASIC;
            $options[CURLOPT_PROXYUSERPWD] = $this->auth;
        }

        if ($this->isSecure()) {
            if (isset($sslOptions['cafile'])) {
                $options[CURLOPT_PROXY_CAINFO] = $sslOptions['cafile'];
            }
            if (isset($sslOptions['capath'])) {
                $options[CURLOPT_PROXY_CAPATH] = $sslOptions['capath'];
            }
        }

        return $options;
    }

    /**
     * Returns proxy info associated with this request
     *
     * An empty return value means that the user has not set a proxy.
     * A non-empty value will either be the sanitized proxy url if a proxy is
     * required, or a message indicating that a no_proxy value has disabled the
     * proxy.
     *
     * @param ?string $format Output format specifier
     */
    public function getStatus(?string $format = null): string
    {
        if ($this->status === null) {
            return '';
        }

        $format = $format ?? '%s';
        if (strpos($format, '%s') !== false) {
            return sprintf($format, $this->status);
        }

        throw new \InvalidArgumentException('String format specifier is missing');
    }

    /**
     * Returns true if the request url has been excluded by a no_proxy value
     *
     * A false value can also mean that the user has not set a proxy.
     */
    public function isExcludedByNoProxy(): bool
    {
        return $this->status !== null && $this->url === null;
    }

    /**
     * Returns true if this is a secure (HTTPS) proxy
     *
     * A false value means that this is either an HTTP proxy, or that a proxy
     * is not required for this request, or that the user has not set a proxy.
     */
    public function isSecure(): bool
    {
        return 0 === strpos((string) $this->url, 'https://');
    }

    /**
     * Returns true if an HTTPS proxy can be used.
     *
     * This depends on PHP7.3+ for CURL_VERSION_HTTPS_PROXY
     * and curl including the feature (from version 7.52.0)
     */
    public function supportsSecureProxy(): bool
    {
        if (false === ($version = curl_version()) || !defined('CURL_VERSION_HTTPS_PROXY')) {
            return false;
        }

        $features = $version['features'];

        return (bool) ($features & CURL_VERSION_HTTPS_PROXY);
    }
}
