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

namespace Composer\Util;

use Composer\Config;
use Composer\Downloader\MaxFileSizeExceededException;
use Composer\IO\IOInterface;
use Composer\Downloader\TransportException;
use Composer\Pcre\Preg;
use Composer\Util\Http\Response;
use Composer\Util\Http\ProxyManager;

/**
 * @internal
 * @author François Pluchino <francois.pluchino@opendisplay.com>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 * @author Nils Adermann <naderman@naderman.de>
 */
class RemoteFilesystem
{
    /** @var IOInterface */
    private $io;
    /** @var Config */
    private $config;
    /** @var string */
    private $scheme;
    /** @var int */
    private $bytesMax;
    /** @var string */
    private $originUrl;
    /** @var string */
    private $fileUrl;
    /** @var ?string */
    private $fileName;
    /** @var bool */
    private $retry = false;
    /** @var bool */
    private $progress;
    /** @var ?int */
    private $lastProgress;
    /** @var mixed[] */
    private $options = array();
    /** @var bool */
    private $disableTls = false;
    /** @var string[] */
    private $lastHeaders;
    /** @var bool */
    private $storeAuth = false;
    /** @var AuthHelper */
    private $authHelper;
    /** @var bool */
    private $degradedMode = false;
    /** @var int */
    private $redirects;
    /** @var int */
    private $maxRedirects = 20;
    /** @var ProxyManager */
    private $proxyManager;

    /**
     * Constructor.
     *
     * @param IOInterface $io         The IO instance
     * @param Config      $config     The config
     * @param mixed[]     $options    The options
     * @param bool        $disableTls
     * @param AuthHelper  $authHelper
     */
    public function __construct(IOInterface $io, Config $config, array $options = array(), bool $disableTls = false, ?AuthHelper $authHelper = null)
    {
        $this->io = $io;

        // Setup TLS options
        // The cafile option can be set via config.json
        if ($disableTls === false) {
            $this->options = StreamContextFactory::getTlsDefaults($options, $io);
        } else {
            $this->disableTls = true;
        }

        // handle the other externally set options normally.
        $this->options = array_replace_recursive($this->options, $options);
        $this->config = $config;
        $this->authHelper = $authHelper ?? new AuthHelper($io, $config);
        $this->proxyManager = ProxyManager::getInstance();
    }

    /**
     * Copy the remote file in local.
     *
     * @param string  $originUrl The origin URL
     * @param string  $fileUrl   The file URL
     * @param string  $fileName  the local filename
     * @param bool    $progress  Display the progression
     * @param mixed[] $options   Additional context options
     *
     * @return bool true
     */
    public function copy(string $originUrl, string $fileUrl, string $fileName, bool $progress = true, array $options = array())
    {
        return $this->get($originUrl, $fileUrl, $options, $fileName, $progress);
    }

    /**
     * Get the content.
     *
     * @param string  $originUrl The origin URL
     * @param string  $fileUrl   The file URL
     * @param bool    $progress  Display the progression
     * @param mixed[] $options   Additional context options
     *
     * @return bool|string The content
     */
    public function getContents(string $originUrl, string $fileUrl, bool $progress = true, array $options = array())
    {
        return $this->get($originUrl, $fileUrl, $options, null, $progress);
    }

    /**
     * Retrieve the options set in the constructor
     *
     * @return mixed[] Options
     */
    public function getOptions()
    {
        return $this->options;
    }

    /**
     * Merges new options
     *
     * @param  mixed[] $options
     * @return void
     */
    public function setOptions(array $options)
    {
        $this->options = array_replace_recursive($this->options, $options);
    }

    /**
     * Check is disable TLS.
     *
     * @return bool
     */
    public function isTlsDisabled()
    {
        return $this->disableTls === true;
    }

    /**
     * Returns the headers of the last request
     *
     * @return string[]
     */
    public function getLastHeaders()
    {
        return $this->lastHeaders;
    }

