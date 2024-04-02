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
 * @internal
 * @author John Stevenson <john-stevenson@blueyonder.co.uk>
 *
 * @phpstan-type contextOptions array{http: array{proxy: string, header?: string, request_fulluri?: bool}}
 */
class ProxyItem
{
    /** @var non-empty-string */
    private $url;
    /** @var non-empty-string */
    private $safeUrl;
    /** @var ?non-empty-string */
    private $curlAuth;
    /** @var string */
    private $optionsProxy;
    /** @var ?non-empty-string */
    private $optionsAuth;

    public function __construct(string $proxy, string $envName)
    {
        if (strpbrk($proxy, "\r\n\t") !== false || !$this->checkData($proxy)) {
            throw new \RuntimeException(sprintf('unsupported `%s` syntax', $envName));
        }
    }

    /**
     * Returns stream context options for the proxy
     *
     * @param string $scheme The scheme of the request url
     * @return contextOptions
     */
    public function getContextOptions(string $scheme): array
    {
        $options = ['http' => ['proxy' => $this->optionsProxy]];

        if ($this->optionsAuth !== null) {
            $options['http']['header'] = $this->optionsAuth;
        }

        if ($scheme === 'http') {
            $options['http']['request_fulluri'] = true;
        }

        return $options;
    }

    /**
     * Returns any proxy authorization for curl
     *
     * @return ?non-empty-string
     */
    public function getCurlAuth(): ?string
    {
        return $this->curlAuth;
    }

    /**
     * Returns the proxy url without user data
     *
     * @return non-empty-string
     */
    public function getProxyUrl(): string
    {
        return $this->url;
    }

    /**
     * Returns the complete proxy url with sanitized user data
     *
     * @return non-empty-string
     */
    public function getSafeUrl(): string
    {
        return $this->safeUrl;
    }

    /**
     * Checks the proxy url and sets the class properties
     */
    private function checkData(string $url): bool
    {
        $proxy = parse_url($url);

        // We need parse_url to have identified a host
        if (!isset($proxy['host'])) {
            return false;
        }

        $scheme = isset($proxy['scheme']) ? strtolower($proxy['scheme']) . '://' : 'http://';
        $safe = '';

        if (isset($proxy['user'])) {
            $safe = '***';
            $user = $proxy['user'];
            $auth = rawurldecode($proxy['user']);

            if (isset($proxy['pass'])) {
                $safe .= ':***';
                $user .= ':' . $proxy['pass'];
                $auth .= ':' . rawurldecode($proxy['pass']);
            }

            $safe .= '@';

            if (strlen($user) > 0) {
                $this->curlAuth = $user;
                $this->optionsAuth = 'Proxy-Authorization: Basic ' . base64_encode($auth);
            }
        }

        $host = $proxy['host'];
        $port = null;

        if (isset($proxy['port'])) {
            $port = $proxy['port'];
        } elseif ($scheme === 'http://') {
            $port = 80;
        } elseif ($scheme === 'https://') {
            $port = 443;
        }

        // We need a port because curl uses 1080 for http. Port 0 is reserved,
        // but is considered valid depending on the PHP or Curl version.
        if ($port === null || $port === 0) {
            return false;
        }

        $this->url = sprintf('%s%s:%d', $scheme, $host, $port);
        $this->safeUrl = sprintf('%s%s%s:%d', $scheme, $safe, $host, $port);

        $scheme = str_replace(['http://', 'https://'], ['tcp://', 'ssl://'], $scheme);
        $this->optionsProxy = sprintf('%s%s:%d', $scheme, $host, $port);

        return true;
    }
}
