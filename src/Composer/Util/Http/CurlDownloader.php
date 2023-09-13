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

use Composer\Config;
use Composer\Downloader\MaxFileSizeExceededException;
use Composer\IO\IOInterface;
use Composer\Downloader\TransportException;
use Composer\Pcre\Preg;
use Composer\Util\Platform;
use Composer\Util\StreamContextFactory;
use Composer\Util\AuthHelper;
use Composer\Util\Url;
use Composer\Util\HttpDownloader;
use React\Promise\Promise;

/**
 * @internal
 * @author Jordi Boggiano <j.boggiano@seld.be>
 * @author Nicolas Grekas <p@tchwork.com>
 * @phpstan-type Attributes array{retryAuthFailure: bool, redirects: int<0, max>, retries: int<0, max>, storeAuth: 'prompt'|bool}
 * @phpstan-type Job array{url: non-empty-string, origin: string, attributes: Attributes, options: mixed[], progress: mixed[], curlHandle: \CurlHandle, filename: string|null, headerHandle: resource, bodyHandle: resource, resolve: callable, reject: callable}
 */
class CurlDownloader
{
    /** @var ?resource */
    private $multiHandle;
    /** @var ?resource */
    private $shareHandle;
    /** @var Job[] */
    private $jobs = [];
    /** @var IOInterface */
    private $io;
    /** @var Config */
    private $config;
    /** @var AuthHelper */
    private $authHelper;
    /** @var float */
    private $selectTimeout = 5.0;
    /** @var int */
    private $maxRedirects = 20;
    /** @var int */
    private $maxRetries = 3;
    /** @var ProxyManager */
    private $proxyManager;
    /** @var bool */
    private $supportsSecureProxy;
    /** @var array<int, string[]> */
    protected $multiErrors = [
        CURLM_BAD_HANDLE => ['CURLM_BAD_HANDLE', 'The passed-in handle is not a valid CURLM handle.'],
        CURLM_BAD_EASY_HANDLE => ['CURLM_BAD_EASY_HANDLE', "An easy handle was not good/valid. It could mean that it isn't an easy handle at all, or possibly that the handle already is in used by this or another multi handle."],
        CURLM_OUT_OF_MEMORY => ['CURLM_OUT_OF_MEMORY', 'You are doomed.'],
        CURLM_INTERNAL_ERROR => ['CURLM_INTERNAL_ERROR', 'This can only be returned if libcurl bugs. Please report it to us!'],
    ];

    /** @var mixed[] */
    private static $options = [
        'http' => [
            'method' => CURLOPT_CUSTOMREQUEST,
            'content' => CURLOPT_POSTFIELDS,
            'header' => CURLOPT_HTTPHEADER,
            'timeout' => CURLOPT_TIMEOUT,
        ],
        'ssl' => [
            'cafile' => CURLOPT_CAINFO,
            'capath' => CURLOPT_CAPATH,
            'verify_peer' => CURLOPT_SSL_VERIFYPEER,
            'verify_peer_name' => CURLOPT_SSL_VERIFYHOST,
            'local_cert' => CURLOPT_SSLCERT,
            'local_pk' => CURLOPT_SSLKEY,
            'passphrase' => CURLOPT_SSLKEYPASSWD,
        ],
    ];

    /** @var array<string, true> */
    private static $timeInfo = [
        'total_time' => true,
        'namelookup_time' => true,
        'connect_time' => true,
        'pretransfer_time' => true,
        'starttransfer_time' => true,
        'redirect_time' => true,
    ];

