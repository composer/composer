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

namespace Composer\Util\Http;

use Composer\Config;
use Composer\Downloader\MaxFileSizeExceededException;
use Composer\IO\IOInterface;
use Composer\Downloader\TransportException;
use Composer\Util\StreamContextFactory;
use Composer\Util\AuthHelper;
use Composer\Util\Url;
use Composer\Util\HttpDownloader;
use React\Promise\Promise;

/**
 * @internal
 * @author Jordi Boggiano <j.boggiano@seld.be>
 * @author Nicolas Grekas <p@tchwork.com>
 */
class CurlDownloader
{
    private $multiHandle;
    private $shareHandle;
    private $jobs = array();
    /** @var IOInterface */
    private $io;
    /** @var Config */
    private $config;
    /** @var AuthHelper */
    private $authHelper;
    private $selectTimeout = 5.0;
    private $maxRedirects = 20;
    /** @var ProxyManager */
    private $proxyManager;
    private $supportsSecureProxy;
    protected $multiErrors = array(
        CURLM_BAD_HANDLE => array('CURLM_BAD_HANDLE', 'The passed-in handle is not a valid CURLM handle.'),
        CURLM_BAD_EASY_HANDLE => array('CURLM_BAD_EASY_HANDLE', "An easy handle was not good/valid. It could mean that it isn't an easy handle at all, or possibly that the handle already is in used by this or another multi handle."),
        CURLM_OUT_OF_MEMORY => array('CURLM_OUT_OF_MEMORY', 'You are doomed.'),
        CURLM_INTERNAL_ERROR => array('CURLM_INTERNAL_ERROR', 'This can only be returned if libcurl bugs. Please report it to us!'),
    );

    private static $options = array(
        'http' => array(
            'method' => CURLOPT_CUSTOMREQUEST,
            'content' => CURLOPT_POSTFIELDS,
            'header' => CURLOPT_HTTPHEADER,
            'timeout' => CURLOPT_TIMEOUT,
        ),
        'ssl' => array(
            'cafile' => CURLOPT_CAINFO,
            'capath' => CURLOPT_CAPATH,
            'verify_peer' => CURLOPT_SSL_VERIFYPEER,
            'verify_peer_name' => CURLOPT_SSL_VERIFYHOST,
            'local_cert' => CURLOPT_SSLCERT,
            'local_pk' => CURLOPT_SSLKEY,
            'passphrase' => CURLOPT_SSLKEYPASSWD,
        ),
    );

    private static $timeInfo = array(
        'total_time' => true,
        'namelookup_time' => true,
        'connect_time' => true,
        'pretransfer_time' => true,
        'starttransfer_time' => true,
        'redirect_time' => true,
    );

    public function __construct(IOInterface $io, Config $config, array $options = array(), $disableTls = false)
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
     * @return int internal job id
     */
    public function download($resolve, $reject, $origin, $url, $options, $copyTo = null)
    {
        $attributes = array();
        if (isset($options['retry-auth-failure'])) {
            $attributes['retryAuthFailure'] = $options['retry-auth-failure'];
            unset($options['retry-auth-failure']);
        }

        return $this->initDownload($resolve, $reject, $origin, $url, $options, $copyTo, $attributes);
    }

