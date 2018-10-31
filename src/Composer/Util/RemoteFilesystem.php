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

namespace Composer\Util;

use Composer\Config;
use Composer\IO\IOInterface;
use Composer\Downloader\TransportException;
use Composer\CaBundle\CaBundle;
use Psr\Log\LoggerInterface;

/**
 * @author François Pluchino <francois.pluchino@opendisplay.com>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 * @author Nils Adermann <naderman@naderman.de>
 */
class RemoteFilesystem
{
    private $io;
    private $config;
    private $scheme;
    private $bytesMax;
    private $originUrl;
    private $fileUrl;
    private $fileName;
    private $retry;
    private $progress;
    private $lastProgress;
    private $options = array();
    private $peerCertificateMap = array();
    private $disableTls = false;
    private $retryAuthFailure;
    private $lastHeaders;
    private $storeAuth;
    private $degradedMode = false;
    private $redirects;
    private $maxRedirects = 20;

    /**
     * Constructor.
     *
     * @param IOInterface $io         The IO instance
     * @param Config      $config     The config
     * @param array       $options    The options
     * @param bool        $disableTls
     */
    public function __construct(IOInterface $io, Config $config = null, array $options = array(), $disableTls = false)
    {
        $this->io = $io;

        // Setup TLS options
        // The cafile option can be set via config.json
        if ($disableTls === false) {
            $this->options = $this->getTlsDefaults($options);
        } else {
            $this->disableTls = true;
        }

        // handle the other externally set options normally.
        $this->options = array_replace_recursive($this->options, $options);
        $this->config = $config;
    }

    /**
     * Copy the remote file in local.
     *
     * @param string $originUrl The origin URL
     * @param string $fileUrl   The file URL
     * @param string $fileName  the local filename
     * @param bool   $progress  Display the progression
     * @param array  $options   Additional context options
     *
     * @return bool true
     */
    public function copy($originUrl, $fileUrl, $fileName, $progress = true, $options = array())
    {
        return $this->get($originUrl, $fileUrl, $options, $fileName, $progress);
    }

    /**
     * Get the content.
     *
     * @param string $originUrl The origin URL
     * @param string $fileUrl   The file URL
     * @param bool   $progress  Display the progression
     * @param array  $options   Additional context options
     *
     * @return bool|string The content
     */
    public function getContents($originUrl, $fileUrl, $progress = true, $options = array())
    {
        return $this->get($originUrl, $fileUrl, $options, null, $progress);
    }

    /**
     * Retrieve the options set in the constructor
     *
     * @return array Options
     */
    public function getOptions()
    {
        return $this->options;
    }

    /**
     * Merges new options
     *
     * @param array $options
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
     * @return array
     */
    public function getLastHeaders()
    {
        return $this->lastHeaders;
    }

    /**
     * @param  array       $headers array of returned headers like from getLastHeaders()
     * @param  string      $name    header name (case insensitive)
     * @return string|null
     */
    public function findHeaderValue(array $headers, $name)
    {
        $value = null;
        foreach ($headers as $header) {
            if (preg_match('{^'.$name.':\s*(.+?)\s*$}i', $header, $match)) {
                $value = $match[1];
            } elseif (preg_match('{^HTTP/}i', $header)) {
                // In case of redirects, http_response_headers contains the headers of all responses
                // so we reset the flag when a new response is being parsed as we are only interested in the last response
                $value = null;
            }
        }

        return $value;
    }

    /**
     * @param  array    $headers array of returned headers like from getLastHeaders()
     * @return int|null
     */
    public function findStatusCode(array $headers)
    {
        $value = null;
        foreach ($headers as $header) {
            if (preg_match('{^HTTP/\S+ (\d+)}i', $header, $match)) {
                // In case of redirects, http_response_headers contains the headers of all responses
                // so we can not return directly and need to keep iterating
                $value = (int) $match[1];
            }
        }

        return $value;
    }