    /**
     * @param  string[] $headers array of returned headers like from getLastHeaders()
     * @return int|null
     */
    public static function findStatusCode(array $headers)
    {
        $value = null;
        foreach ($headers as $header) {
            if (Preg::isMatch('{^HTTP/\S+ (\d+)}i', $header, $match)) {
                // In case of redirects, http_response_headers contains the headers of all responses
                // so we can not return directly and need to keep iterating
                $value = (int) $match[1];
            }
        }

        return $value;
    }

    /**
     * @param  string[]    $headers array of returned headers like from getLastHeaders()
     * @return string|null
     */
    public function findStatusMessage(array $headers)
    {
        $value = null;
        foreach ($headers as $header) {
            if (Preg::isMatch('{^HTTP/\S+ \d+}i', $header)) {
                // In case of redirects, http_response_headers contains the headers of all responses
                // so we can not return directly and need to keep iterating
                $value = $header;
            }
        }

        return $value;
    }

    /**
     * Get file content or copy action.
     *
     * @param string  $originUrl         The origin URL
     * @param string  $fileUrl           The file URL
     * @param mixed[] $additionalOptions context options
     * @param string  $fileName          the local filename
     * @param bool    $progress          Display the progression
     *
     * @throws TransportException|\Exception
     * @throws TransportException            When the file could not be downloaded
     *
     * @return bool|string
     */
    protected function get(string $originUrl, string $fileUrl, array $additionalOptions = array(), ?string $fileName = null, bool $progress = true)
    {
        $this->scheme = parse_url(strtr($fileUrl, '\\', '/'), PHP_URL_SCHEME);
        $this->bytesMax = 0;
        $this->originUrl = $originUrl;
        $this->fileUrl = $fileUrl;
        $this->fileName = $fileName;
        $this->progress = $progress;
        $this->lastProgress = null;
        $retryAuthFailure = true;
        $this->lastHeaders = array();
        $this->redirects = 1; // The first request counts.

        $tempAdditionalOptions = $additionalOptions;
        if (isset($tempAdditionalOptions['retry-auth-failure'])) {
            $retryAuthFailure = (bool) $tempAdditionalOptions['retry-auth-failure'];

            unset($tempAdditionalOptions['retry-auth-failure']);
        }

        $isRedirect = false;
        if (isset($tempAdditionalOptions['redirects'])) {
            $this->redirects = $tempAdditionalOptions['redirects'];
            $isRedirect = true;

            unset($tempAdditionalOptions['redirects']);
        }

        $options = $this->getOptionsForUrl($originUrl, $tempAdditionalOptions);
        unset($tempAdditionalOptions);

        $origFileUrl = $fileUrl;

        if (isset($options['gitlab-token'])) {
            $fileUrl .= (false === strpos($fileUrl, '?') ? '?' : '&') . 'access_token='.$options['gitlab-token'];
            unset($options['gitlab-token']);
        }

        if (isset($options['http'])) {
            $options['http']['ignore_errors'] = true;
        }

        if ($this->degradedMode && strpos($fileUrl, 'http://repo.packagist.org/') === 0) {
            // access packagist using the resolved IPv4 instead of the hostname to force IPv4 protocol
            $fileUrl = 'http://' . gethostbyname('repo.packagist.org') . substr($fileUrl, 20);
            $degradedPackagist = true;
        }

        $maxFileSize = null;
        if (isset($options['max_file_size'])) {
            $maxFileSize = $options['max_file_size'];
            unset($options['max_file_size']);
        }

        $ctx = StreamContextFactory::getContext($fileUrl, $options, array('notification' => array($this, 'callbackGet')));

        $proxy = $this->proxyManager->getProxyForRequest($fileUrl);
        $usingProxy = $proxy->getFormattedUrl(' using proxy (%s)');
        $this->io->writeError((strpos($origFileUrl, 'http') === 0 ? 'Downloading ' : 'Reading ') . Url::sanitize($origFileUrl) . $usingProxy, true, IOInterface::DEBUG);
        unset($origFileUrl, $proxy, $usingProxy);

        // Check for secure HTTP, but allow insecure Packagist calls to $hashed providers as file integrity is verified with sha256
        if ((!Preg::isMatch('{^http://(repo\.)?packagist\.org/p/}', $fileUrl) || (false === strpos($fileUrl, '$') && false === strpos($fileUrl, '%24'))) && empty($degradedPackagist)) {
            $this->config->prohibitUrlByConfig($fileUrl, $this->io);
        }

        if ($this->progress && !$isRedirect) {
            $this->io->writeError("Downloading (<comment>connecting...</comment>)", false);
        }

        $errorMessage = '';
        $errorCode = 0;
        $result = false;
        set_error_handler(function ($code, $msg) use (&$errorMessage): bool {
            if ($errorMessage) {
                $errorMessage .= "\n";
            }
            $errorMessage .= Preg::replace('{^file_get_contents\(.*?\): }', '', $msg);

            return true;
        });
        $http_response_header = array();
        try {
            $result = $this->getRemoteContents($originUrl, $fileUrl, $ctx, $http_response_header, $maxFileSize);

            if (!empty($http_response_header[0])) {
                $statusCode = self::findStatusCode($http_response_header);
                if ($statusCode >= 400 && Response::findHeaderValue($http_response_header, 'content-type') === 'application/json') {
                    HttpDownloader::outputWarnings($this->io, $originUrl, json_decode($result, true));
                }

                if (in_array($statusCode, array(401, 403)) && $retryAuthFailure) {
                    $this->promptAuthAndRetry($statusCode, $this->findStatusMessage($http_response_header), $http_response_header);
                }
            }

            $contentLength = !empty($http_response_header[0]) ? Response::findHeaderValue($http_response_header, 'content-length') : null;
            if ($contentLength && Platform::strlen($result) < $contentLength) {
                // alas, this is not possible via the stream callback because STREAM_NOTIFY_COMPLETED is documented, but not implemented anywhere in PHP
                $e = new TransportException('Content-Length mismatch, received '.Platform::strlen($result).' bytes out of the expected '.$contentLength);
                $e->setHeaders($http_response_header);
                $e->setStatusCode(self::findStatusCode($http_response_header));
                try {
                    $e->setResponse($this->decodeResult($result, $http_response_header));
                } catch (\Exception $discarded) {
                    $e->setResponse($this->normalizeResult($result));
                }

                $this->io->writeError('Content-Length mismatch, received '.Platform::strlen($result).' out of '.$contentLength.' bytes: (' . base64_encode($result).')', true, IOInterface::DEBUG);

                throw $e;
            }
        } catch (\Exception $e) {
            if ($e instanceof TransportException && !empty($http_response_header[0])) {
                $e->setHeaders($http_response_header);
                $e->setStatusCode(self::findStatusCode($http_response_header));
            }
            if ($e instanceof TransportException && $result !== false) {
                $e->setResponse($this->decodeResult($result, $http_response_header));
            }
            $result = false;
        }
        if ($errorMessage && !filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
            $errorMessage = 'allow_url_fopen must be enabled in php.ini ('.$errorMessage.')';
        }
        restore_error_handler();
        if (isset($e) && !$this->retry) {
            if (!$this->degradedMode && false !== strpos($e->getMessage(), 'Operation timed out')) {
                $this->degradedMode = true;
                $this->io->writeError('');
                $this->io->writeError(array(
                    '<error>'.$e->getMessage().'</error>',
                    '<error>Retrying with degraded mode, check https://getcomposer.org/doc/articles/troubleshooting.md#degraded-mode for more info</error>',
                ));

                return $this->get($this->originUrl, $this->fileUrl, $additionalOptions, $this->fileName, $this->progress);
            }

            throw $e;
        }

        $statusCode = null;
        $contentType = null;
        $locationHeader = null;
        if (!empty($http_response_header[0])) {
            $statusCode = self::findStatusCode($http_response_header);
            $contentType = Response::findHeaderValue($http_response_header, 'content-type');
            $locationHeader = Response::findHeaderValue($http_response_header, 'location');
        }

        // check for bitbucket login page asking to authenticate
        if ($originUrl === 'bitbucket.org'
            && !$this->authHelper->isPublicBitBucketDownload($fileUrl)
            && substr($fileUrl, -4) === '.zip'
            && (!$locationHeader || substr(parse_url($locationHeader, PHP_URL_PATH), -4) !== '.zip')
            && $contentType && Preg::isMatch('{^text/html\b}i', $contentType)
        ) {
            $result = false;
            if ($retryAuthFailure) {
                $this->promptAuthAndRetry(401);
            }
        }

        // check for gitlab 404 when downloading archives
        if ($statusCode === 404
            && in_array($originUrl, $this->config->get('gitlab-domains'), true)
            && false !== strpos($fileUrl, 'archive.zip')
        ) {
            $result = false;
            if ($retryAuthFailure) {
                $this->promptAuthAndRetry(401);
            }
        }

        // handle 3xx redirects, 304 Not Modified is excluded
        $hasFollowedRedirect = false;
        if ($statusCode >= 300 && $statusCode <= 399 && $statusCode !== 304 && $this->redirects < $this->maxRedirects) {
            $hasFollowedRedirect = true;
            $result = $this->handleRedirect($http_response_header, $additionalOptions, $result);
        }

        // fail 4xx and 5xx responses and capture the response
        if ($statusCode && $statusCode >= 400 && $statusCode <= 599) {
            if (!$this->retry) {
                if ($this->progress && !$isRedirect) {
                    $this->io->overwriteError("Downloading (<error>failed</error>)", false);
                }

                $e = new TransportException('The "'.$this->fileUrl.'" file could not be downloaded ('.$http_response_header[0].')', $statusCode);
                $e->setHeaders($http_response_header);
                $e->setResponse($this->decodeResult($result, $http_response_header));
                $e->setStatusCode($statusCode);
                throw $e;
            }
            $result = false;
        }

        if ($this->progress && !$this->retry && !$isRedirect) {
            $this->io->overwriteError("Downloading (".($result === false ? '<error>failed</error>' : '<comment>100%</comment>').")", false);
        }

        // decode gzip
        if ($result && extension_loaded('zlib') && strpos($fileUrl, 'http') === 0 && !$hasFollowedRedirect) {
            try {
                $result = $this->decodeResult($result, $http_response_header);
            } catch (\Exception $e) {
                if ($this->degradedMode) {
                    throw $e;
                }

                $this->degradedMode = true;
                $this->io->writeError(array(
                    '',
                    '<error>Failed to decode response: '.$e->getMessage().'</error>',
                    '<error>Retrying with degraded mode, check https://getcomposer.org/doc/articles/troubleshooting.md#degraded-mode for more info</error>',
                ));

                return $this->get($this->originUrl, $this->fileUrl, $additionalOptions, $this->fileName, $this->progress);
            }
        }

        // handle copy command if download was successful
        if (false !== $result && null !== $fileName && !$isRedirect) {
            if ('' === $result) {
                throw new TransportException('"'.$this->fileUrl.'" appears broken, and returned an empty 200 response');
            }

            $errorMessage = '';
            set_error_handler(function ($code, $msg) use (&$errorMessage): bool {
                if ($errorMessage) {
                    $errorMessage .= "\n";
                }
                $errorMessage .= Preg::replace('{^file_put_contents\(.*?\): }', '', $msg);

                return true;
            });
            $result = (bool) file_put_contents($fileName, $result);
            restore_error_handler();
            if (false === $result) {
                throw new TransportException('The "'.$this->fileUrl.'" file could not be written to '.$fileName.': '.$errorMessage);
            }
        }

        if ($this->retry) {
            $this->retry = false;

            $result = $this->get($this->originUrl, $this->fileUrl, $additionalOptions, $this->fileName, $this->progress);

            if ($this->storeAuth) {
                $this->authHelper->storeAuth($this->originUrl, $this->storeAuth);
                $this->storeAuth = false;
            }

            return $result;
        }

        if (false === $result) {
            $e = new TransportException('The "'.$this->fileUrl.'" file could not be downloaded: '.$errorMessage, $errorCode);
            if (!empty($http_response_header[0])) {
                $e->setHeaders($http_response_header);
            }

            if (!$this->degradedMode && false !== strpos($e->getMessage(), 'Operation timed out')) {
                $this->degradedMode = true;
                $this->io->writeError('');
                $this->io->writeError(array(
                    '<error>'.$e->getMessage().'</error>',
                    '<error>Retrying with degraded mode, check https://getcomposer.org/doc/articles/troubleshooting.md#degraded-mode for more info</error>',
                ));

                return $this->get($this->originUrl, $this->fileUrl, $additionalOptions, $this->fileName, $this->progress);
            }

            throw $e;
        }

        if (!empty($http_response_header[0])) {
            $this->lastHeaders = $http_response_header;
        }

        return $result;
    }

