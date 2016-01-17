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

use Composer\Composer;
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
    private $bytesMax;
    private $originUrl;
    private $fileUrl;
    private $fileName;
    private $retry;
    private $progress;
    private $lastProgress;
    private $options = array();
    private $disableTls = false;
    private $retryAuthFailure;
    private $lastHeaders;
    private $storeAuth;
    private $degradedMode = false;

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
            $this->options = $this->getTlsDefaults();
            if (isset($options['ssl']['cafile'])
                && (
                    !is_readable($options['ssl']['cafile'])
                    || !self::validateCaFile(file_get_contents($options['ssl']['cafile']))
                )
            ) {
                throw new TransportException('The configured cafile was not valid or could not be read.');
            }
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

        // capture username/password from URL if there is one
        if (preg_match('{^https?://(.+):(.+)@([^/]+)}i', $fileUrl, $match)) {
            $this->io->setAuthentication($originUrl, urldecode($match[1]), urldecode($match[2]));
        }

        if (isset($additionalOptions['retry-auth-failure'])) {
            $this->retryAuthFailure = (bool) $additionalOptions['retry-auth-failure'];

            unset($additionalOptions['retry-auth-failure']);
        }

        $options = $this->getOptionsForUrl($originUrl, $additionalOptions);

        if ($this->io->isDebug()) {
            $this->io->writeError((substr($fileUrl, 0, 4) === 'http' ? 'Downloading ' : 'Reading ') . $fileUrl);
        }

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

        if ($this->progress) {
            $this->io->writeError("    Downloading: <comment>Connecting...</comment>", false);
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
        } catch (\Exception $e) {
            if ($e instanceof TransportException && !empty($http_response_header[0])) {
                $e->setHeaders($http_response_header);
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

        // fail 4xx and 5xx responses and capture the response
        if (!empty($http_response_header[0]) && preg_match('{^HTTP/\S+ ([45]\d\d)}i', $http_response_header[0], $match)) {
            $errorCode = $match[1];
            if (!$this->retry) {
                $e = new TransportException('The "'.$this->fileUrl.'" file could not be downloaded ('.$http_response_header[0].')', $errorCode);
                $e->setHeaders($http_response_header);
                $e->setResponse($result);
                throw $e;
            }
            $result = false;
        }

        if ($this->progress && !$this->retry) {
            $this->io->overwriteError("    Downloading: <comment>100%</comment>");
        }

        // decode gzip
        if ($result && extension_loaded('zlib') && substr($fileUrl, 0, 4) === 'http') {
            $decode = false;
            foreach ($http_response_header as $header) {
                if (preg_match('{^content-encoding: *gzip *$}i', $header)) {
                    $decode = true;
                } elseif (preg_match('{^HTTP/}i', $header)) {
                    // In case of redirects, http_response_headers contains the headers of all responses
                    // so we reset the flag when a new response is being parsed as we are only interested in the last response
                    $decode = false;
                }
            }

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
        if (false !== $result && null !== $fileName) {
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

        if ($this->retry) {
            $this->retry = false;

            $result = $this->get($this->originUrl, $this->fileUrl, $additionalOptions, $this->fileName, $this->progress);

            $authHelper = new AuthHelper($this->io, $this->config);
            $authHelper->storeAuth($this->originUrl, $this->storeAuth);
            $this->storeAuth = false;

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
                if ($this->bytesMax < $bytesMax) {
                    $this->bytesMax = $bytesMax;
                }
                break;

            case STREAM_NOTIFY_PROGRESS:
                if ($this->bytesMax > 0 && $this->progress) {
                    $progression = round($bytesTransferred / $this->bytesMax * 100);

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
        if ($this->disableTls === false && PHP_VERSION_ID < 50600) {
            if (!preg_match('{^https?://}', $this->fileUrl)) {
                $host = $originUrl;
            } else {
                $host = parse_url($this->fileUrl, PHP_URL_HOST);
            }

            if ($host === 'github.com' || $host === 'api.github.com') {
                $host = '*.github.com';
            }

            $tlsOptions['ssl']['CN_match'] = $host;
            $tlsOptions['ssl']['SNI_server_name'] = $host;
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

    private function getTlsDefaults()
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
            '!PSK'
        ));

        /**
         * CN_match and SNI_server_name are only known once a URL is passed.
         * They will be set in the getOptionsForUrl() method which receives a URL.
         *
         * cafile or capath can be overridden by passing in those options to constructor.
         */
        $options = array(
            'ssl' => array(
                'ciphers' => $ciphers,
                'verify_peer' => true,
                'verify_depth' => 7,
                'SNI_enabled' => true,
            )
        );

        /**
         * Attempt to find a local cafile or throw an exception if none pre-set
         * The user may go download one if this occurs.
         */
        if (!isset($this->options['ssl']['cafile'])) {
            $result = self::getSystemCaRootBundlePath();
            if ($result) {
                if (preg_match('{^phar://}', $result)) {
                    $targetPath = rtrim(sys_get_temp_dir(), '\\/') . '/composer-cacert.pem';

                    // use stream_copy_to_stream instead of copy
                    // to work around https://bugs.php.net/bug.php?id=64634
                    $source = fopen($result, 'r');
                    $target = fopen($targetPath, 'w+');
                    stream_copy_to_stream($source, $target);
                    fclose($source);
                    fclose($target);
                    unset($source, $target);

                    $options['ssl']['cafile'] = $targetPath;
                } else {
                    if (is_dir($result)) {
                        $options['ssl']['capath'] = $result;
                    } elseif ($result) {
                        $options['ssl']['cafile'] = $result;
                    }
                }
            } else {
                throw new TransportException('A valid cafile could not be located automatically.');
            }
        }

        /**
         * Disable TLS compression to prevent CRIME attacks where supported.
         */
        if (PHP_VERSION_ID >= 50413) {
            $options['ssl']['disable_compression'] = true;
        }

        return $options;
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
    */
    private static function getSystemCaRootBundlePath()
    {
        static $caPath = null;

        if ($caPath !== null) {
            return $caPath;
        }

        // If SSL_CERT_FILE env variable points to a valid certificate/bundle, use that.
        // This mimics how OpenSSL uses the SSL_CERT_FILE env variable.
        $envCertFile = getenv('SSL_CERT_FILE');
        if ($envCertFile && is_readable($envCertFile) && self::validateCaFile(file_get_contents($envCertFile))) {
            // Possibly throw exception instead of ignoring SSL_CERT_FILE if it's invalid?
            return $caPath = $envCertFile;
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
            __DIR__.'/../../../res/cacert.pem', // Bundled with Composer
        );

        $configured = ini_get('openssl.cafile');
        if ($configured && strlen($configured) > 0 && is_readable($caBundle) && self::validateCaFile(file_get_contents($caBundle))) {
            return $caPath = $configured;
        }

        foreach ($caBundlePaths as $caBundle) {
            if (@is_readable($caBundle) && self::validateCaFile(file_get_contents($caBundle))) {
                return $caPath = $caBundle;
            }
        }

        foreach ($caBundlePaths as $caBundle) {
            $caBundle = dirname($caBundle);
            if (is_dir($caBundle) && glob($caBundle.'/*')) {
                return $caPath = $caBundle;
            }
        }

        return $caPath = false;
    }

    private static function validateCaFile($contents)
    {
        // assume the CA is valid if php is vulnerable to
        // https://www.sektioneins.de/advisories/advisory-012013-php-openssl_x509_parse-memory-corruption-vulnerability.html
        if (
            PHP_VERSION_ID <= 50327
            || (PHP_VERSION_ID >= 50400 && PHP_VERSION_ID < 50422)
            || (PHP_VERSION_ID >= 50500 && PHP_VERSION_ID < 50506)
        ) {
            return !empty($contents);
        }

        return (bool) openssl_x509_parse($contents);
    }
}
