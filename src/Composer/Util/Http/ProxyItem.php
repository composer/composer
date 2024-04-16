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

    /**
     * @param string $proxyUrl The value from the environment
     * @param string $envName The name of the environment variable
     * @throws \RuntimeException If the proxy url is invalid
     */
    public function __construct(string $proxyUrl, string $envName)
    {
        $syntaxError = sprintf('unsupported `%s` syntax', $envName);

        if (strpbrk($proxyUrl, "\r\n\t") !== false) {
            throw new \RuntimeException($syntaxError);
        }
        if (false === ($proxy = parse_url($proxyUrl))) {
            throw new \RuntimeException($syntaxError);
        }
        if (!isset($proxy['host'])) {
            throw new \RuntimeException('unable to find proxy host in ' . $envName);
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
        if ($port === null) {
            throw new \RuntimeException('unable to find proxy port in ' . $envName);
        }
        if ($port === 0) {
            throw new \RuntimeException('port 0 is reserved in ' . $envName);
        }

        $this->url = sprintf('%s%s:%d', $scheme, $host, $port);
        $this->safeUrl = sprintf('%s%s%s:%d', $scheme, $safe, $host, $port);

        $scheme = str_replace(['http://', 'https://'], ['tcp://', 'ssl://'], $scheme);
        $this->optionsProxy = sprintf('%s%s:%d', $scheme, $host, $port);
    }

    /**
     * Returns a RequestProxy instance for the scheme of the request url
     *
     * @param string $scheme The scheme of the request url
     */
    public function toRequestProxy(string $scheme): RequestProxy
    {
        $options = ['http' => ['proxy' => $this->optionsProxy]];

        if ($this->optionsAuth !== null) {
            $options['http']['header'] = $this->optionsAuth;
        }

        if ($scheme === 'http') {
            $options['http']['request_fulluri'] = true;
        }

        return new RequestProxy($this->url, $this->curlAuth, $options, $this->safeUrl);
    }
}