    /**
     * Get contents of remote URL.
     *
     * @param string   $originUrl   The origin URL
     * @param string   $fileUrl     The file URL
     * @param resource $context     The stream context
     * @param string[] $responseHeaders
     * @param int      $maxFileSize The maximum allowed file size
     *
     * @return string|false The response contents or false on failure
     */
    protected function getRemoteContents(string $originUrl, string $fileUrl, $context, ?array &$responseHeaders = null, ?int $maxFileSize = null)
    {
        $result = false;

        try {
            $e = null;
            if ($maxFileSize !== null) {
                $result = file_get_contents($fileUrl, false, $context, 0, $maxFileSize);
            } else {
                // passing `null` to file_get_contents will convert `null` to `0` and return 0 bytes
                $result = file_get_contents($fileUrl, false, $context);
            }
        } catch (\Throwable $e) {
        }

        if ($result !== false && $maxFileSize !== null && Platform::strlen($result) >= $maxFileSize) {
            throw new MaxFileSizeExceededException('Maximum allowed download size reached. Downloaded ' . Platform::strlen($result) . ' of allowed ' .  $maxFileSize . ' bytes');
        }

        // https://www.php.net/manual/en/reserved.variables.httpresponseheader.php
        $responseHeaders = $http_response_header ?? array();

        if (null !== $e) {
            throw $e;
        }

        return $result;
    }

