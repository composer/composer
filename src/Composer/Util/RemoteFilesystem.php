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
     * @return array $options
     */
    public function setOptions(array $options)
    {
        $this->options = array_replace_recursive($this->options, $options);
    }

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
        if (preg_match('{^https?://(.+):(.+)@([^/]+)}i', $fileUrl, $match)) {
            $this->io->setAuthentication($originUrl, urldecode($match[1]), urldecode($match[2]));
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
        $userlandFollow = isset($options['http']['follow_location']) && !$options['http']['follow_location'];

        $origFileUrl = $fileUrl;

        if (isset($options['github-token'])) {
            $fileUrl .= (false === strpos($fileUrl, '?') ? '?' : '&') . 'access_token='.$options['github-token'];
            unset($options['github-token']);
        }

        if (isset($options['gitlab-token'])) {
            $fileUrl .= (false === strpos($fileUrl, '?') ? '?' : '&') . 'access_token='.$options['gitlab-token'];
            unset($options['gitlab-token']);
        }

        if (isset($options['http'])) {
            $options['http']['ignore_errors'] = true;
        }

        if ($this->degradedMode && substr($fileUrl, 0, 21) === 'http://packagist.org/') {
            // access packagist using the resolved IPv4 instead of the hostname to force IPv4 protocol
            $fileUrl = 'http://' . gethostbyname('packagist.org') . substr($fileUrl, 20);
        }

        $ctx = StreamContextFactory::getContext($fileUrl, $options, array('notification' => array($this, 'callbackGet')));

        $actualContextOptions = stream_context_get_options($ctx);
        $usingProxy = !empty($actualContextOptions['http']['proxy']) ? ' using proxy ' . $actualContextOptions['http']['proxy'] : '';
        $this->io->writeError((substr($origFileUrl, 0, 4) === 'http' ? 'Downloading ' : 'Reading ') . $origFileUrl . $usingProxy, true, IOInterface::DEBUG);
        unset($origFileUrl, $actualContextOptions);

        if ($this->progress && !$isRedirect) {
            $this->io->writeError("    Downloading: <comment>Connecting...</comment>", false);
        }

        // Check for secure HTTP, but allow insecure Packagist calls to $hashed providers as file integrity is verified with sha256
        if ((substr($fileUrl, 0, 23) !== 'http://packagist.org/p/' || (false === strpos($fileUrl, '$') && false === strpos($fileUrl, '%24'))) && $this->config) {
            $this->config->prohibitUrlByConfig($fileUrl);
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
            $result = file_get_contents($fileUrl, false, $ctx);

            $contentLength = !empty($http_response_header[0]) ? $this->findHeaderValue($http_response_header, 'content-length') : null;
            if ($contentLength && Platform::strlen($result) < $contentLength) {
                // alas, this is not possible via the stream callback because STREAM_NOTIFY_COMPLETED is documented, but not implemented anywhere in PHP
                throw new TransportException('Content-Length mismatch');
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
        if ($errorMessage && !ini_get('allow_url_fopen')) {
            $errorMessage = 'allow_url_fopen must be enabled in php.ini ('.$errorMessage.')';
        }
        restore_error_handler();
        if (isset($e) && !$this->retry) {
            if (!$this->degradedMode && false !== strpos($e->getMessage(), 'Operation timed out')) {
                $this->degradedMode = true;
                $this->io->writeError(array(
                    '<error>'.$e->getMessage().'</error>',
                    '<error>Retrying with degraded mode, check https://getcomposer.org/doc/articles/troubleshooting.md#degraded-mode for more info</error>',
                ));

                return $this->get($this->originUrl, $this->fileUrl, $additionalOptions, $this->fileName, $this->progress);
            }

            throw $e;
        }

        $statusCode = null;
        if (!empty($http_response_header[0])) {
            $statusCode = $this->findStatusCode($http_response_header);
        }

        // handle 3xx redirects for php<5.6, 304 Not Modified is excluded
        $hasFollowedRedirect = false;
        if ($userlandFollow && $statusCode >= 300 && $statusCode <= 399 && $statusCode !== 304 && $this->redirects < $this->maxRedirects) {
            $hasFollowedRedirect = true;
            $result = $this->handleRedirect($http_response_header, $additionalOptions, $result);
        }

        // fail 4xx and 5xx responses and capture the response
        if ($statusCode && $statusCode >= 400 && $statusCode <= 599) {
            if (!$this->retry) {
                if ($this->progress && !$this->retry && !$isRedirect) {
                    $this->io->overwriteError("    Downloading: <error>Failed</error>");
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
            $this->io->overwriteError("    Downloading: ".($result === false ? '<error>Failed</error>' : '<comment>100%</comment>'));
        }

        // decode gzip
        if ($result && extension_loaded('zlib') && substr($fileUrl, 0, 4) === 'http' && !$hasFollowedRedirect) {
            $decode = 'gzip' === strtolower($this->findHeaderValue($http_response_header, 'content-encoding'));

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
            if (TlsHelper::isOpensslParseSafe()) {
                $certDetails = $this->getCertificateCnAndFp($this->fileUrl, $options);

                if ($certDetails) {
                    $this->peerCertificateMap[$this->getUrlAuthority($this->fileUrl)] = $certDetails;

                    $this->retry = true;
                }
            } else {
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
                // intentional fallthrough to the next case as the notificationCode
                // isn't always consistent and we should inspect the messageCode for 401s

            case STREAM_NOTIFY_AUTH_REQUIRED:
                if (401 === $messageCode) {
                    // Bail if the caller is going to handle authentication failures itself.
                    if (!$this->retryAuthFailure) {
                        break;
                    }

                    $this->promptAuthAndRetry($messageCode);
                }
                break;

            case STREAM_NOTIFY_AUTH_RESULT:
                if (403 === $messageCode) {
                    // Bail if the caller is going to handle authentication failures itself.
                    if (!$this->retryAuthFailure) {
                        break;
                    }

                    $this->promptAuthAndRetry($messageCode, $message);
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
                        $this->io->overwriteError("    Downloading: <comment>$progression%</comment>", false);
                    }
                }
                break;

            default:
                break;
        }
    }

    protected function promptAuthAndRetry($httpStatus, $reason = null)
    {
        if ($this->config && in_array($this->originUrl, $this->config->get('github-domains'), true)) {
            $message = "\n".'Could not fetch '.$this->fileUrl.', please create a GitHub OAuth token '.($httpStatus === 404 ? 'to access private repos' : 'to go over the API rate limit');
            $gitHubUtil = new GitHub($this->io, $this->config, null);
            if (!$gitHubUtil->authorizeOAuth($this->originUrl)
                && (!$this->io->isInteractive() || !$gitHubUtil->authorizeOAuthInteractively($this->originUrl, $message))
            ) {
                throw new TransportException('Could not authenticate against '.$this->originUrl, 401);
            }
        } elseif ($this->config && in_array($this->originUrl, $this->config->get('gitlab-domains'), true)) {
            $message = "\n".'Could not fetch '.$this->fileUrl.', enter your ' . $this->originUrl . ' credentials ' .($httpStatus === 401 ? 'to access private repos' : 'to go over the API rate limit');
            $gitLabUtil = new GitLab($this->io, $this->config, null);
            if (!$gitLabUtil->authorizeOAuth($this->originUrl)
                && (!$this->io->isInteractive() || !$gitLabUtil->authorizeOAuthInteractively($this->scheme, $this->originUrl, $message))
            ) {
                throw new TransportException('Could not authenticate against '.$this->originUrl, 401);
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

            $this->io->overwriteError('    Authentication required (<info>'.parse_url($this->fileUrl, PHP_URL_HOST).'</info>):');
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

            if (PHP_VERSION_ID >= 50304) {
                // Must manually follow when setting CN_match because this causes all
                // redirects to be validated against the same CN_match value.
                $userlandFollow = true;
            } else {
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

                $this->io->writeError(sprintf(
                    'Using <info>%s</info> as CN for subjectAltName enabled host <info>%s</info>',
                    $certMap['cn'],
                    $urlAuthority
                ), true, IOInterface::DEBUG);

                $tlsOptions['ssl']['CN_match'] = $certMap['cn'];
                $tlsOptions['ssl']['peer_fingerprint'] = $certMap['fp'];
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

        if (isset($userlandFollow)) {
            $options['http']['follow_location'] = 0;
        }

        if ($this->io->hasAuthentication($originUrl)) {
            $auth = $this->io->getAuthentication($originUrl);
            if ('github.com' === $originUrl && 'x-oauth-basic' === $auth['password']) {
                $options['github-token'] = $auth['username'];
            } elseif ($this->config && in_array($originUrl, $this->config->get('gitlab-domains'), true)) {
                if ($auth['password'] === 'oauth2') {
                    $headers[] = 'Authorization: Bearer '.$auth['username'];
                }
            } else {
                $authStr = base64_encode($auth['username'] . ':' . $auth['password']);
                $headers[] = 'Authorization: Basic '.$authStr;
            }
        }

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

            $this->io->writeError(sprintf('Following redirect (%u) %s', $this->redirects, $targetUrl), true, IOInterface::DEBUG);

            $additionalOptions['redirects'] = $this->redirects;

            return $this->get($this->originUrl, $targetUrl, $additionalOptions, $this->fileName, $this->progress);
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
            'ECDHE-RSA-RC4-SHA',
            'ECDHE-ECDSA-RC4-SHA',
            'AES128',
            'AES256',
            'RC4-SHA',
            'HIGH',
            '!aNULL',
            '!eNULL',
            '!EXPORT',
            '!DES',
            '!3DES',
            '!MD5',
            '!PSK',
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

        /**
         * Attempt to find a local cafile or throw an exception if none pre-set
         * The user may go download one if this occurs.
         */
        if (!isset($defaults['ssl']['cafile']) && !isset($defaults['ssl']['capath'])) {
            $result = $this->getSystemCaRootBundlePath();

            if (preg_match('{^phar://}', $result)) {
                $hash = hash_file('sha256', $result);
                $targetPath = rtrim(sys_get_temp_dir(), '\\/') . '/composer-cacert-' . $hash . '.pem';

                if (!file_exists($targetPath) || $hash !== hash_file('sha256', $targetPath)) {
                    $this->streamCopy($result, $targetPath);
                    chmod($targetPath, 0666);
                }

                $defaults['ssl']['cafile'] = $targetPath;
            } elseif (is_dir($result)) {
                $defaults['ssl']['capath'] = $result;
            } else {
                $defaults['ssl']['cafile'] = $result;
            }
        }

        if (isset($defaults['ssl']['cafile']) && (!is_readable($defaults['ssl']['cafile']) || !$this->validateCaFile($defaults['ssl']['cafile']))) {
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
     * This method was adapted from Sslurp.
     * https://github.com/EvanDotPro/Sslurp
     *
     * (c) Evan Coury <me@evancoury.com>
     *
     * For the full copyright and license information, please see below:
     *
     * Copyright (c) 2013, Evan Coury
     * All rights reserved.
     *
     * Redistribution and use in source and binary forms, with or without modification,
     * are permitted provided that the following conditions are met:
     *
     *     * Redistributions of source code must retain the above copyright notice,
     *       this list of conditions and the following disclaimer.
     *
     *     * Redistributions in binary form must reproduce the above copyright notice,
     *       this list of conditions and the following disclaimer in the documentation
     *       and/or other materials provided with the distribution.
     *
     * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND
     * ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED
     * WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
     * DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT OWNER OR CONTRIBUTORS BE LIABLE FOR
     * ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES
     * (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;
     * LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON
     * ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
     * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS
     * SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
     *
     * @return string
     */
    private function getSystemCaRootBundlePath()
    {
        static $caPath = null;

        if ($caPath !== null) {
            return $caPath;
        }

        // If SSL_CERT_FILE env variable points to a valid certificate/bundle, use that.
        // This mimics how OpenSSL uses the SSL_CERT_FILE env variable.
        $envCertFile = getenv('SSL_CERT_FILE');
        if ($envCertFile && is_readable($envCertFile) && $this->validateCaFile($envCertFile)) {
            return $caPath = $envCertFile;
        }

        // If SSL_CERT_DIR env variable points to a valid certificate/bundle, use that.
        // This mimics how OpenSSL uses the SSL_CERT_FILE env variable.
        $envCertDir = getenv('SSL_CERT_DIR');
        if ($envCertDir && is_dir($envCertDir) && is_readable($envCertDir)) {
            return $caPath = $envCertDir;
        }

        $configured = ini_get('openssl.cafile');
        if ($configured && strlen($configured) > 0 && is_readable($configured) && $this->validateCaFile($configured)) {
            return $caPath = $configured;
        }

        $configured = ini_get('openssl.capath');
        if ($configured && is_dir($configured) && is_readable($configured)) {
            return $caPath = $configured;
        }

        $caBundlePaths = array(
            '/etc/pki/tls/certs/ca-bundle.crt', // Fedora, RHEL, CentOS (ca-certificates package)
            '/etc/ssl/certs/ca-certificates.crt', // Debian, Ubuntu, Gentoo, Arch Linux (ca-certificates package)
            '/etc/ssl/ca-bundle.pem', // SUSE, openSUSE (ca-certificates package)
            '/usr/local/share/certs/ca-root-nss.crt', // FreeBSD (ca_root_nss_package)
            '/usr/ssl/certs/ca-bundle.crt', // Cygwin
            '/opt/local/share/curl/curl-ca-bundle.crt', // OS X macports, curl-ca-bundle package
            '/usr/local/share/curl/curl-ca-bundle.crt', // Default cURL CA bunde path (without --with-ca-bundle option)
            '/usr/share/ssl/certs/ca-bundle.crt', // Really old RedHat?
            '/etc/ssl/cert.pem', // OpenBSD
            '/usr/local/etc/ssl/cert.pem', // FreeBSD 10.x
        );

        foreach ($caBundlePaths as $caBundle) {
            if (Silencer::call('is_readable', $caBundle) && $this->validateCaFile($caBundle)) {
                return $caPath = $caBundle;
            }
        }

        foreach ($caBundlePaths as $caBundle) {
            $caBundle = dirname($caBundle);
            if (Silencer::call('is_dir', $caBundle) && glob($caBundle.'/*')) {
                return $caPath = $caBundle;
            }
        }

        return $caPath = __DIR__.'/../../../res/cacert.pem'; // Bundled with Composer, last resort
    }

    /**
     * @param string $filename
     *
     * @return bool
     */
    private function validateCaFile($filename)
    {
        static $files = array();

        if (isset($files[$filename])) {
            return $files[$filename];
        }

        $this->io->writeError('Checking CA file '.realpath($filename), true, IOInterface::DEBUG);
        $contents = file_get_contents($filename);

        // assume the CA is valid if php is vulnerable to
        // https://www.sektioneins.de/advisories/advisory-012013-php-openssl_x509_parse-memory-corruption-vulnerability.html
        if (!TlsHelper::isOpensslParseSafe()) {
            $this->io->writeError(sprintf(
                '<error>Your version of PHP, %s, is affected by CVE-2013-6420 and cannot safely perform certificate validation, we strongly suggest you upgrade.</error>',
                PHP_VERSION
            ));

            return $files[$filename] = !empty($contents);
        }

        return $files[$filename] = (bool) openssl_x509_parse($contents);
    }

    /**
     * Uses stream_copy_to_stream instead of copy to work around https://bugs.php.net/bug.php?id=64634
     *
     * @param string $source
     * @param string $target
     */
    private function streamCopy($source, $target)
    {
        $source = fopen($source, 'r');
        $target = fopen($target, 'w+');

        stream_copy_to_stream($source, $target);
        fclose($source);
        fclose($target);

        unset($source, $target);
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
}
