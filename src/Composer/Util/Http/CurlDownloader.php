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
 * @phpstan-type Attributes array{retryAuthFailure: bool, redirects: int<0, max>, retries: int<0, max>, storeAuth: 'prompt'|bool, ipResolve: 4|6|null}
 * @phpstan-type Job array{url: non-empty-string, origin: string, attributes: Attributes, options: mixed[], progress: mixed[], curlHandle: \CurlHandle, filename: string|null, headerHandle: resource, bodyHandle: resource, resolve: callable, reject: callable, primaryIp: string}
 * @phpstan-type RetryAttributes array{retryAuthFailure?: bool, redirects?: int<0, max>, storeAuth?: 'prompt'|bool, retries: int<1, max>, ipResolve?: 4|6}
 * @phpstan-type DelayedJob array{job: Job, url: non-empty-string, attributes: RetryAttributes, at: float}
 */
class CurlDownloader
{
    /**
     * Known libcurl's broken versions when proxy is in use with HTTP/2
     * multiplexing.
     *
     * @var list<non-empty-string>
     */
    private const BAD_MULTIPLEXING_CURL_VERSIONS = ['7.87.0', '7.88.0', '7.88.1'];

    /**
     * Longest Retry-After interval which is waited out, in seconds
     *
     * Without a ceiling a server could park an install for as long as it likes, see isStatusCodeRetryNeeded()
     */
    private const MAX_RETRY_AFTER = 60;

