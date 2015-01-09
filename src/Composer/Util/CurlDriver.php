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
use Composer\Downloader\TransportException;
use Composer\Config;
use Composer\IO\IOInterface;

/**
 * Driver for data transfer using cUrl library.
 *
 * @author Alexander Goryachev <mail@a-goryachev.ru>
 */
class CurlDriver implements TransportInterface {

    private $bytesMax;
    private $originUrl;
    private $fileUrl;
    private $fileName;
    private $retry;
    private $progress;
    private $lastProgress;
    private $retryAuthFailure;
    private $lastHeaders;
    private $storeAuth;
    private $io;
    private $config;
    private $options;

    public function __construct(IOInterface $io, Config $config = null, array $options = array()) {
        $this->io = $io;
        $this->config = $config;
        $this->options = $options;
    }

    public function getOptions() {
        return $this->options;
    }

    public function getLastHeaders() {
        return $this->lastHeaders;
    }

    /**
     * Get file content or copy action.
     *
     * @param string  $originUrl         The origin URL
     * @param string  $fileUrl           The file URL
     * @param array   $additionalOptions context options
     * @param string  $fileName          the local filename
     * @param boolean $progress          Display the progression
     *
     * @throws TransportException|\Exception
     * @throws TransportException            When the file could not be downloaded
     *
     * @return bool|string
     */
    public function get($originUrl, $fileUrl, $additionalOptions = array(), $fileName = null, $progress = true) {
        if (strpos($originUrl, '.github.com') === (strlen($originUrl) - 11)) {
            $originUrl = 'github.com';
        }

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
            $this->io->write((substr($fileUrl, 0, 4) === 'http' ? 'Downloading ' : 'Reading ') . $fileUrl);
        }
        if (isset($options['github-token'])) {
            $fileUrl .= (false === strpos($fileUrl, '?') ? '?' : '&') . 'access_token=' . $options['github-token'];
            unset($options['github-token']);
        }

        if ($this->progress) {
            $this->io->write("    Downloading: <comment>connection...</comment>", false);
        }

        $errorMessage = '';
        $errorCode = 0;
        $result = false;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $fileUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HEADER, 1);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt_array($ch, $options);
        $response = curl_exec($ch);
        curl_close($ch);
        $error = curl_error($ch);
        $responseData = array(
            'header' => '',
            'body' => '',
            'curl_error' => '',
            'http_code' => '',
            'last_url' => ''
        );
        $responseData['http_code'] = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $responseData['last_url'] = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);

        if ($error != "") {
            $responseData['curl_error'] = $error;
        }

        if ($response === false) {
            $e = new TransportException('The "' . $this->fileUrl . '" file could not be downloaded (' . $responseData['curl_error'] . ')', $responseData['http_code']);
            throw $e;
        }

        $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $responseData['headers'] = explode("\r\n", substr($response, 0, $header_size));
        $result = $responseData['body'] = substr($response, $header_size);

        
        // fail 4xx and 5xx responses and capture the response
        if (intval($responseData['http_code']) >= 400) {
            if (!$this->retry) {
                $e = new TransportException('The "' . $this->fileUrl . '" file could not be downloaded (' . $responseData['headers'][0] . ')', $responseData['http_code']);
                $e->setHeaders($responseData['headers']);
                $e->setResponse($responseData['body']);
                throw $e;
            }
            $result = false;
        }
        
        if ($this->progress && !$this->retry) {
            $this->io->overwrite("    Downloading: <comment>100%</comment>");
        }

        // handle copy command if download was successful
        if (false !== $result && null !== $fileName) {
            if ('' === $result) {
                throw new TransportException('"' . $this->fileUrl . '" appears broken, and returned an empty 200 response');
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
                throw new TransportException('The "' . $this->fileUrl . '" file could not be written to ' . $fileName . ': ' . $errorMessage);
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
            $e = new TransportException('The "' . $this->fileUrl . '" file could not be downloaded: ' . $responseData['curl_error'], intval($responseData['http_code']));
            if (!empty($responseData['headers'][0])) {
                $e->setHeaders($responseData['headers']);
            }
            throw $e;
        }

        if (!empty($responseData['headers'][0])) {
            $this->lastHeaders = $responseData['headers'];
        }

        return $result;
    }

    protected function promptAuthAndRetry($httpStatus, $reason = null) {
        if ($this->config && in_array($this->originUrl, $this->config->get('github-domains'), true)) {
            $message = "\n" . 'Could not fetch ' . $this->fileUrl . ', enter your GitHub credentials ' . ($httpStatus === 404 ? 'to access private repos' : 'to go over the API rate limit');
            $gitHubUtil = new GitHub($this->io, $this->config, null, $this);
            if (!$gitHubUtil->authorizeOAuth($this->originUrl) && (!$this->io->isInteractive() || !$gitHubUtil->authorizeOAuthInteractively($this->originUrl, $message))
            ) {
                throw new TransportException('Could not authenticate against ' . $this->originUrl, 401);
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

            $this->io->overwrite('    Authentication required (<info>' . parse_url($this->fileUrl, PHP_URL_HOST) . '</info>):');
            $username = $this->io->ask('      Username: ');
            $password = $this->io->askAndHideAnswer('      Password: ');
            $this->io->setAuthentication($this->originUrl, $username, $password);
            $this->storeAuth = $this->config->get('store-auths');
        }

        $this->retry = true;
        throw new TransportException('RETRY');
    }

    protected function getOptionsForUrl($originUrl, $additionalOptions) {

        $curlOptions = array();

        if (defined('HHVM_VERSION')) {
            $phpVersion = 'HHVM ' . HHVM_VERSION;
        } else {
            $phpVersion = 'PHP ' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.' . PHP_RELEASE_VERSION;
        }

        $curlOptions[CURLOPT_USERAGENT] = sprintf(
                'User-Agent: Composer/%s (%s; %s; %s)', Composer::VERSION === '@package_version@' ? 'source' : Composer::VERSION, php_uname('s'), php_uname('r'), $phpVersion
        );

        if (extension_loaded('zlib')) {
            $curlOptions[CURLOPT_ENCODING] = 'Accept-Encoding: gzip';
        } else {
            $curlOptions[CURLOPT_ENCODING] = 'Accept-Encoding: identity';
        }

        $options = array_replace_recursive($this->options, $additionalOptions);

        if ($this->io->hasAuthentication($originUrl)) {
            $auth = $this->io->getAuthentication($originUrl);
            if ('github.com' === $originUrl && 'x-oauth-basic' === $auth['password']) {
                $curlOptions['github-token'] = $auth['username'];
            } else {
                $curlOptions[CURLOPT_USERPWD] = $auth['username'] . ':' . $auth['password'];
            }
        }

        if (isset($options['http']['header'])) {
            if (!is_array($options['http']['header'])) {
                $options['http']['header'] = explode("\r\n", trim($options['http']['header'], "\r\n"));
            }
            foreach ($options['http']['header'] as $header) {
                $curlOptions[CURLOPT_HTTPHEADER][] = $header;
            }
        }
        return $curlOptions;
    }

}