    /**
     * @param mixed[] $options
     */
    public function __construct(IOInterface $io, Config $config, array $options = [], bool $disableTls = false)
    {
        $this->io = $io;
        $this->config = $config;

        $this->multiHandle = $mh = curl_multi_init();
        if (function_exists('curl_multi_setopt')) {
            curl_multi_setopt($mh, CURLMOPT_PIPELINING, PHP_VERSION_ID >= 70400 ? /* CURLPIPE_MULTIPLEX */ 2 : /*CURLPIPE_HTTP1 | CURLPIPE_MULTIPLEX*/ 3);
            if (defined('CURLMOPT_MAX_HOST_CONNECTIONS') && !defined('HHVM_VERSION')) {
                curl_multi_setopt($mh, CURLMOPT_MAX_HOST_CONNECTIONS, 8);
            }
        }

        if (function_exists('curl_share_init')) {
            $this->shareHandle = $sh = curl_share_init();
            curl_share_setopt($sh, CURLSHOPT_SHARE, CURL_LOCK_DATA_COOKIE);
            curl_share_setopt($sh, CURLSHOPT_SHARE, CURL_LOCK_DATA_DNS);
            curl_share_setopt($sh, CURLSHOPT_SHARE, CURL_LOCK_DATA_SSL_SESSION);
        }

        $this->authHelper = new AuthHelper($io, $config);
        $this->proxyManager = ProxyManager::getInstance();

        $version = curl_version();
        $features = $version['features'];
        $this->supportsSecureProxy = defined('CURL_VERSION_HTTPS_PROXY') && ($features & CURL_VERSION_HTTPS_PROXY);
    }

    /**
     * @param mixed[]  $options
     * @param non-empty-string $url
     *
     * @return int internal job id
     */
    public function download(callable $resolve, callable $reject, string $origin, string $url, array $options, ?string $copyTo = null): int
    {
        $attributes = [];
        if (isset($options['retry-auth-failure'])) {
            $attributes['retryAuthFailure'] = $options['retry-auth-failure'];
            unset($options['retry-auth-failure']);
        }

        return $this->initDownload($resolve, $reject, $origin, $url, $options, $copyTo, $attributes);
    }