    /**
     * @param  array       $headers array of returned headers like from getLastHeaders()
     * @return string|null
     */
    public function findStatusMessage(array $headers)
    {
        $value = null;
        foreach ($headers as $header) {
            if (preg_match('{^HTTP/\S+ \d+}i', $header)) {
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
     * @param string $originUrl         The origin URL
     * @param string $fileUrl           The file URL
     * @param array  $additionalOptions context options
     * @param string $fileName          the local filename
     * @param bool   $progress          Display the progression
     *
     * @throws TransportException|\Exception
     * @throws TransportException            When the file could not be downloaded
     *
     * @return bool|string
     */
    protected function get($originUrl, $fileUrl, $additionalOptions = array(), $fileName = null, $progress = true)
    {
        if (strpos($originUrl, '.github.com') === (strlen($originUrl) - 11)) {
            $originUrl = 'github.com';
        }

        // Gitlab can be installed in a non-root context (i.e. gitlab.com/foo). When downloading archives the originUrl
        // is the host without the path, so we look for the registered gitlab-domains matching the host here
        if (
            $this->config
            && is_array($this->config->get('gitlab-domains'))
            && false === strpos($originUrl, '/')
            && !in_array($originUrl, $this->config->get('gitlab-domains'))
        ) {
            foreach ($this->config->get('gitlab-domains') as $gitlabDomain) {
                if (0 === strpos($gitlabDomain, $originUrl)) {
                    $originUrl = $gitlabDomain;
                    break;
                }
            }
            unset($gitlabDomain);
        }

        $this->scheme = parse_url($fileUrl, PHP_URL_SCHEME);
        $this->bytesMax = 0;
        $this->originUrl = $originUrl;
        $this->fileUrl = $fileUrl;
        $this->fileName = $fileName;
        $this->progress = $progress;
        $this->lastProgress = null;
        $this->retryAuthFailure = true;
        $this->lastHeaders = array();
        $this->redirects = 1; // The first request counts.

        // capture username/password from URL if there is one
        if (preg_match('{^https?://([^:/]+):([^@/]+)@([^/]+)}i', $fileUrl, $match)) {
            $this->io->setAuthentication($originUrl, rawurldecode($match[1]), rawurldecode($match[2]));
        }

        $tempAdditionalOptions = $additionalOptions;
        if (isset($tempAdditionalOptions['retry-auth-failure'])) {
            $this->retryAuthFailure = (bool) $tempAdditionalOptions['retry-auth-failure'];

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

        if (isset($options['github-token'])) {
            // only add the access_token if it is actually a github URL (in case we were redirected to S3)
            if (preg_match('{^https?://([a-z0-9-]+\.)*github\.com/}', $fileUrl)) {
                $fileUrl .= (false === strpos($fileUrl, '?') ? '?' : '&') . 'access_token='.$options['github-token'];
            }
            unset($options['github-token']);
        }

        if (isset($options['gitlab-token'])) {
            $fileUrl .= (false === strpos($fileUrl, '?') ? '?' : '&') . 'access_token='.$options['gitlab-token'];
            unset($options['gitlab-token']);
        }

        if (isset($options['http'])) {
            $options['http']['ignore_errors'] = true;
        }

        if ($this->degradedMode && substr($fileUrl, 0, 26) === 'http://repo.packagist.org/') {
            // access packagist using the resolved IPv4 instead of the hostname to force IPv4 protocol
            $fileUrl = 'http://' . gethostbyname('repo.packagist.org') . substr($fileUrl, 20);
            $degradedPackagist = true;
        }

        $ctx = StreamContextFactory::getContext($fileUrl, $options, array('notification' => array($this, 'callbackGet')));

        $actualContextOptions = stream_context_get_options($ctx);
        $usingProxy = !empty($actualContextOptions['http']['proxy']) ? ' using proxy ' . $actualContextOptions['http']['proxy'] : '';
        $this->io->writeError((substr($origFileUrl, 0, 4) === 'http' ? 'Downloading ' : 'Reading ') . $origFileUrl . $usingProxy, true, IOInterface::DEBUG);
        unset($origFileUrl, $actualContextOptions);

        // Check for secure HTTP, but allow insecure Packagist calls to $hashed providers as file integrity is verified with sha256
        if ((!preg_match('{^http://(repo\.)?packagist\.org/p/}', $fileUrl) || (false === strpos($fileUrl, '$') && false === strpos($fileUrl, '%24'))) && empty($degradedPackagist) && $this->config) {
            $this->config->prohibitUrlByConfig($fileUrl, $this->io);
        }

        if ($this->progress && !$isRedirect) {
            $this->io->writeError("Downloading (<comment>connecting...</comment>)", false);
        }

        $errorMessage = '';
        $errorCode = 0;
        $result = false;
        set_error_handler(function ($code, $msg) use (&$errorMessage) {
            if ($errorMessage) {
                $errorMessage .= "\n";
            }
            $errorMessage .= preg_replace('{^file_get_contents\(.*?\): }', '', $msg);
        });
        try {
            $result = $this->getRemoteContents($originUrl, $fileUrl, $ctx, $http_response_header);

            if (!empty($http_response_header[0])) {
                $statusCode = $this->findStatusCode($http_response_header);
                if (in_array($statusCode, array(401, 403)) && $this->retryAuthFailure) {
                    $warning = null;
                    if ($this->findHeaderValue($http_response_header, 'content-type') === 'application/json') {
                        $data = json_decode($result, true);
                        if (!empty($data['warning'])) {
                            $warning = $data['warning'];
                        }
                    }
                    $this->promptAuthAndRetry($statusCode, $this->findStatusMessage($http_response_header), $warning, $http_response_header);
                }
            }

            $contentLength = !empty($http_response_header[0]) ? $this->findHeaderValue($http_response_header, 'content-length') : null;
            if ($contentLength && Platform::strlen($result) < $contentLength) {
                // alas, this is not possible via the stream callback because STREAM_NOTIFY_COMPLETED is documented, but not implemented anywhere in PHP
                $e = new TransportException('Content-Length mismatch, received '.Platform::strlen($result).' bytes out of the expected '.$contentLength);
                $e->setHeaders($http_response_header);
                $e->setStatusCode($this->findStatusCode($http_response_header));
                $e->setResponse($result);
                $this->io->writeError('Content-Length mismatch, received '.Platform::strlen($result).' out of '.$contentLength.' bytes: (' . base64_encode($result).')', true, IOInterface::DEBUG);

                throw $e;
            }

            if (PHP_VERSION_ID < 50600 && !empty($options['ssl']['peer_fingerprint'])) {
                // Emulate fingerprint validation on PHP < 5.6
                $params = stream_context_get_params($ctx);
                $expectedPeerFingerprint = $options['ssl']['peer_fingerprint'];
                $peerFingerprint = TlsHelper::getCertificateFingerprint($params['options']['ssl']['peer_certificate']);

                // Constant time compare??!
                if ($expectedPeerFingerprint !== $peerFingerprint) {
                    throw new TransportException('Peer fingerprint did not match');
                }
            }
        } catch (\Exception $e) {
            if ($e instanceof TransportException && !empty($http_response_header[0])) {
                $e->setHeaders($http_response_header);
                $e->setStatusCode($this->findStatusCode($http_response_header));
            }
            if ($e instanceof TransportException && $result !== false) {
                $e->setResponse($result);
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
            $statusCode = $this->findStatusCode($http_response_header);
            $contentType = $this->findHeaderValue($http_response_header, 'content-type');
            $locationHeader = $this->findHeaderValue($http_response_header, 'location');
        }

        // check for bitbucket login page asking to authenticate
        if ($originUrl === 'bitbucket.org'
            && !$this->isPublicBitBucketDownload($fileUrl)
            && substr($fileUrl, -4) === '.zip'
            && (!$locationHeader || substr($locationHeader, -4) !== '.zip')
            && $contentType && preg_match('{^text/html\b}i', $contentType)
        ) {
            $result = false;
            if ($this->retryAuthFailure) {
                $this->promptAuthAndRetry(401);
            }
        }

        // check for gitlab 404 when downloading archives
        if ($statusCode === 404
            && $this->config && in_array($originUrl, $this->config->get('gitlab-domains'), true)
            && false !== strpos($fileUrl, 'archive.zip')
        ) {
            $result = false;
            if ($this->retryAuthFailure) {
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
                if ($this->progress && !$this->retry && !$isRedirect) {
                    $this->io->overwriteError("Downloading (<error>failed</error>)", false);
                }

                $e = new TransportException('The "'.$this->fileUrl.'" file could not be downloaded ('.$http_response_header[0].')', $statusCode);
                $e->setHeaders($http_response_header);
                $e->setResponse($result);
                $e->setStatusCode($statusCode);
                throw $e;
            }
            $result = false;
        }

        if ($this->progress && !$this->retry && !$isRedirect) {
            $this->io->overwriteError("Downloading (".($result === false ? '<error>failed</error>' : '<comment>100%</comment>').")", false);
        }

        // decode gzip
        if ($result && extension_loaded('zlib') && substr($fileUrl, 0, 4) === 'http' && !$hasFollowedRedirect) {
            $contentEncoding = $this->findHeaderValue($http_response_header, 'content-encoding');
            $decode = $contentEncoding && 'gzip' === strtolower($contentEncoding);

            if ($decode) {
                try {
                    if (PHP_VERSION_ID >= 50400) {
                        $result = zlib_decode($result);
                    } else {
                        // work around issue with gzuncompress & co that do not work with all gzip checksums
                        $result = file_get_contents('compress.zlib://data:application/octet-stream;base64,'.base64_encode($result));
                    }

                    if (!$result) {
                        throw new TransportException('Failed to decode zlib stream');
                    }
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
        }

        // handle copy command if download was successful
        if (false !== $result && null !== $fileName && !$isRedirect) {
            if ('' === $result) {
                throw new TransportException('"'.$this->fileUrl.'" appears broken, and returned an empty 200 response');
            }

            $errorMessage = '';
            set_error_handler(function ($code, $msg) use (&$errorMessage) {
                if ($errorMessage) {
                    $errorMessage .= "\n";
                }
                $errorMessage .= preg_replace('{^file_put_contents\(.*?\): }', '', $msg);
            });
            $result = (bool) file_put_contents($fileName, $result);
            restore_error_handler();
            if (false === $result) {
                throw new TransportException('The "'.$this->fileUrl.'" file could not be written to '.$fileName.': '.$errorMessage);
            }
        }

        // Handle SSL cert match issues
        if (false === $result && false !== strpos($errorMessage, 'Peer certificate') && PHP_VERSION_ID < 50600) {
            // Certificate name error, PHP doesn't support subjectAltName on PHP < 5.6
            // The procedure to handle sAN for older PHP's is:
            //
            // 1. Open socket to remote server and fetch certificate (disabling peer
            //    validation because PHP errors without giving up the certificate.)
            //
            // 2. Verifying the domain in the URL against the names in the sAN field.
            //    If there is a match record the authority [host/port], certificate
            //    common name, and certificate fingerprint.
            //
            // 3. Retry the original request but changing the CN_match parameter to
            //    the common name extracted from the certificate in step 2.
            //
            // 4. To prevent any attempt at being hoodwinked by switching the
            //    certificate between steps 2 and 3 the fingerprint of the certificate
            //    presented in step 3 is compared against the one recorded in step 2.
            if (CaBundle::isOpensslParseSafe()) {
                $certDetails = $this->getCertificateCnAndFp($this->fileUrl, $options);

                if ($certDetails) {
                    $this->peerCertificateMap[$this->getUrlAuthority($this->fileUrl)] = $certDetails;

                    $this->retry = true;
                }
            } else {
                $this->io->writeError('');
                $this->io->writeError(sprintf(
                    '<error>Your version of PHP, %s, is affected by CVE-2013-6420 and cannot safely perform certificate validation, we strongly suggest you upgrade.</error>',
                    PHP_VERSION
                ));
            }
        }

        if ($this->retry) {
            $this->retry = false;

            $result = $this->get($this->originUrl, $this->fileUrl, $additionalOptions, $this->fileName, $this->progress);

            if ($this->storeAuth && $this->config) {
                $authHelper = new AuthHelper($this->io, $this->config);
                $authHelper->storeAuth($this->originUrl, $this->storeAuth);
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
     * @param string   $originUrl The origin URL
     * @param string   $fileUrl   The file URL
     * @param resource $context   The stream context
     *
     * @return string|false The response contents or false on failure
     */
    protected function getRemoteContents($originUrl, $fileUrl, $context, array &$responseHeaders = null)
    {
        try {
            $e = null;
            $result = file_get_contents($fileUrl, false, $context);
        } catch (\Throwable $e) {
        } catch (\Exception $e) {
        }

        $responseHeaders = isset($http_response_header) ? $http_response_header : array();

        if (null !== $e) {
            throw $e;
        }

        return $result;
    }

    /**
     * Get notification action.
     *
     * @param  int                $notificationCode The notification code
     * @param  int                $severity         The severity level
     * @param  string             $message          The message
     * @param  int                $messageCode      The message code
     * @param  int                $bytesTransferred The loaded size
     * @param  int                $bytesMax         The total size
     * @throws TransportException
     */
    protected function callbackGet($notificationCode, $severity, $message, $messageCode, $bytesTransferred, $bytesMax)
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
                    $progression = min(100, round($bytesTransferred / $this->bytesMax * 100));

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

    protected function promptAuthAndRetry($httpStatus, $reason = null, $warning = null, $headers = array())
    {
        if ($this->config && in_array($this->originUrl, $this->config->get('github-domains'), true)) {
            $gitHubUtil = new GitHub($this->io, $this->config, null);
            $message = "\n";

            $rateLimited = $gitHubUtil->isRateLimited($headers);
            if ($rateLimited) {
                $rateLimit = $gitHubUtil->getRateLimit($headers);
                if ($this->io->hasAuthentication($this->originUrl)) {
                    $message = 'Review your configured GitHub OAuth token or enter a new one to go over the API rate limit.';
                } else {
                    $message = 'Create a GitHub OAuth token to go over the API rate limit.';
                }

                $message = sprintf(
                    'GitHub API limit (%d calls/hr) is exhausted, could not fetch '.$this->fileUrl.'. '.$message.' You can also wait until %s for the rate limit to reset.',
                    $rateLimit['limit'],
                    $rateLimit['reset']
                )."\n";
            } else {
                $message .= 'Could not fetch '.$this->fileUrl.', please ';
                if ($this->io->hasAuthentication($this->originUrl)) {
                    $message .= 'review your configured GitHub OAuth token or enter a new one to access private repos';
                } else {
                    $message .= 'create a GitHub OAuth token to access private repos';
                }
            }

            if (!$gitHubUtil->authorizeOAuth($this->originUrl)
                && (!$this->io->isInteractive() || !$gitHubUtil->authorizeOAuthInteractively($this->originUrl, $message))
            ) {
                throw new TransportException('Could not authenticate against '.$this->originUrl, 401);
            }
        } elseif ($this->config && in_array($this->originUrl, $this->config->get('gitlab-domains'), true)) {
            $message = "\n".'Could not fetch '.$this->fileUrl.', enter your ' . $this->originUrl . ' credentials ' .($httpStatus === 401 ? 'to access private repos' : 'to go over the API rate limit');
            $gitLabUtil = new GitLab($this->io, $this->config, null);

            if ($this->io->hasAuthentication($this->originUrl) && ($auth = $this->io->getAuthentication($this->originUrl)) && $auth['password'] === 'private-token') {
                throw new TransportException("Invalid credentials for '" . $this->fileUrl . "', aborting.", $httpStatus);
            }

            if (!$gitLabUtil->authorizeOAuth($this->originUrl)
                && (!$this->io->isInteractive() || !$gitLabUtil->authorizeOAuthInteractively($this->scheme, $this->originUrl, $message))
            ) {
                throw new TransportException('Could not authenticate against '.$this->originUrl, 401);
            }
        } elseif ($this->config && $this->originUrl === 'bitbucket.org') {
            $askForOAuthToken = true;
            if ($this->io->hasAuthentication($this->originUrl)) {
                $auth = $this->io->getAuthentication($this->originUrl);
                if ($auth['username'] !== 'x-token-auth') {
                    $bitbucketUtil = new Bitbucket($this->io, $this->config);
                    $accessToken = $bitbucketUtil->requestToken($this->originUrl, $auth['username'], $auth['password']);
                    if (!empty($accessToken)) {
                        $this->io->setAuthentication($this->originUrl, 'x-token-auth', $accessToken);
                        $askForOAuthToken = false;
                    }
                } else {
                    throw new TransportException('Could not authenticate against ' . $this->originUrl, 401);
                }
            }

            if ($askForOAuthToken) {
                $message = "\n".'Could not fetch ' . $this->fileUrl . ', please create a bitbucket OAuth token to ' . (($httpStatus === 401 || $httpStatus === 403) ? 'access private repos' : 'go over the API rate limit');
                $bitBucketUtil = new Bitbucket($this->io, $this->config);
                if (! $bitBucketUtil->authorizeOAuth($this->originUrl)
                    && (! $this->io->isInteractive() || !$bitBucketUtil->authorizeOAuthInteractively($this->originUrl, $message))
                ) {
                    throw new TransportException('Could not authenticate against ' . $this->originUrl, 401);
                }
            }
        } else {
            // 404s are only handled for github
            if ($httpStatus === 404) {
                return;
            }

            // fail if the console is not interactive
            if (!$this->io->isInteractive()) {
                if ($httpStatus === 401) {
                    $message = "The '" . $this->fileUrl . "' URL required authentication.\nYou must be using the interactive console to authenticate";
                }
                if ($httpStatus === 403) {
                    $message = "The '" . $this->fileUrl . "' URL could not be accessed: " . $reason;
                }

                throw new TransportException($message, $httpStatus);
            }
            // fail if we already have auth
            if ($this->io->hasAuthentication($this->originUrl)) {
                throw new TransportException("Invalid credentials for '" . $this->fileUrl . "', aborting.", $httpStatus);
            }

            $this->io->overwriteError('');
            if ($warning) {
                $this->io->writeError('    <warning>'.$warning.'</warning>');
            }
            $this->io->writeError('    Authentication required (<info>'.parse_url($this->fileUrl, PHP_URL_HOST).'</info>):');
            $username = $this->io->ask('      Username: ');
            $password = $this->io->askAndHideAnswer('      Password: ');
            $this->io->setAuthentication($this->originUrl, $username, $password);
            $this->storeAuth = $this->config->get('store-auths');
        }

        $this->retry = true;
        throw new TransportException('RETRY');
    }

    protected function getOptionsForUrl($originUrl, $additionalOptions)
    {
        $tlsOptions = array();

        // Setup remaining TLS options - the matching may need monitoring, esp. www vs none in CN
        if ($this->disableTls === false && PHP_VERSION_ID < 50600 && !stream_is_local($this->fileUrl)) {
            $host = parse_url($this->fileUrl, PHP_URL_HOST);

            if (PHP_VERSION_ID < 50304) {
                // PHP < 5.3.4 does not support follow_location, for those people
                // do some really nasty hard coded transformations. These will
                // still breakdown if the site redirects to a domain we don't
                // expect.

                if ($host === 'github.com' || $host === 'api.github.com') {
                    $host = '*.github.com';
                }
            }

            $tlsOptions['ssl']['CN_match'] = $host;
            $tlsOptions['ssl']['SNI_server_name'] = $host;

            $urlAuthority = $this->getUrlAuthority($this->fileUrl);

            if (isset($this->peerCertificateMap[$urlAuthority])) {
                // Handle subjectAltName on lesser PHP's.
                $certMap = $this->peerCertificateMap[$urlAuthority];

                $this->io->writeError('', true, IOInterface::DEBUG);
                $this->io->writeError(sprintf(
                    'Using <info>%s</info> as CN for subjectAltName enabled host <info>%s</info>',
                    $certMap['cn'],
                    $urlAuthority
                ), true, IOInterface::DEBUG);

                $tlsOptions['ssl']['CN_match'] = $certMap['cn'];
                $tlsOptions['ssl']['peer_fingerprint'] = $certMap['fp'];
            } elseif (!CaBundle::isOpensslParseSafe() && $host === 'repo.packagist.org') {
                // handle subjectAltName for packagist.org's repo domain on very old PHPs
                $tlsOptions['ssl']['CN_match'] = 'packagist.org';
            }
        }

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

        if ($this->io->hasAuthentication($originUrl)) {
            $auth = $this->io->getAuthentication($originUrl);
            if ('github.com' === $originUrl && 'x-oauth-basic' === $auth['password']) {
                $options['github-token'] = $auth['username'];
            } elseif ($this->config && in_array($originUrl, $this->config->get('gitlab-domains'), true)) {
                if ($auth['password'] === 'oauth2') {
                    $headers[] = 'Authorization: Bearer '.$auth['username'];
                } elseif ($auth['password'] === 'private-token') {
                    $headers[] = 'PRIVATE-TOKEN: '.$auth['username'];
                }
            } elseif ('bitbucket.org' === $originUrl
                && $this->fileUrl !== Bitbucket::OAUTH2_ACCESS_TOKEN_URL && 'x-token-auth' === $auth['username']
            ) {
                if (!$this->isPublicBitBucketDownload($this->fileUrl)) {
                    $headers[] = 'Authorization: Bearer ' . $auth['password'];
                }
            } else {
                $authStr = base64_encode($auth['username'] . ':' . $auth['password']);
                $headers[] = 'Authorization: Basic '.$authStr;
            }
        }

        $options['http']['follow_location'] = 0;

        if (isset($options['http']['header']) && !is_array($options['http']['header'])) {
            $options['http']['header'] = explode("\r\n", trim($options['http']['header'], "\r\n"));
        }
        foreach ($headers as $header) {
            $options['http']['header'][] = $header;
        }

        return $options;
    }

    private function handleRedirect(array $http_response_header, array $additionalOptions, $result)
    {
        if ($locationHeader = $this->findHeaderValue($http_response_header, 'location')) {
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
                $targetUrl = preg_replace('{^(.+(?://|@)'.preg_quote($urlHost).'(?::\d+)?)(?:[/\?].*)?$}', '\1'.$locationHeader, $this->fileUrl);
            } else {
                // Relative path; e.g. foo
                // This actually differs from PHP which seems to add duplicate slashes.
                $targetUrl = preg_replace('{^(.+/)[^/?]*(?:\?.*)?$}', '\1'.$locationHeader, $this->fileUrl);
            }
        }

        if (!empty($targetUrl)) {
            $this->redirects++;

            $this->io->writeError('', true, IOInterface::DEBUG);
            $this->io->writeError(sprintf('Following redirect (%u) %s', $this->redirects, $targetUrl), true, IOInterface::DEBUG);

            $additionalOptions['redirects'] = $this->redirects;

            return $this->get(parse_url($targetUrl, PHP_URL_HOST), $targetUrl, $additionalOptions, $this->fileName, $this->progress);
        }

        if (!$this->retry) {
            $e = new TransportException('The "'.$this->fileUrl.'" file could not be downloaded, got redirect without Location ('.$http_response_header[0].')');
            $e->setHeaders($http_response_header);
            $e->setResponse($result);

            throw $e;
        }

        return false;
    }

    /**
     * @param array $options
     *
     * @return array
     */
    private function getTlsDefaults(array $options)
    {
        $ciphers = implode(':', array(
            'ECDHE-RSA-AES128-GCM-SHA256',
            'ECDHE-ECDSA-AES128-GCM-SHA256',
            'ECDHE-RSA-AES256-GCM-SHA384',
            'ECDHE-ECDSA-AES256-GCM-SHA384',
            'DHE-RSA-AES128-GCM-SHA256',
            'DHE-DSS-AES128-GCM-SHA256',
            'kEDH+AESGCM',
            'ECDHE-RSA-AES128-SHA256',
            'ECDHE-ECDSA-AES128-SHA256',
            'ECDHE-RSA-AES128-SHA',
            'ECDHE-ECDSA-AES128-SHA',
            'ECDHE-RSA-AES256-SHA384',
            'ECDHE-ECDSA-AES256-SHA384',
            'ECDHE-RSA-AES256-SHA',
            'ECDHE-ECDSA-AES256-SHA',
            'DHE-RSA-AES128-SHA256',
            'DHE-RSA-AES128-SHA',
            'DHE-DSS-AES128-SHA256',
            'DHE-RSA-AES256-SHA256',
            'DHE-DSS-AES256-SHA',
            'DHE-RSA-AES256-SHA',
            'AES128-GCM-SHA256',
            'AES256-GCM-SHA384',
            'AES128-SHA256',
            'AES256-SHA256',
            'AES128-SHA',
            'AES256-SHA',
            'AES',
            'CAMELLIA',
            'DES-CBC3-SHA',
            '!aNULL',
            '!eNULL',
            '!EXPORT',
            '!DES',
            '!RC4',
            '!MD5',
            '!PSK',
            '!aECDH',
            '!EDH-DSS-DES-CBC3-SHA',
            '!EDH-RSA-DES-CBC3-SHA',
            '!KRB5-DES-CBC3-SHA',
        ));

        /**
         * CN_match and SNI_server_name are only known once a URL is passed.
         * They will be set in the getOptionsForUrl() method which receives a URL.
         *
         * cafile or capath can be overridden by passing in those options to constructor.
         */
        $defaults = array(
            'ssl' => array(
                'ciphers' => $ciphers,
                'verify_peer' => true,
                'verify_depth' => 7,
                'SNI_enabled' => true,
                'capture_peer_cert' => true,
            ),
        );

        if (isset($options['ssl'])) {
            $defaults['ssl'] = array_replace_recursive($defaults['ssl'], $options['ssl']);
        }

        $caBundleLogger = $this->io instanceof LoggerInterface ? $this->io : null;

        /**
         * Attempt to find a local cafile or throw an exception if none pre-set
         * The user may go download one if this occurs.
         */
        if (!isset($defaults['ssl']['cafile']) && !isset($defaults['ssl']['capath'])) {
            $result = CaBundle::getSystemCaRootBundlePath($caBundleLogger);

            if (is_dir($result)) {
                $defaults['ssl']['capath'] = $result;
            } else {
                $defaults['ssl']['cafile'] = $result;
            }
        }

        if (isset($defaults['ssl']['cafile']) && (!is_readable($defaults['ssl']['cafile']) || !CaBundle::validateCaFile($defaults['ssl']['cafile'], $caBundleLogger))) {
            throw new TransportException('The configured cafile was not valid or could not be read.');
        }

        if (isset($defaults['ssl']['capath']) && (!is_dir($defaults['ssl']['capath']) || !is_readable($defaults['ssl']['capath']))) {
            throw new TransportException('The configured capath was not valid or could not be read.');
        }

        /**
         * Disable TLS compression to prevent CRIME attacks where supported.
         */
        if (PHP_VERSION_ID >= 50413) {
            $defaults['ssl']['disable_compression'] = true;
        }

        return $defaults;
    }

    /**
     * Fetch certificate common name and fingerprint for validation of SAN.
     *
     * @todo Remove when PHP 5.6 is minimum supported version.
     */
    private function getCertificateCnAndFp($url, $options)
    {
        if (PHP_VERSION_ID >= 50600) {
            throw new \BadMethodCallException(sprintf(
                '%s must not be used on PHP >= 5.6',
                __METHOD__
            ));
        }

        $context = StreamContextFactory::getContext($url, $options, array('options' => array(
            'ssl' => array(
                'capture_peer_cert' => true,
                'verify_peer' => false, // Yes this is fucking insane! But PHP is lame.
            ), ),
        ));

        // Ideally this would just use stream_socket_client() to avoid sending a
        // HTTP request but that does not capture the certificate.
        if (false === $handle = @fopen($url, 'rb', false, $context)) {
            return;
        }

        // Close non authenticated connection without reading any content.
        fclose($handle);
        $handle = null;

        $params = stream_context_get_params($context);

        if (!empty($params['options']['ssl']['peer_certificate'])) {
            $peerCertificate = $params['options']['ssl']['peer_certificate'];

            if (TlsHelper::checkCertificateHost($peerCertificate, parse_url($url, PHP_URL_HOST), $commonName)) {
                return array(
                    'cn' => $commonName,
                    'fp' => TlsHelper::getCertificateFingerprint($peerCertificate),
                );
            }
        }
    }

    private function getUrlAuthority($url)
    {
        $defaultPorts = array(
            'ftp' => 21,
            'http' => 80,
            'https' => 443,
            'ssh2.sftp' => 22,
            'ssh2.scp' => 22,
        );

        $scheme = parse_url($url, PHP_URL_SCHEME);

        if (!isset($defaultPorts[$scheme])) {
            throw new \InvalidArgumentException(sprintf(
                'Could not get default port for unknown scheme: %s',
                $scheme
            ));
        }

        $defaultPort = $defaultPorts[$scheme];
        $port = parse_url($url, PHP_URL_PORT) ?: $defaultPort;

        return parse_url($url, PHP_URL_HOST).':'.$port;
    }

    /**
     * @link https://github.com/composer/composer/issues/5584
     *
     * @param string $urlToBitBucketFile URL to a file at bitbucket.org.
     *
     * @return bool Whether the given URL is a public BitBucket download which requires no authentication.
     */
    private function isPublicBitBucketDownload($urlToBitBucketFile)
    {
        $domain = parse_url($urlToBitBucketFile, PHP_URL_HOST);
        if (strpos($domain, 'bitbucket.org') === false) {
            // Bitbucket downloads are hosted on amazonaws.
            // We do not need to authenticate there at all
            return true;
        }

        $path = parse_url($urlToBitBucketFile, PHP_URL_PATH);

        // Path for a public download follows this pattern /{user}/{repo}/downloads/{whatever}
        // {@link https://blog.bitbucket.org/2009/04/12/new-feature-downloads/}
        $pathParts = explode('/', $path);

        return count($pathParts) >= 4 && $pathParts[3] == 'downloads';
    }
}
