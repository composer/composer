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

namespace Composer\Util\Transfer;

use Composer\Composer;
use Composer\Downloader\TransportException;

/**
 * Driver for data transfer using cUrl library.
 *
 * @author Alexander Goryachev <mail@a-goryachev.ru>
 */
class CurlDriver extends BaseDriver {

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
        
        if ($this->progress) {
            curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, array($this,'progressIndicator'));
            curl_setopt($ch, CURLOPT_NOPROGRESS, false);
            curl_setopt($ch, CURLOPT_BUFFERSIZE, 128);
        }
        
        curl_setopt_array($ch, $options);
        $response = curl_exec($ch);
        $error = curl_error($ch);
        $responseData = array(
            'header' => '',
            'body' => '',
            'curl_error' => '',
            'http_code' => '',
            'last_url' => ''
        );
        $responseData['http_code'] = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
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
        curl_close($ch);

        try {
            if ($responseData['http_code'] == 401 && $this->retryAuthFailure) {
                $this->promptAuthAndRetry($responseData['http_code']);
            }
            if ($responseData['http_code'] == 403 && $this->retryAuthFailure) {
                $this->promptAuthAndRetry($responseData['http_code'], $responseData['headers'][0]);
            }
        } catch (\Exception $e) {
            if ($e instanceof TransportException) {
                $e->setHeaders($responseData['headers'][0]);
                $e->setResponse($result);
            }
            if (!$this->retry) {
                throw $e;
            }
        }

        /* 4xx and 5xx responses */
        if ($responseData['http_code'] >= 400 && $responseData['http_code'] != 401 && $responseData['http_code'] != 403) {
            $e = new TransportException('The "' . $this->fileUrl . '" file could not be downloaded (' . $responseData['headers'][0] . ')', $responseData['http_code']);
            $e->setHeaders($responseData['headers']);
            $e->setResponse($responseData['body']);
            throw $e;
        }

        /* show progress */
        if ($this->progress && !$this->retry) {
            $this->io->overwrite("    Downloading: <comment>100%</comment>");
        }

        /* copy downloaded file */
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
        
        // Handle system proxy
        if (!empty($_SERVER['HTTP_PROXY']) || !empty($_SERVER['http_proxy'])) {
            // Some systems seem to rely on a lowercased version instead...
            $proxy = parse_url(!empty($_SERVER['http_proxy']) ? $_SERVER['http_proxy'] : $_SERVER['HTTP_PROXY']);
        }

        // Override with HTTPS proxy if present and URL is https
        if (preg_match('{^https://}i', $originUrl) && (!empty($_SERVER['HTTPS_PROXY']) || !empty($_SERVER['https_proxy']))) {
            $proxy = parse_url(!empty($_SERVER['https_proxy']) ? $_SERVER['https_proxy'] : $_SERVER['HTTPS_PROXY']);
        }

        // Remove proxy if URL matches no_proxy directive
        if (!empty($_SERVER['no_proxy']) && parse_url($originUrl, PHP_URL_HOST)) {
            $pattern = new \Composer\Util\NoProxyPattern($_SERVER['no_proxy']);
            if ($pattern->test($originUrl)) {
                unset($proxy);
            }
        }

        if (!empty($proxy)) {
            $proxyURL = isset($proxy['scheme']) ? $proxy['scheme'] . '://' : '';
            $proxyURL .= isset($proxy['host']) ? $proxy['host'] : '';

            if (isset($proxy['port'])) {
                $proxyURL .= ":" . $proxy['port'];
            } elseif ('http://' == substr($proxyURL, 0, 7)) {
                $proxyURL .= ":80";
            } elseif ('https://' == substr($proxyURL, 0, 8)) {
                $proxyURL .= ":443";
            }

            // http(s):// is not supported in proxy
            $proxyURL = str_replace(array('http://', 'https://'), array('tcp://', 'ssl://'), $proxyURL);

            if (0 === strpos($proxyURL, 'ssl:') && !extension_loaded('openssl')) {
                throw new \RuntimeException('You must enable the openssl extension to use a proxy over https');
            }

            $curlOptions[CURLOPT_PROXY] = $proxyURL;

            // handle proxy auth if present
            if (isset($proxy['user'])) {
                $auth = urldecode($proxy['user']);
                if (isset($proxy['pass'])) {
                    $auth .= ':' . urldecode($proxy['pass']);
                }
                
                $curlOptions[CURLOPT_PROXYUSERPWD]=$auth;
            }
        }

        return $curlOptions;
    }

    private function progressIndicator($resource, $downloadSize, $downloaded, $uploadSize, $uploaded) {
        if ($this->bytesMax < $downloadSize) {
            $this->bytesMax = $downloadSize;
        }
        if ($this->bytesMax > 0 && $this->progress) {
            $progression = 0;

            if ($this->bytesMax > 0) {
                $progression = round($downloaded / $this->bytesMax * 100);
            }

            if ((0 === $progression % 5) && $progression !== $this->lastProgress) {
                $this->lastProgress = $progression;
                $this->io->overwrite("    Downloading: <comment>$progression%</comment>", false);
            }
        }
        return '';
    }
}