    /**
     * @return int internal job id
     */
    private function initDownload($resolve, $reject, $origin, $url, $options, $copyTo = null, array $attributes = array())
    {
        $attributes = array_merge(array(
            'retryAuthFailure' => true,
            'redirects' => 0,
            'storeAuth' => false,
        ), $attributes);

        $originalOptions = $options;

        // check URL can be accessed (i.e. is not insecure), but allow insecure Packagist calls to $hashed providers as file integrity is verified with sha256
        if (!preg_match('{^http://(repo\.)?packagist\.org/p/}', $url) || (false === strpos($url, '$') && false === strpos($url, '%24'))) {
            $this->config->prohibitUrlByConfig($url, $this->io);
        }

        $curlHandle = curl_init();
        $headerHandle = fopen('php://temp/maxmemory:32768', 'w+b');

        if ($copyTo) {
            $errorMessage = '';
            set_error_handler(function ($code, $msg) use (&$errorMessage) {
                if ($errorMessage) {
                    $errorMessage .= "\n";
                }
                $errorMessage .= preg_replace('{^fopen\(.*?\): }', '', $msg);
            });
            $bodyHandle = fopen($copyTo.'~', 'w+b');
            restore_error_handler();
            if (!$bodyHandle) {
                throw new TransportException('The "'.$url.'" file could not be written to '.$copyTo.': '.$errorMessage);
            }
        } else {
            $bodyHandle = @fopen('php://temp/maxmemory:524288', 'w+b');
        }

        curl_setopt($curlHandle, CURLOPT_URL, $url);
        curl_setopt($curlHandle, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($curlHandle, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($curlHandle, CURLOPT_TIMEOUT, 300);
        curl_setopt($curlHandle, CURLOPT_WRITEHEADER, $headerHandle);
        curl_setopt($curlHandle, CURLOPT_FILE, $bodyHandle);
        curl_setopt($curlHandle, CURLOPT_ENCODING, "gzip");
        curl_setopt($curlHandle, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
        if (function_exists('curl_share_init')) {
            curl_setopt($curlHandle, CURLOPT_SHARE, $this->shareHandle);
        }

        if (!isset($options['http']['header'])) {
            $options['http']['header'] = array();
        }

        $options['http']['header'] = array_diff($options['http']['header'], array('Connection: close'));
        $options['http']['header'][] = 'Connection: keep-alive';

        $version = curl_version();
        $features = $version['features'];
        if (0 === strpos($url, 'https://') && \defined('CURL_VERSION_HTTP2') && \defined('CURL_HTTP_VERSION_2_0') && (CURL_VERSION_HTTP2 & $features)) {
            curl_setopt($curlHandle, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_2_0);
        }

        $options['http']['header'] = $this->authHelper->addAuthenticationHeader($options['http']['header'], $origin, $url);
        // Merge in headers - we don't get any proxy values
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
        curl_setopt($curlHandle, CURLOPT_PROXY, $proxy->getUrl());

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

        $this->jobs[(int) $curlHandle] = array(
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
        );

        $usingProxy = $proxy->getFormattedUrl(' using proxy (%s)');
        $ifModified = false !== stripos(implode(',', $options['http']['header']), 'if-modified-since:') ? ' if modified' : '';
        if ($attributes['redirects'] === 0) {
            $this->io->writeError('Downloading ' . Url::sanitize($url) . $usingProxy . $ifModified, true, IOInterface::DEBUG);
        }

        $this->checkCurlResult(curl_multi_add_handle($this->multiHandle, $curlHandle));
        // TODO progress

        return (int) $curlHandle;
    }

    public function abortRequest($id)
    {
        if (isset($this->jobs[$id], $this->jobs[$id]['handle'])) {
            $job = $this->jobs[$id];
            curl_multi_remove_handle($this->multiHandle, $job['handle']);
            curl_close($job['handle']);
            if (is_resource($job['headerHandle'])) {
                fclose($job['headerHandle']);
            }
            if (is_resource($job['bodyHandle'])) {
                fclose($job['bodyHandle']);
            }
            if ($job['filename']) {
                @unlink($job['filename'].'~');
            }
            unset($this->jobs[$id]);
        }
    }

    public function tick()
    {
        if (!$this->jobs) {
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
                if ($job['filename']) {
                    $contents = $job['filename'].'~';
                    if ($statusCode >= 300) {
                        rewind($job['bodyHandle']);
                        $contents = stream_get_contents($job['bodyHandle']);
                    }
                    $response = new Response(array('url' => $progress['url']), $statusCode, $headers, $contents);
                    $this->io->writeError('['.$statusCode.'] '.Url::sanitize($progress['url']), true, IOInterface::DEBUG);
                } else {
                    rewind($job['bodyHandle']);
                    $contents = stream_get_contents($job['bodyHandle']);
                    $response = new Response(array('url' => $progress['url']), $statusCode, $headers, $contents);
                    $this->io->writeError('['.$statusCode.'] '.Url::sanitize($progress['url']), true, IOInterface::DEBUG);
                }
                fclose($job['bodyHandle']);

                if ($response->getStatusCode() >= 400 && $response->getHeader('content-type') === 'application/json') {
                    HttpDownloader::outputWarnings($this->io, $job['origin'], json_decode($response->getBody(), true));
                }

                $result = $this->isAuthenticatedRetryNeeded($job, $response);
                if ($result['retry']) {
                    $this->restartJob($job, $job['url'], array('storeAuth' => $result['storeAuth']));
                    continue;
                }

                // handle 3xx redirects, 304 Not Modified is excluded
                if ($statusCode >= 300 && $statusCode <= 399 && $statusCode !== 304 && $job['attributes']['redirects'] < $this->maxRedirects) {
                    $location = $this->handleRedirect($job, $response);
                    if ($location) {
                        $this->restartJob($job, $location, array('redirects' => $job['attributes']['redirects'] + 1));
                        continue;
                    }
                }

                // fail 4xx and 5xx responses and capture the response
                if ($statusCode >= 400 && $statusCode <= 599) {
                    throw $this->failResponse($job, $response, $response->getStatusMessage());
                }

                if ($job['attributes']['storeAuth']) {
                    $this->authHelper->storeAuth($job['origin'], $job['attributes']['storeAuth']);
                }

                // resolve promise
                if ($job['filename']) {
                    rename($job['filename'].'~', $job['filename']);
                    call_user_func($job['resolve'], $response);
                } else {
                    call_user_func($job['resolve'], $response);
                }
            } catch (\Exception $e) {
                if ($e instanceof TransportException && $headers) {
                    $e->setHeaders($headers);
                    $e->setStatusCode($statusCode);
                }
                if ($e instanceof TransportException && $response) {
                    $e->setResponse($response->getBody());
                }

                if (is_resource($job['headerHandle'])) {
                    fclose($job['headerHandle']);
                }
                if (is_resource($job['bodyHandle'])) {
                    fclose($job['bodyHandle']);
                }
                if ($job['filename']) {
                    @unlink($job['filename'].'~');
                }
                call_user_func($job['reject'], $e);
            }
        }

        foreach ($this->jobs as $i => $curlHandle) {
            if (!isset($this->jobs[$i])) {
                continue;
            }
            $curlHandle = $this->jobs[$i]['curlHandle'];
            $progress = array_diff_key(curl_getinfo($curlHandle), self::$timeInfo);

            if ($this->jobs[$i]['progress'] !== $progress) {
                $this->jobs[$i]['progress'] = $progress;

                if (isset($this->jobs[$i]['options']['max_file_size'])) {
                    // Compare max_file_size with the content-length header this value will be -1 until the header is parsed
                    if ($this->jobs[$i]['options']['max_file_size'] < $progress['download_content_length']) {
                        throw new MaxFileSizeExceededException('Maximum allowed download size reached. Content-length header indicates ' . $progress['download_content_length'] . ' bytes. Allowed ' .  $this->jobs[$i]['options']['max_file_size'] . ' bytes');
                    }

                    // Compare max_file_size with the download size in bytes
                    if ($this->jobs[$i]['options']['max_file_size'] < $progress['size_download']) {
                        throw new MaxFileSizeExceededException('Maximum allowed download size reached. Downloaded ' . $progress['size_download'] . ' of allowed ' .  $this->jobs[$i]['options']['max_file_size'] . ' bytes');
                    }
                }

                // TODO progress
            }
        }
    }

    private function handleRedirect(array $job, Response $response)
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
                $targetUrl = preg_replace('{^(.+(?://|@)'.preg_quote($urlHost).'(?::\d+)?)(?:[/\?].*)?$}', '\1'.$locationHeader, $job['url']);
            } else {
                // Relative path; e.g. foo
                // This actually differs from PHP which seems to add duplicate slashes.
                $targetUrl = preg_replace('{^(.+/)[^/?]*(?:\?.*)?$}', '\1'.$locationHeader, $job['url']);
            }
        }