    /**
     * Get notification action.
     *
     * @param int    $notificationCode The notification code
     * @param int    $severity         The severity level
     * @param string $message          The message
     * @param int    $messageCode      The message code
     * @param int    $bytesTransferred The loaded size
     * @param int    $bytesMax         The total size
     *
     * @return void
     *
     * @throws TransportException
     */
    protected function callbackGet(int $notificationCode, int $severity, ?string $message, int $messageCode, int $bytesTransferred, int $bytesMax)
    {
        switch ($notificationCode) {
            case STREAM_NOTIFY_FAILURE:
                if (400 === $messageCode) {
                    // This might happen if your host is secured by ssl client certificate authentication
                    // but you do not send an appropriate certificate
                    throw new TransportException("The '" . $this->fileUrl . "' URL could not be accessed: " . $message, $messageCode);
                }
                break;

            case STREAM_NOTIFY_FILE_SIZE_IS:
                $this->bytesMax = $bytesMax;
                break;

            case STREAM_NOTIFY_PROGRESS:
                if ($this->bytesMax > 0 && $this->progress) {
                    $progression = min(100, (int) round($bytesTransferred / $this->bytesMax * 100));

                    if ((0 === $progression % 5) && 100 !== $progression && $progression !== $this->lastProgress) {
                        $this->lastProgress = $progression;
                        $this->io->overwriteError("Downloading (<comment>$progression%</comment>)", false);
                    }
                }
                break;

            default:
                break;
        }
    }