    /** @var \CurlMultiHandle */
    private $multiHandle;
    /** @var \CurlShareHandle */
    private $shareHandle;
    /** @var Job[] */
    private $jobs = [];
    /**
     * Retries which are waiting for their delay to elapse, see restartJobWithDelay()
     *
     * @var array<int, DelayedJob>
     */
    private $delayedJobs = [];
    /**
     * Origins which asked for requests to be retried later, keyed by origin, see restartJobWithDelay()
     *
     * until is the latest point in time the origin asked to be left alone for, see getDueAt()
     *
     * @var array<string, array{statusCode: int, announced: bool, until: float}>
     */
    private $retryAfterOrigins = [];
    /**
     * Warnings which were already shown, keyed by origin and warning, see outputWarnings()
     *
     * @var array<string, true>
     */
    private $shownWarnings = [];
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
            if (ProxyManager::getInstance()->hasProxy() && ($version = curl_version()) !== false && in_array($version['version'], self::BAD_MULTIPLEXING_CURL_VERSIONS, true)) {
                /**
                 * Disable HTTP/2 multiplexing for some broken versions of libcurl.
                 *
                 * In certain versions of libcurl when proxy is in use with HTTP/2
                 * multiplexing, connections will continue stacking up. This was
                 * fixed in libcurl 8.0.0 in curl/curl@821f6e2a89de8aec1c7da3c0f381b92b2b801efc
                 */
                curl_multi_setopt($mh, CURLMOPT_PIPELINING, /* CURLPIPE_NOTHING */ 0);
            } else {
                curl_multi_setopt($mh, CURLMOPT_PIPELINING, \PHP_VERSION_ID >= 70400 ? /* CURLPIPE_MULTIPLEX */ 2 : /*CURLPIPE_HTTP1 | CURLPIPE_MULTIPLEX*/ 3);
            }
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
     * @param array{retryAuthFailure?: bool, redirects?: int<0, max>, retries?: int<0, max>, storeAuth?: 'prompt'|bool, ipResolve?: 4|6|null} $attributes
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
            'ipResolve' => null,
        ], $attributes);

        if ($attributes['ipResolve'] === null && Platform::getEnv('COMPOSER_IPRESOLVE') === '4') {
            $attributes['ipResolve'] = 4;
        } elseif ($attributes['ipResolve'] === null && Platform::getEnv('COMPOSER_IPRESOLVE') === '6') {
            $attributes['ipResolve'] = 6;
        }

        $originalOptions = $options;

        // check URL can be accessed (i.e. is not insecure), but allow insecure Packagist calls to $hashed providers as file integrity is verified with sha256
        if (!Preg::isMatch('{^http://(repo\.)?packagist\.org/p/}', $url) || (false === strpos($url, '$') && false === strpos($url, '%24'))) {
            $this->config->prohibitUrlByConfig($url, $this->io, $options);
        }

        if (
            isset($options['prevent_url_access_callable']) &&
            is_callable($options['prevent_url_access_callable']) &&
            $options['prevent_url_access_callable']($url)
        ) {
            throw new TransportException('Access to "'.Url::sanitize($url).'" is blocked.');
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
        set_error_handler(static function (int $code, string $msg) use (&$errorMessage): bool {
            if ($errorMessage) {
                $errorMessage .= "\n";
            }
            $errorMessage .= Preg::replace('{^fopen\(.*?\): }', '', $msg);

            return true;
        });
        $bodyHandle = fopen($bodyTarget, 'w+b');
        restore_error_handler();
        if (false === $bodyHandle) {
            throw new TransportException('The "'.Url::sanitize($url).'" file could not be written to '.($copyTo ?? 'a temporary file').': '.$errorMessage);
        }

        curl_setopt($curlHandle, CURLOPT_URL, $url);
        curl_setopt($curlHandle, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($curlHandle, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($curlHandle, CURLOPT_TIMEOUT, max((int) ini_get("default_socket_timeout"), 300));
        curl_setopt($curlHandle, CURLOPT_WRITEHEADER, $headerHandle);
        curl_setopt($curlHandle, CURLOPT_FILE, $bodyHandle);
        curl_setopt($curlHandle, CURLOPT_ENCODING, ""); // let cURL set the Accept-Encoding header to what it supports
        curl_setopt($curlHandle, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);

        if ($attributes['ipResolve'] === 4) {
            curl_setopt($curlHandle, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        } elseif ($attributes['ipResolve'] === 6) {
            curl_setopt($curlHandle, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V6);
        }

        if ($attributes['retries'] > 0) {
            // when retrying, do not re-use a kept-alive connection from the pool as it may be the cause of the failure
            curl_setopt($curlHandle, CURLOPT_FRESH_CONNECT, true);
        }

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

        $proxy = ProxyManager::getInstance()->getProxyForRequest($url);

        if (0 === strpos($url, 'https://')) {
            $willUseProxy = $proxy->getStatus() !== '' && !$proxy->isExcludedByNoProxy();

            if (!$willUseProxy && \defined('CURL_VERSION_HTTP3') && \defined('CURL_HTTP_VERSION_3') && (CURL_VERSION_HTTP3 & $features) !== 0) {
                curl_setopt($curlHandle, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_3);
            } elseif (\defined('CURL_VERSION_HTTP2') && \defined('CURL_HTTP_VERSION_2_0') && (CURL_VERSION_HTTP2 & $features) !== 0) {
                curl_setopt($curlHandle, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_2_0);
            }
        }

        // curl 8.7.0 - 8.7.1 has a bug whereas automatic accept-encoding header results in an error when reading the response
        // https://github.com/composer/composer/issues/11913
        if (isset($version['version']) && in_array($version['version'], ['8.7.0', '8.7.1'], true) && \defined('CURL_VERSION_LIBZ') && (CURL_VERSION_LIBZ & $features) !== 0) {
            curl_setopt($curlHandle, CURLOPT_ENCODING, "gzip");
        }

        $options = $this->authHelper->addAuthenticationOptions($options, $origin, $url);
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

        curl_setopt_array($curlHandle, $proxy->getCurlOptions($options['ssl'] ?? []));

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
            'primaryIp' => '',
        ];

        $usingProxy = $proxy->getStatus(' using proxy (%s)');
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
            if (\PHP_VERSION_ID < 80000) {
                curl_close($job['curlHandle']);
            }
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

        // a retry waiting for its delay is not in flight but must not be started either
        foreach ($this->delayedJobs as $i => $delayedJob) {
            if ((int) $delayedJob['job']['curlHandle'] === $id) {
                if (null !== $delayedJob['job']['filename']) {
                    @unlink($delayedJob['job']['filename'].'~');
                }
                unset($this->delayedJobs[$i]);
            }
        }
    }

    /**
     * @return int number of retries waiting for their delay, which hold no transfer open
     */
    public function countDelayedJobs(): int
    {
        return count($this->delayedJobs);
    }

    /**
     * @return bool whether the origin asked for requests to be held back until later, see restartJobWithDelay()
     */
    public function isOriginOnHold(string $origin): bool
    {
        return ($this->retryAfterOrigins[$origin]['until'] ?? 0.0) > microtime(true);
    }

    public function tick(): void
    {
        if (count($this->jobs) === 0 && count($this->delayedJobs) === 0) {
            return;
        }

        $this->restartDueJobs();

        if (count($this->jobs) === 0) {
            // only delayed retries are left, so there is no transfer this could hold up and the
            // short sleep merely avoids spinning the CPU until the next one comes due
            usleep(10000);

            return;
        }

        $active = true;
        $this->checkCurlResult(curl_multi_exec($this->multiHandle, $active));
        if (-1 === curl_multi_select($this->multiHandle, $this->getSelectTimeout())) {
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
                throw new \RuntimeException('Failed getting info from curl handle '.$i.' ('.Url::sanitize($this->jobs[$i]['url']).')');
            }
            $job = $this->jobs[$i];
            unset($this->jobs[$i]);
            $error = curl_error($curlHandle);
            $errno = curl_errno($curlHandle);
            curl_multi_remove_handle($this->multiHandle, $curlHandle);
            if (\PHP_VERSION_ID < 80000) {
                curl_close($curlHandle);
            }

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
                            in_array($errno, [7 /* CURLE_COULDNT_CONNECT */, 16 /* CURLE_HTTP2 */, 92 /* CURLE_HTTP2_STREAM */, 6 /* CURLE_COULDNT_RESOLVE_HOST */, 28 /* CURLE_OPERATION_TIMEDOUT */], true)
                            || (in_array($errno, [56 /* CURLE_RECV_ERROR */, 35 /* CURLE_SSL_CONNECT_ERROR */], true) && str_contains((string) $error, 'Connection reset by peer'))
                        ) && $job['attributes']['retries'] < $this->maxRetries
                    ) {
                        $attributes = ['retries' => $job['attributes']['retries'] + 1];
                        if ($errno === 7 && !isset($job['attributes']['ipResolve'])) { // CURLE_COULDNT_CONNECT, retry forcing IPv4 if no IP stack was selected
                            $attributes['ipResolve'] = 4;
                        }
                        $this->io->writeError('Retrying ('.($job['attributes']['retries'] + 1).') ' . Url::sanitize($job['url']) . ' due to curl error '. $errno, true, IOInterface::DEBUG);
                        $this->restartJobWithDelay($job, $job['url'], $attributes);
                        continue;
                    }

                    // TODO: Remove this as soon as https://github.com/curl/curl/issues/10591 is resolved
                    if ($errno === 55 /* CURLE_SEND_ERROR */) {
                        $this->io->writeError('Retrying ('.($job['attributes']['retries'] + 1).') ' . Url::sanitize($job['url']) . ' due to curl error '. $errno, true, IOInterface::DEBUG);
                        $this->restartJobWithDelay($job, $job['url'], ['retries' => $job['attributes']['retries'] + 1]);
                        continue;
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
                            throw new MaxFileSizeExceededException('Maximum allowed download size reached. Downloaded ' . Platform::strlen($contents) . ' of allowed ' .  $maxFileSize . ' bytes for ' . Url::sanitize($job['url']));
                        }
                    } else {
                        $contents = stream_get_contents($job['bodyHandle']);
                    }

                    $response = new CurlResponse(['url' => $job['url']], $statusCode, $headers, $contents, $progress);
                    $this->io->writeError('['.$statusCode.'] '.Url::sanitize($job['url']), true, IOInterface::DEBUG);
                }
                fclose($job['bodyHandle']);

                $warningsOutput = false;
                if ($response->getStatusCode() >= 300 && self::isJsonResponse($response)) {
                    $warningsOutput = $this->outputWarnings($job['origin'], json_decode($response->getBody(), true));
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
                    $statusRetry = $this->isStatusCodeRetryNeeded($job, $response);
                    if ($statusRetry['retry']) {
                        $this->io->writeError('Retrying ('.($job['attributes']['retries'] + 1).') ' . Url::sanitize($job['url']) . ' due to status code '. $statusCode . (null !== $statusRetry['delay'] ? ' in '.$statusRetry['delay'].'s as requested by Retry-After' : ''), true, IOInterface::DEBUG);
                        $this->restartJobWithDelay($job, $job['url'], ['retries' => $job['attributes']['retries'] + 1], $statusRetry['delay'], $statusCode);
                        continue;
                    }

                    throw $this->failResponse($job, $response, self::getStatusFailureMessage($response), $warningsOutput);
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

        $this->announceRetryAfterWaits();

        foreach ($this->jobs as $i => $curlHandle) {
            $curlHandle = $this->jobs[$i]['curlHandle'];
            $progress = array_diff_key(curl_getinfo($curlHandle), self::$timeInfo);

            if ($this->jobs[$i]['progress'] !== $progress) {
                $this->jobs[$i]['progress'] = $progress;

                if (isset($this->jobs[$i]['options']['max_file_size'])) {
                    // Compare max_file_size with the content-length header this value will be -1 until the header is parsed
                    if ($this->jobs[$i]['options']['max_file_size'] < $progress['download_content_length']) {
                        $this->rejectJob($this->jobs[$i], new MaxFileSizeExceededException('Maximum allowed download size reached. Content-length header indicates ' . $progress['download_content_length'] . ' bytes. Allowed ' .  $this->jobs[$i]['options']['max_file_size'] . ' bytes for ' . Url::sanitize($this->jobs[$i]['url'])));
                    }

                    // Compare max_file_size with the download size in bytes
                    if ($this->jobs[$i]['options']['max_file_size'] < $progress['size_download']) {
                        $this->rejectJob($this->jobs[$i], new MaxFileSizeExceededException('Maximum allowed download size reached. Downloaded ' . $progress['size_download'] . ' of allowed ' .  $this->jobs[$i]['options']['max_file_size'] . ' bytes for ' . Url::sanitize($this->jobs[$i]['url'])));
                    }
                }

                if (isset($progress['primary_ip']) && $progress['primary_ip'] !== $this->jobs[$i]['primaryIp']) {
                    if (
                        isset($this->jobs[$i]['options']['prevent_ip_access_callable']) &&
                        is_callable($this->jobs[$i]['options']['prevent_ip_access_callable']) &&
                        $this->jobs[$i]['options']['prevent_ip_access_callable']($progress['primary_ip'])
                    ) {
                        $this->rejectJob($this->jobs[$i], new TransportException(sprintf('IP "%s" is blocked for "%s".', $progress['primary_ip'], Url::sanitize($progress['url']))));
                    }

                    $this->jobs[$i]['primaryIp'] = (string) $progress['primary_ip'];
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
            if (!Url::isAllowedRedirect($targetUrl)) {
                throw new TransportException('Could not follow the redirect to "'.Url::sanitize($targetUrl).'" because only http and https redirects are supported.');
            }

            $this->io->writeError(sprintf('Following redirect (%u) %s', $job['attributes']['redirects'] + 1, Url::sanitize($targetUrl)), true, IOInterface::DEBUG);

            return $targetUrl;
        }

        throw new TransportException('The "'.Url::sanitize($job['url']).'" file could not be downloaded, got redirect without Location ('.$response->getStatusMessage().')');
    }

    /**
     * @param  Job                                          $job
     * @return array{retry: bool, storeAuth: 'prompt'|bool}
     */
    private function isAuthenticatedRetryNeeded(array $job, Response $response): array
    {
        if (in_array($response->getStatusCode(), [401, 403]) && $job['attributes']['retryAuthFailure']) {
            $result = $this->authHelper->promptAuthIfNeeded($job['url'], $job['origin'], $response->getStatusCode(), $response->getStatusMessage(), $response->getHeaders(), $job['attributes']['retries'], $response->getBody());

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
     * Decides whether a 4xx/5xx response should be retried, and how long to wait beforehand
     *
     * @param  Job $job
     * @return array{retry: bool, delay: float|null} delay is null when the default backoff applies
     */
    private function isStatusCodeRetryNeeded(array $job, Response $response): array
    {
        $noRetry = ['retry' => false, 'delay' => null];

        if (isset($job['options']['http']['method']) && $job['options']['http']['method'] !== 'GET') {
            return $noRetry;
        }

        if ($job['attributes']['retries'] >= $this->maxRetries) {
            return $noRetry;
        }

        $statusCode = $response->getStatusCode();
        $retryAfter = self::getRetryAfterDelay($response);

        $retryableByStatus = in_array($statusCode, [423, 425, 500, 502, 503, 504, 507, 510], true)
            // codeload.github.com intermittently returns 400 on reused connections, retry those specifically, see #12958
            || ($statusCode === 400 && parse_url($job['url'], PHP_URL_HOST) === 'codeload.github.com');

        // a rate limit is only retried when the server said when to come back
        $retryableByHeader = $statusCode === 429 && null !== $retryAfter;

        if (!$retryableByStatus && !$retryableByHeader) {
            return $noRetry;
        }

        // an interval beyond the cap is not waited for: a status which is retryable anyway falls
        // back to the usual backoff, a rate limit has nothing else to go on and fails
        if (null !== $retryAfter && $retryAfter > self::MAX_RETRY_AFTER) {
            if (!$retryableByStatus) {
                return $noRetry;
            }

            $retryAfter = null;
        }

        // a wait of 0s is no wait, so the usual backoff spaces such retries out instead
        return ['retry' => true, 'delay' => null !== $retryAfter && $retryAfter > 0 ? (float) $retryAfter : null];
    }

    /**
     * Reads the Retry-After header, in either of the forms RFC 9110 allows, as a number of seconds to wait
     *
     * @return int|null seconds to wait, or null if there is no usable Retry-After header
     */
    private static function getRetryAfterDelay(Response $response): ?int
    {
        $retryAfter = $response->getHeader('retry-after');
        if (null === $retryAfter) {
            return null;
        }

        // more digits would overflow an int, and are no interval anyone can mean
        if (Preg::isMatch('{^\d{1,9}$}', $retryAfter)) {
            return (int) $retryAfter;
        }

        // the three date formats RFC 9110 requires recipients to accept, with the leading day name
        // skipped as it is redundant and DateTime would otherwise move the date to match it
        foreach (['!*, d M Y H:i:s \G\M\T', '!*, d-M-y H:i:s \G\M\T', '!* M j H:i:s Y'] as $format) {
            $retryAt = \DateTime::createFromFormat($format, $retryAfter, new \DateTimeZone('UTC'));
            if (false !== $retryAt) {
                // a date which has already passed means the wait is over and we can retry straight away
                return max(0, $retryAt->getTimestamp() - time());
            }
        }

        return null;
    }

    /**
     * Starts the delayed retries whose delay has elapsed
     */
    private function restartDueJobs(): void
    {
        $now = microtime(true);
        foreach ($this->delayedJobs as $i => $delayedJob) {
            if ($this->getDueAt($delayedJob) > $now) {
                continue;
            }

            unset($this->delayedJobs[$i]);
            try {
                $this->restartJob($delayedJob['job'], $delayedJob['url'], $delayedJob['attributes']);
            } catch (\Exception $e) {
                // an immediate restart is covered by tick()'s try/catch, a deferred one is not
                $this->rejectJob($delayedJob['job'], $e);
            }
        }
    }

    /**
     * @return float how long curl_multi_select may block for, in seconds
     */
    private function getSelectTimeout(): float
    {
        $timeout = $this->selectTimeout;
        $now = microtime(true);
        foreach ($this->delayedJobs as $delayedJob) {
            // never sleep past the point where a delayed retry comes due
            $timeout = min($timeout, max(0.0, $this->getDueAt($delayedJob) - $now));
        }

        return $timeout;
    }

    /**
     * A Retry-After speaks for the whole origin, so a retry also waits for a later hold the origin asked for
     *
     * @param  DelayedJob $delayedJob
     * @return float      when the retry may be started, as a timestamp
     */
    private function getDueAt(array $delayedJob): float
    {
        return max($delayedJob['at'], $this->retryAfterOrigins[$delayedJob['job']['origin']]['until'] ?? 0.0);
    }

    /**
     * @param  Job    $job
     * @param non-empty-string $url
     *
     * @param  array{retryAuthFailure?: bool, redirects?: int<0, max>, storeAuth?: 'prompt'|bool, retries?: int<1, max>, ipResolve?: 4|6} $attributes
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
     * @param  RetryAttributes $attributes
     * @param  float|null      $retryAfter seconds the response asked to wait before retrying, null to use the default backoff
     * @param  int             $statusCode status code of the response which asked for the wait, only used with $retryAfter
     */
    private function restartJobWithDelay(array $job, string $url, array $attributes, ?float $retryAfter = null, int $statusCode = 0): void
    {
        $now = microtime(true);
        if (null !== $retryAfter) {
            $delay = $retryAfter;
            $until = $now + $retryAfter;
            $origin = $this->retryAfterOrigins[$job['origin']] ?? null;
            // a hold which has already run out is over, so the origin is announced anew rather than extended
            if (null === $origin || $origin['until'] <= $now) {
                $this->retryAfterOrigins[$job['origin']] = ['statusCode' => $statusCode, 'announced' => false, 'until' => $until];
            } else {
                $this->retryAfterOrigins[$job['origin']]['until'] = max($origin['until'], $until);
            }
        } elseif ($attributes['retries'] >= 3) {
            $delay = 0.5; // half a second delay for 3rd retry and beyond
        } elseif ($attributes['retries'] >= 2) {
            $delay = 0.1; // 100ms delay for 2nd retry
        } else {
            $delay = 0.0; // no delay for the first retry
        }

        if ($delay <= 0.0 && ($this->retryAfterOrigins[$job['origin']]['until'] ?? 0.0) <= $now) {
            $this->restartJob($job, $url, $attributes);

            return;
        }

        // sleeping here would stall every other transfer tick() drives, so the retry is scheduled instead
        $this->delayedJobs[] = ['job' => $job, 'url' => $url, 'attributes' => $attributes, 'at' => $now + $delay];
    }

    /**
     * Tells the user when an origin asked for requests to be retried later, once per origin and episode
     *
     * The wait can last up to MAX_RETRY_AFTER seconds, which without a word would look like a hang
     */
    private function announceRetryAfterWaits(): void
    {
        $now = microtime(true);
        foreach ($this->retryAfterOrigins as $origin => $retryAfterOrigin) {
            $pending = 0;
            $lastDue = $now;
            foreach ($this->delayedJobs as $delayedJob) {
                if ($delayedJob['job']['origin'] === $origin) {
                    $pending++;
                    $lastDue = max($lastDue, $this->getDueAt($delayedJob));
                }
            }

            if (0 === $pending) {
                unset($this->retryAfterOrigins[$origin]);
                continue;
            }

            if ($retryAfterOrigin['announced']) {
                continue;
            }

            $reason = 429 === $retryAfterOrigin['statusCode'] ? 'is rate limiting requests' : 'responded with status code '.$retryAfterOrigin['statusCode'];
            $this->io->writeError('<warning>'.$origin.' '.$reason.' ('.$pending.' pending), waiting '.(int) ceil($lastDue - $now).'s before retrying</warning>');
            $this->retryAfterOrigins[$origin]['announced'] = true;
        }
    }

    /**
     * Shows the warnings of a response, unless the same origin already showed the same warnings
     *
     * @param  mixed $data the decoded response body
     * @return bool  whether the warnings were shown, now or before
     */
    private function outputWarnings(string $origin, $data): bool
    {
        if (!is_array($data)) {
            return false;
        }

        $key = $origin.':'.json_encode(array_intersect_key($data, array_flip(['warning', 'warning-versions', 'info', 'info-versions', 'warnings', 'infos'])));
        if (isset($this->shownWarnings[$key])) {
            return true;
        }

        if (!HttpDownloader::outputWarnings($this->io, $origin, $data)) {
            return false;
        }

        $this->shownWarnings[$key] = true;

        return true;
    }

    /**
     * @param  Job                $job
     */
    private function failResponse(array $job, Response $response, string $errorMessage, bool $warningsOutput = false): TransportException
    {
        if (null !== $job['filename']) {
            @unlink($job['filename'].'~');
        }

        $details = '';
        // skip dumping the raw JSON body when outputWarnings already presented it cleanly, to avoid duplicate/messy output
        if (!$warningsOutput && self::isJsonResponse($response)) {
            $details = ':'.PHP_EOL.substr($response->getBody(), 0, 200).(strlen($response->getBody()) > 200 ? '...' : '');
        }

        return new TransportException('The "'.Url::sanitize($job['url']).'" file could not be downloaded ('.$errorMessage.')' . $details, $response->getStatusCode());
    }

    /**
     * Describes why a 4xx/5xx response failed the request
     *
     * A Retry-After which is longer than we wait for is spelled out, as it tells the user whether
     * trying again straight away can help or whether to come back later.
     */
    private static function getStatusFailureMessage(Response $response): string
    {
        $message = (string) $response->getStatusMessage();
        $retryAfter = self::getRetryAfterDelay($response);
        if (null !== $retryAfter && $retryAfter > self::MAX_RETRY_AFTER) {
            $message .= ', the server asked to retry in '.$retryAfter.'s which is longer than the '.self::MAX_RETRY_AFTER.'s Composer is willing to wait, try again later';
        }

        return $message;
    }

    private static function isJsonResponse(Response $response): bool
    {
        return in_array(strtolower((string) $response->getHeader('content-type')), ['application/json', 'application/json; charset=utf-8'], true);
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