    /**
     * @param mixed[]  $options
     *
     * @param array{retryAuthFailure?: bool, redirects?: int<0, max>, retries?: int<0, max>, storeAuth?: 'prompt'|bool} $attributes
     * @param non-empty-string $url
     *
     * @return int internal job id
     */
    private function initDownload(callable $resolve, callable $reject, string $origin, string $url, array $options, ?string $copyTo = null, array $attributes = []): int
    {
        $attributes = array_merge([
            'retryAuthFailure' => true,
            'redirects' => 0,
            'retries' => 0,
            'storeAuth' => false,
        ], $attributes);

        $originalOptions = $options;

        // check URL can be accessed (i.e. is not insecure), but allow insecure Packagist calls to $hashed providers as file integrity is verified with sha256
        if (!Preg::isMatch('{^http://(repo\.)?packagist\.org/p/}', $url) || (false === strpos($url, '$') && false === strpos($url, '%24'))) {
            $this->config->prohibitUrlByConfig($url, $this->io, $options);
        }

        $curlHandle = curl_init();
        $headerHandle = fopen('php://temp/maxmemory:32768', 'w+b');
        if (false === $headerHandle) {
            throw new \RuntimeException('Failed to open a temp stream to store curl headers');
        }

        if ($copyTo !== null) {
            $bodyTarget = $copyTo.'~';
        } else {
            $bodyTarget = 'php://temp/maxmemory:524288';
        }

        $errorMessage = '';
        // @phpstan-ignore-next-line
        set_error_handler(static function ($code, $msg) use (&$errorMessage): void {
            if ($errorMessage) {
                $errorMessage .= "\n";
            }
            $errorMessage .= Preg::replace('{^fopen\(.*?\): }', '', $msg);
        });
        $bodyHandle = fopen($bodyTarget, 'w+b');
        restore_error_handler();
        if (false === $bodyHandle) {
            throw new TransportException('The "'.$url.'" file could not be written to '.($copyTo ?? 'a temporary file').': '.$errorMessage);
        }

        curl_setopt($curlHandle, CURLOPT_URL, $url);
        curl_setopt($curlHandle, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($curlHandle, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($curlHandle, CURLOPT_TIMEOUT, max((int) ini_get("default_socket_timeout"), 300));
        curl_setopt($curlHandle, CURLOPT_WRITEHEADER, $headerHandle);
        curl_setopt($curlHandle, CURLOPT_FILE, $bodyHandle);
        curl_setopt($curlHandle, CURLOPT_ENCODING, ""); // let cURL set the Accept-Encoding header to what it supports
        curl_setopt($curlHandle, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);

        if (function_exists('curl_share_init')) {
            curl_setopt($curlHandle, CURLOPT_SHARE, $this->shareHandle);
        }

        if (!isset($options['http']['header'])) {
            $options['http']['header'] = [];
        }

        $options['http']['header'] = array_diff($options['http']['header'], ['Connection: close']);
        $options['http']['header'][] = 'Connection: keep-alive';

        $version = curl_version();
        $features = $version['features'];
        if (0 === strpos($url, 'https://') && \defined('CURL_VERSION_HTTP2') && \defined('CURL_HTTP_VERSION_2_0') && (CURL_VERSION_HTTP2 & $features)) {
            curl_setopt($curlHandle, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_2_0);
        }

        $options['http']['header'] = $this->authHelper->addAuthenticationHeader($options['http']['header'], $origin, $url);
        $options = StreamContextFactory::initOptions($url, $options, true);

        foreach (self::$options as $type => $curlOptions) {
            foreach ($curlOptions as $name => $curlOption) {
                if (isset($options[$type][$name])) {
                    if ($type === 'ssl' && $name === 'verify_peer_name') {
                        curl_setopt($curlHandle, $curlOption, $options[$type][$name] === true ? 2 : $options[$type][$name]);
                    } else {
                        curl_setopt($curlHandle, $curlOption, $options[$type][$name]);
                    }
                }
            }
        }

        // Always set CURLOPT_PROXY to enable/disable proxy handling
        // Any proxy authorization is included in the proxy url
        $proxy = $this->proxyManager->getProxyForRequest($url);
        if ($proxy->getUrl() !== '') {
            curl_setopt($curlHandle, CURLOPT_PROXY, $proxy->getUrl());
        }

        // Curl needs certificate locations for secure proxies.
        // CURLOPT_PROXY_SSL_VERIFY_PEER/HOST are enabled by default
        if ($proxy->isSecure()) {
            if (!$this->supportsSecureProxy) {
                throw new TransportException('Connecting to a secure proxy using curl is not supported on PHP versions below 7.3.0.');
            }
            if (!empty($options['ssl']['cafile'])) {
                curl_setopt($curlHandle, CURLOPT_PROXY_CAINFO, $options['ssl']['cafile']);
            }
            if (!empty($options['ssl']['capath'])) {
                curl_setopt($curlHandle, CURLOPT_PROXY_CAPATH, $options['ssl']['capath']);
            }
        }

        $progress = array_diff_key(curl_getinfo($curlHandle), self::$timeInfo);

        $this->jobs[(int) $curlHandle] = [
            'url' => $url,
            'origin' => $origin,
            'attributes' => $attributes,
            'options' => $originalOptions,
            'progress' => $progress,
            'curlHandle' => $curlHandle,
            'filename' => $copyTo,
            'headerHandle' => $headerHandle,
            'bodyHandle' => $bodyHandle,
            'resolve' => $resolve,
            'reject' => $reject,
        ];

        $usingProxy = $proxy->getFormattedUrl(' using proxy (%s)');
        $ifModified = false !== stripos(implode(',', $options['http']['header']), 'if-modified-since:') ? ' if modified' : '';
        if ($attributes['redirects'] === 0 && $attributes['retries'] === 0) {
            $this->io->writeError('Downloading ' . Url::sanitize($url) . $usingProxy . $ifModified, true, IOInterface::DEBUG);
        }

        $this->checkCurlResult(curl_multi_add_handle($this->multiHandle, $curlHandle));
        // TODO progress

        return (int) $curlHandle;
    }

    public function abortRequest(int $id): void
    {
        if (isset($this->jobs[$id], $this->jobs[$id]['curlHandle'])) {
            $job = $this->jobs[$id];
            curl_multi_remove_handle($this->multiHandle, $job['curlHandle']);
            curl_close($job['curlHandle']);
            if (is_resource($job['headerHandle'])) {
                fclose($job['headerHandle']);
            }
            if (is_resource($job['bodyHandle'])) {
                fclose($job['bodyHandle']);
            }
            if (null !== $job['filename']) {
                @unlink($job['filename'].'~');
            }
            unset($this->jobs[$id]);
        }
    }

    public function tick(): void
    {
        static $timeoutWarning = false;

        if (count($this->jobs) === 0) {
            return;
        }

        $active = true;
        $this->checkCurlResult(curl_multi_exec($this->multiHandle, $active));
        if (-1 === curl_multi_select($this->multiHandle, $this->selectTimeout)) {
            // sleep in case select returns -1 as it can happen on old php versions or some platforms where curl does not manage to do the select
            usleep(150);
        }

        while ($progress = curl_multi_info_read($this->multiHandle)) {
            $curlHandle = $progress['handle'];
            $result = $progress['result'];
            $i = (int) $curlHandle;
            if (!isset($this->jobs[$i])) {
                continue;
            }

            $progress = curl_getinfo($curlHandle);
            if (false === $progress) {
                throw new \RuntimeException('Failed getting info from curl handle '.$i.' ('.$this->jobs[$i]['url'].')');
            }
            $job = $this->jobs[$i];
            unset($this->jobs[$i]);
            $error = curl_error($curlHandle);
            $errno = curl_errno($curlHandle);
            curl_multi_remove_handle($this->multiHandle, $curlHandle);
            curl_close($curlHandle);

            $headers = null;
            $statusCode = null;
            $response = null;
            try {
                // TODO progress
                if (CURLE_OK !== $errno || $error || $result !== CURLE_OK) {
                    $errno = $errno ?: $result;
                    if (!$error && function_exists('curl_strerror')) {
                        $error = curl_strerror($errno);
                    }
                    $progress['error_code'] = $errno;

                    if (
                        (!isset($job['options']['http']['method']) || $job['options']['http']['method'] === 'GET')
                        && (
                            in_array($errno, [7 /* CURLE_COULDNT_CONNECT */, 16 /* CURLE_HTTP2 */, 92 /* CURLE_HTTP2_STREAM */, 6 /* CURLE_COULDNT_RESOLVE_HOST */], true)
                            || (in_array($errno, [56 /* CURLE_RECV_ERROR */, 35 /* CURLE_SSL_CONNECT_ERROR */], true) && str_contains((string) $error, 'Connection reset by peer'))
                        ) && $job['attributes']['retries'] < $this->maxRetries
                    ) {
                        $this->io->writeError('Retrying ('.($job['attributes']['retries'] + 1).') ' . Url::sanitize($job['url']) . ' due to curl error '. $errno, true, IOInterface::DEBUG);
                        $this->restartJobWithDelay($job, $job['url'], ['retries' => $job['attributes']['retries'] + 1]);
                        continue;
                    }

                    // TODO: Remove this as soon as https://github.com/curl/curl/issues/10591 is resolved
                    if ($errno === 55 /* CURLE_SEND_ERROR */) {
                        $this->io->writeError('Retrying ('.($job['attributes']['retries'] + 1).') ' . Url::sanitize($job['url']) . ' due to curl error '. $errno, true, IOInterface::DEBUG);
                        $this->restartJobWithDelay($job, $job['url'], ['retries' => $job['attributes']['retries'] + 1]);
                        continue;
                    }

                    if ($errno === 28 /* CURLE_OPERATION_TIMEDOUT */ && PHP_VERSION_ID >= 70300 && $progress['namelookup_time'] === 0.0 && !$timeoutWarning) {
                        $timeoutWarning = true;
                        $this->io->writeError('<warning>A connection timeout was encountered. If you intend to run Composer without connecting to the internet, run the command again prefixed with COMPOSER_DISABLE_NETWORK=1 to make Composer run in offline mode.</warning>');
                    }

                    throw new TransportException('curl error '.$errno.' while downloading '.Url::sanitize($progress['url']).': '.$error);
                }
                $statusCode = $progress['http_code'];
                rewind($job['headerHandle']);
                $headers = explode("\r\n", rtrim(stream_get_contents($job['headerHandle'])));
                fclose($job['headerHandle']);

                if ($statusCode === 0) {
                    throw new \LogicException('Received unexpected http status code 0 without error for '.Url::sanitize($progress['url']).': headers '.var_export($headers, true).' curl info '.var_export($progress, true));
                }

                // prepare response object
                if (null !== $job['filename']) {
                    $contents = $job['filename'].'~';
                    if ($statusCode >= 300) {
                        rewind($job['bodyHandle']);
                        $contents = stream_get_contents($job['bodyHandle']);
                    }
                    $response = new CurlResponse(['url' => $job['url']], $statusCode, $headers, $contents, $progress);
                    $this->io->writeError('['.$statusCode.'] '.Url::sanitize($job['url']), true, IOInterface::DEBUG);
                } else {
                    $maxFileSize = $job['options']['max_file_size'] ?? null;
                    rewind($job['bodyHandle']);
                    if ($maxFileSize !== null) {
                        $contents = stream_get_contents($job['bodyHandle'], $maxFileSize);
                        // Gzipped responses with missing Content-Length header cannot be detected during the file download
                        // because $progress['size_download'] refers to the gzipped size downloaded, not the actual file size
                        if ($contents !== false && Platform::strlen($contents) >= $maxFileSize) {
                            throw new MaxFileSizeExceededException('Maximum allowed download size reached. Downloaded ' . Platform::strlen($contents) . ' of allowed ' .  $maxFileSize . ' bytes');
                        }
                    } else {
                        $contents = stream_get_contents($job['bodyHandle']);
                    }

                    $response = new CurlResponse(['url' => $job['url']], $statusCode, $headers, $contents, $progress);
                    $this->io->writeError('['.$statusCode.'] '.Url::sanitize($job['url']), true, IOInterface::DEBUG);
                }
                fclose($job['bodyHandle']);

                if ($response->getStatusCode() >= 400 && $response->getHeader('content-type') === 'application/json') {
                    HttpDownloader::outputWarnings($this->io, $job['origin'], json_decode($response->getBody(), true));
                }

                $result = $this->isAuthenticatedRetryNeeded($job, $response);
                if ($result['retry']) {
                    $this->restartJob($job, $job['url'], ['storeAuth' => $result['storeAuth'], 'retries' => $job['attributes']['retries'] + 1]);
                    continue;
                }

                // handle 3xx redirects, 304 Not Modified is excluded
                if ($statusCode >= 300 && $statusCode <= 399 && $statusCode !== 304 && $job['attributes']['redirects'] < $this->maxRedirects) {
                    $location = $this->handleRedirect($job, $response);
                    if ($location) {
                        $this->restartJob($job, $location, ['redirects' => $job['attributes']['redirects'] + 1]);
                        continue;
                    }
                }

                // fail 4xx and 5xx responses and capture the response
                if ($statusCode >= 400 && $statusCode <= 599) {
                    if (
                        (!isset($job['options']['http']['method']) || $job['options']['http']['method'] === 'GET')
                        && in_array($statusCode, [423, 425, 500, 502, 503, 504, 507, 510], true)
                        && $job['attributes']['retries'] < $this->maxRetries
                    ) {
                        $this->io->writeError('Retrying ('.($job['attributes']['retries'] + 1).') ' . Url::sanitize($job['url']) . ' due to status code '. $statusCode, true, IOInterface::DEBUG);
                        $this->restartJobWithDelay($job, $job['url'], ['retries' => $job['attributes']['retries'] + 1]);
                        continue;
                    }

                    throw $this->failResponse($job, $response, $response->getStatusMessage());
                }

                if ($job['attributes']['storeAuth'] !== false) {
                    $this->authHelper->storeAuth($job['origin'], $job['attributes']['storeAuth']);
                }

                // resolve promise
                if (null !== $job['filename']) {
                    rename($job['filename'].'~', $job['filename']);
                    $job['resolve']($response);
                } else {
                    $job['resolve']($response);
                }
            } catch (\Exception $e) {
                if ($e instanceof TransportException) {
                    if (null !== $headers) {
                        $e->setHeaders($headers);
                        $e->setStatusCode($statusCode);
                    }
                    if (null !== $response) {
                        $e->setResponse($response->getBody());
                    }
                    $e->setResponseInfo($progress);
                }

                $this->rejectJob($job, $e);
            }
        }

        foreach ($this->jobs as $i => $curlHandle) {
            $curlHandle = $this->jobs[$i]['curlHandle'];
            $progress = array_diff_key(curl_getinfo($curlHandle), self::$timeInfo);

            if ($this->jobs[$i]['progress'] !== $progress) {
                $this->jobs[$i]['progress'] = $progress;

                if (isset($this->jobs[$i]['options']['max_file_size'])) {
                    // Compare max_file_size with the content-length header this value will be -1 until the header is parsed
                    if ($this->jobs[$i]['options']['max_file_size'] < $progress['download_content_length']) {
                        $this->rejectJob($this->jobs[$i], new MaxFileSizeExceededException('Maximum allowed download size reached. Content-length header indicates ' . $progress['download_content_length'] . ' bytes. Allowed ' .  $this->jobs[$i]['options']['max_file_size'] . ' bytes'));
                    }

                    // Compare max_file_size with the download size in bytes
                    if ($this->jobs[$i]['options']['max_file_size'] < $progress['size_download']) {
                        $this->rejectJob($this->jobs[$i], new MaxFileSizeExceededException('Maximum allowed download size reached. Downloaded ' . $progress['size_download'] . ' of allowed ' .  $this->jobs[$i]['options']['max_file_size'] . ' bytes'));
                    }
                }

                // TODO progress
            }
        }
    }

    /**
     * @param  Job    $job
     */
    private function handleRedirect(array $job, Response $response): string
    {
        if ($locationHeader = $response->getHeader('location')) {
            if (parse_url($locationHeader, PHP_URL_SCHEME)) {
                // Absolute URL; e.g. https://example.com/composer
                $targetUrl = $locationHeader;
            } elseif (parse_url($locationHeader, PHP_URL_HOST)) {
                // Scheme relative; e.g. //example.com/foo
                $targetUrl = parse_url($job['url'], PHP_URL_SCHEME).':'.$locationHeader;
            } elseif ('/' === $locationHeader[0]) {
                // Absolute path; e.g. /foo
                $urlHost = parse_url($job['url'], PHP_URL_HOST);

                // Replace path using hostname as an anchor.
                $targetUrl = Preg::replace('{^(.+(?://|@)'.preg_quote($urlHost).'(?::\d+)?)(?:[/\?].*)?$}', '\1'.$locationHeader, $job['url']);
            } else {
                // Relative path; e.g. foo
                // This actually differs from PHP which seems to add duplicate slashes.
                $targetUrl = Preg::replace('{^(.+/)[^/?]*(?:\?.*)?$}', '\1'.$locationHeader, $job['url']);
            }
        }

        if (!empty($targetUrl)) {
            $this->io->writeError(sprintf('Following redirect (%u) %s', $job['attributes']['redirects'] + 1, Url::sanitize($targetUrl)), true, IOInterface::DEBUG);

            return $targetUrl;
        }

        throw new TransportException('The "'.$job['url'].'" file could not be downloaded, got redirect without Location ('.$response->getStatusMessage().')');
    }

    /**
     * @param  Job                                          $job
     * @return array{retry: bool, storeAuth: 'prompt'|bool}
     */
    private function isAuthenticatedRetryNeeded(array $job, Response $response): array
    {
        if (in_array($response->getStatusCode(), [401, 403]) && $job['attributes']['retryAuthFailure']) {
            $result = $this->authHelper->promptAuthIfNeeded($job['url'], $job['origin'], $response->getStatusCode(), $response->getStatusMessage(), $response->getHeaders(), $job['attributes']['retries']);

            if ($result['retry']) {
                return $result;
            }
        }

        $locationHeader = $response->getHeader('location');
        $needsAuthRetry = false;

        // check for bitbucket login page asking to authenticate
        if (
            $job['origin'] === 'bitbucket.org'
            && !$this->authHelper->isPublicBitBucketDownload($job['url'])
            && substr($job['url'], -4) === '.zip'
            && (!$locationHeader || substr($locationHeader, -4) !== '.zip')
            && Preg::isMatch('{^text/html\b}i', $response->getHeader('content-type'))
        ) {
            $needsAuthRetry = 'Bitbucket requires authentication and it was not provided';
        }

        // check for gitlab 404 when downloading archives
        if (
            $response->getStatusCode() === 404
            && in_array($job['origin'], $this->config->get('gitlab-domains'), true)
            && false !== strpos($job['url'], 'archive.zip')
        ) {
            $needsAuthRetry = 'GitLab requires authentication and it was not provided';
        }

        if ($needsAuthRetry) {
            if ($job['attributes']['retryAuthFailure']) {
                $result = $this->authHelper->promptAuthIfNeeded($job['url'], $job['origin'], 401, null, [], $job['attributes']['retries']);
                if ($result['retry']) {
                    return $result;
                }
            }

            throw $this->failResponse($job, $response, $needsAuthRetry);
        }

        return ['retry' => false, 'storeAuth' => false];
    }

    /**
     * @param  Job    $job
     * @param non-empty-string $url
     *
     * @param  array{retryAuthFailure?: bool, redirects?: int<0, max>, storeAuth?: 'prompt'|bool, retries?: int<1, max>} $attributes
     */
    private function restartJob(array $job, string $url, array $attributes = []): void
    {
        if (null !== $job['filename']) {
            @unlink($job['filename'].'~');
        }

        $attributes = array_merge($job['attributes'], $attributes);
        $origin = Url::getOrigin($this->config, $url);

        $this->initDownload($job['resolve'], $job['reject'], $origin, $url, $job['options'], $job['filename'], $attributes);
    }

    /**
     * @param  Job    $job
     * @param non-empty-string $url
     *
     * @param  array{retryAuthFailure?: bool, redirects?: int<0, max>, storeAuth?: 'prompt'|bool, retries: int<1, max>} $attributes
     */
    private function restartJobWithDelay(array $job, string $url, array $attributes): void
    {
        if ($attributes['retries'] >= 3) {
            usleep(500000); // half a second delay for 3rd retry and beyond
        } elseif ($attributes['retries'] >= 2) {
            usleep(100000); // 100ms delay for 2nd retry
        } // no sleep for the first retry

        $this->restartJob($job, $url, $attributes);
    }

    /**
     * @param  Job                $job
     */
    private function failResponse(array $job, Response $response, string $errorMessage): TransportException
    {
        if (null !== $job['filename']) {
            @unlink($job['filename'].'~');
        }

        $details = '';
        if (in_array(strtolower((string) $response->getHeader('content-type')), ['application/json', 'application/json; charset=utf-8'], true)) {
            $details = ':'.PHP_EOL.substr($response->getBody(), 0, 200).(strlen($response->getBody()) > 200 ? '...' : '');
        }

        return new TransportException('The "'.$job['url'].'" file could not be downloaded ('.$errorMessage.')' . $details, $response->getStatusCode());
    }

    /**
     * @param  Job                $job
     */
    private function rejectJob(array $job, \Exception $e): void
    {
        if (is_resource($job['headerHandle'])) {
            fclose($job['headerHandle']);
        }
        if (is_resource($job['bodyHandle'])) {
            fclose($job['bodyHandle']);
        }
        if (null !== $job['filename']) {
            @unlink($job['filename'].'~');
        }
        $job['reject']($e);
    }

    private function checkCurlResult(int $code): void
    {
        if ($code !== CURLM_OK && $code !== CURLM_CALL_MULTI_PERFORM) {
            throw new \RuntimeException(
                isset($this->multiErrors[$code])
                ? "cURL error: {$code} ({$this->multiErrors[$code][0]}): cURL message: {$this->multiErrors[$code][1]}"
                : 'Unexpected cURL error: ' . $code
            );
        }
    }
}