    /**
     * @param positive-int $httpStatus
     * @param string|null  $reason
     * @param string[]     $headers
     *
     * @return void
     */
    protected function promptAuthAndRetry($httpStatus, ?string $reason = null, array $headers = array())
    {
        $result = $this->authHelper->promptAuthIfNeeded($this->fileUrl, $this->originUrl, $httpStatus, $reason, $headers, 1 /** always pass 1 as RemoteFilesystem is single threaded there is no race condition possible */);

        $this->storeAuth = $result['storeAuth'];
        $this->retry = $result['retry'];

        if ($this->retry) {
            throw new TransportException('RETRY');
        }
    }

    /**
     * @param string  $originUrl
     * @param mixed[] $additionalOptions
     *
     * @return mixed[]
     */
    protected function getOptionsForUrl(string $originUrl, array $additionalOptions)
    {
        $tlsOptions = array();
        $headers = array();

        if (extension_loaded('zlib')) {
            $headers[] = 'Accept-Encoding: gzip';
        }

        $options = array_replace_recursive($this->options, $tlsOptions, $additionalOptions);
        if (!$this->degradedMode) {
            // degraded mode disables HTTP/1.1 which causes issues with some bad
            // proxies/software due to the use of chunked encoding
            $options['http']['protocol_version'] = 1.1;
            $headers[] = 'Connection: close';
        }

        $headers = $this->authHelper->addAuthenticationHeader($headers, $originUrl, $this->fileUrl);

        $options['http']['follow_location'] = 0;

        if (isset($options['http']['header']) && !is_array($options['http']['header'])) {
            $options['http']['header'] = explode("\r\n", trim($options['http']['header'], "\r\n"));
        }
        foreach ($headers as $header) {
            $options['http']['header'][] = $header;
        }

        return $options;
    }