        if (!empty($targetUrl)) {
            $this->io->writeError(sprintf('Following redirect (%u) %s', $job['attributes']['redirects'] + 1, Url::sanitize($targetUrl)), true, IOInterface::DEBUG);

            return $targetUrl;
        }

        throw new TransportException('The "'.$job['url'].'" file could not be downloaded, got redirect without Location ('.$response->getStatusMessage().')');
    }

    private function isAuthenticatedRetryNeeded(array $job, Response $response)
    {
        if (in_array($response->getStatusCode(), array(401, 403)) && $job['attributes']['retryAuthFailure']) {
            $result = $this->authHelper->promptAuthIfNeeded($job['url'], $job['origin'], $response->getStatusCode(), $response->getStatusMessage(), $response->getHeaders());

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
            && preg_match('{^text/html\b}i', $response->getHeader('content-type'))
        ) {
            $needsAuthRetry = 'Bitbucket requires authentication and it was not provided';
        }

        // check for gitlab 404 when downloading archives
        if (
            $response->getStatusCode() === 404
            && $this->config && in_array($job['origin'], $this->config->get('gitlab-domains'), true)
            && false !== strpos($job['url'], 'archive.zip')
        ) {
            $needsAuthRetry = 'GitLab requires authentication and it was not provided';
        }

        if ($needsAuthRetry) {
            if ($job['attributes']['retryAuthFailure']) {
                $result = $this->authHelper->promptAuthIfNeeded($job['url'], $job['origin'], 401);
                if ($result['retry']) {
                    return $result;
                }
            }

            throw $this->failResponse($job, $response, $needsAuthRetry);
        }

        return array('retry' => false, 'storeAuth' => false);
    }

    private function restartJob(array $job, $url, array $attributes = array())
    {
        if ($job['filename']) {
            @unlink($job['filename'].'~');
        }

        $attributes = array_merge($job['attributes'], $attributes);
        $origin = Url::getOrigin($this->config, $url);

        $this->initDownload($job['resolve'], $job['reject'], $origin, $url, $job['options'], $job['filename'], $attributes);
    }

    private function failResponse(array $job, Response $response, $errorMessage)
    {
        if ($job['filename']) {
            @unlink($job['filename'].'~');
        }

        return new TransportException('The "'.$job['url'].'" file could not be downloaded ('.$errorMessage.')', $response->getStatusCode());
    }

    private function checkCurlResult($code)
    {
        if ($code != CURLM_OK && $code != CURLM_CALL_MULTI_PERFORM) {
            throw new \RuntimeException(
                isset($this->multiErrors[$code])
                ? "cURL error: {$code} ({$this->multiErrors[$code][0]}): cURL message: {$this->multiErrors[$code][1]}"
                : 'Unexpected cURL error: ' . $code
            );
        }
    }
}
