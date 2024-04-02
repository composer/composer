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
    /** @var string */
    private $optionsProxy;
    /** @var string|null */
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
     * Returns the complete proxy url for use with curl
     *
     * @return non-empty-string
     */
    public function getProxyUrl(): string
    {
        return $this->url;
    }

    /**
     * Returns the proxy url with sanitized user data
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

        $scheme = isset($proxy['scheme']) ? strtolower($proxy['scheme']) . '://' : '';
        $user = '';
        $safe = '';

        if (isset($proxy['user'])) {
            $user = $proxy['user'];
            $auth = rawurldecode($proxy['user']);
            $safe = '***';

            if (isset($proxy['pass'])) {
                $user .= ':' . $proxy['pass'];
                $auth .= ':' . rawurldecode($proxy['pass']);
                $safe .= ':***';
            }

            $user .= '@';
            $safe .= '@';
            $this->optionsAuth = 'Proxy-Authorization: Basic ' . base64_encode($auth);
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
        // but is considered valid depending on the the PHP or Curl version.
        if ($port === null || $port === 0) {
            return false;
        }

        $this->url = sprintf('%s%s%s:%d', $scheme, $user, $host, $port);
        $this->safeUrl = sprintf('%s%s%s:%d', $scheme, $safe, $host, $port);

        $scheme = str_replace(['http://', 'https://'], ['tcp://', 'ssl://'], $scheme);
        $this->optionsProxy = sprintf('%s%s:%d', $scheme, $host, $port);

        return true;
    }
}