    /**
     * @param string[]     $http_response_header
     * @param mixed[]      $additionalOptions
     * @param string|false $result
     *
     * @return bool|string
     */
    private function handleRedirect(array $http_response_header, array $additionalOptions, $result)
    {
        if ($locationHeader = Response::findHeaderValue($http_response_header, 'location')) {
            if (parse_url($locationHeader, PHP_URL_SCHEME)) {
                // Absolute URL; e.g. https://example.com/composer
                $targetUrl = $locationHeader;
            } elseif (parse_url($locationHeader, PHP_URL_HOST)) {
                // Scheme relative; e.g. //example.com/foo
                $targetUrl = $this->scheme.':'.$locationHeader;
            } elseif ('/' === $locationHeader[0]) {
                // Absolute path; e.g. /foo
                $urlHost = parse_url($this->fileUrl, PHP_URL_HOST);

                // Replace path using hostname as an anchor.
                $targetUrl = Preg::replace('{^(.+(?://|@)'.preg_quote($urlHost).'(?::\d+)?)(?:[/\?].*)?$}', '\1'.$locationHeader, $this->fileUrl);
            } else {
                // Relative path; e.g. foo
                // This actually differs from PHP which seems to add duplicate slashes.
                $targetUrl = Preg::replace('{^(.+/)[^/?]*(?:\?.*)?$}', '\1'.$locationHeader, $this->fileUrl);
            }
        }

        if (!empty($targetUrl)) {
            $this->redirects++;

            $this->io->writeError('', true, IOInterface::DEBUG);
            $this->io->writeError(sprintf('Following redirect (%u) %s', $this->redirects, Url::sanitize($targetUrl)), true, IOInterface::DEBUG);

            $additionalOptions['redirects'] = $this->redirects;

            return $this->get(parse_url($targetUrl, PHP_URL_HOST), $targetUrl, $additionalOptions, $this->fileName, $this->progress);
        }

        if (!$this->retry) {
            $e = new TransportException('The "'.$this->fileUrl.'" file could not be downloaded, got redirect without Location ('.$http_response_header[0].')');
            $e->setHeaders($http_response_header);
            $e->setResponse($this->decodeResult($result, $http_response_header));

            throw $e;
        }

        return false;
    }

    /**
     * @param string|false $result
     * @param string[]     $http_response_header
     *
     * @return string|null
     */
    private function decodeResult($result, array $http_response_header): ?string
    {
        // decode gzip
        if ($result && extension_loaded('zlib')) {
            $contentEncoding = Response::findHeaderValue($http_response_header, 'content-encoding');
            $decode = $contentEncoding && 'gzip' === strtolower($contentEncoding);

            if ($decode) {
                $result = zlib_decode($result);

                if ($result === false) {
                    throw new TransportException('Failed to decode zlib stream');
                }
            }
        }

        return $this->normalizeResult($result);
    }

    /**
     * @param string|false $result
     *
     * @return string|null
     */
    private function normalizeResult($result): ?string
    {
        if ($result === false) {
            return null;
        }

        return $result;
    }
}
