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
use Composer\IO\IOInterface;
use Composer\Downloader\TransportException;

/**
 * @author François Pluchino <francois.pluchino@opendisplay.com>
 * @author Flávio Heleno <flaviohbatista@gmail.com>
 */
class StreamDriver
{
    private $io;
    private $firstCall;
    private $bytesMax;
    private $originUrl;
    private $fileUrl;
    private $progress;
    private $lastProgress;
    private $options;

    public function __construct(IOInterface $io, $options = array())
    {
        $this->io = $io;
        $this->options = $options;
    }

    /**
     * Get file content.
     *
     * @param string  $originUrl         The origin URL
     * @param string  $fileUrl           The file URL
     * @param array   $additionalOptions context options
     * @param boolean $progress          Display the progression
     *
     * @throws TransportException When the file could not be downloaded
     */
    public function get($originUrl, $fileUrl, $additionalOptions = array(), $progress = true)
    {
        $this->bytesMax = 0;
        $this->originUrl = $originUrl;
        $this->fileUrl = $fileUrl;
        $this->progress = $progress;
        $this->lastProgress = null;

        $options = $this->getOptionsForUrl($originUrl, $additionalOptions);
        if (isset($options['github-token'])) {
            $fileUrl .= (false === strpos($fileUrl, '?') ? '?' : '&') . 'access_token='.$options['github-token'];
            unset($options['github-token']);
        }
        $ctx = StreamContextFactory::getContext($options, array('notification' => array($this, 'callbackGet')));

        if ($this->progress) {
            $this->io->write("    Downloading: <comment>connection...</comment>", false);
        }

        $errorMessage = '';
        $errorCode = 0;
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
        }
        if ($errorMessage && !ini_get('allow_url_fopen')) {
            $errorMessage = 'allow_url_fopen must be enabled in php.ini ('.$errorMessage.')';
        }
        restore_error_handler();
        if (isset($e)) {
            throw $e;
        }

        // fix for 5.4.0 https://bugs.php.net/bug.php?id=61336
        if (!empty($http_response_header[0]) && preg_match('{^HTTP/\S+ ([45]\d\d)}i', $http_response_header[0], $match)) {
            $result = false;
            $errorCode = $match[1];
        }

        // decode gzip
        if (false !== $result && extension_loaded('zlib') && substr($fileUrl, 0, 4) === 'http') {
            $decode = false;
            foreach ($http_response_header as $header) {
                if (preg_match('{^content-encoding: *gzip *$}i', $header)) {
                    $decode = true;
                    continue;
                } elseif (preg_match('{^HTTP/}i', $header)) {
                    $decode = false;
                }
            }

            if ($decode) {
                if (version_compare(PHP_VERSION, '5.4.0', '>=')) {
                    $result = zlib_decode($result);
                } else {
                    // work around issue with gzuncompress & co that do not work with all gzip checksums
                    $result = file_get_contents('compress.zlib://data:application/octet-stream;base64,'.base64_encode($result));
                }
            }
        }

        if ($this->progress) {
            $this->io->overwrite("    Downloading: <comment>100%</comment>");
        }

        if (false === $result) {
            $e = new TransportException('The "'.$fileUrl.'" file could not be downloaded: '.$errorMessage, $errorCode);
            if (!empty($http_response_header[0])) {
                $e->setHeaders($http_response_header);
            }

            throw $e;
        }
        return $result;
    }

    /**
     * Get notification action.
     *
     * @param integer $notificationCode The notification code
     * @param integer $severity         The severity level
     * @param string  $message          The message
     * @param integer $messageCode      The message code
     * @param integer $bytesTransferred The loaded size
     * @param integer $bytesMax         The total size
     */
    protected function callbackGet($notificationCode, $severity, $message, $messageCode, $bytesTransferred, $bytesMax)
    {
        switch ($notificationCode) {
            case STREAM_NOTIFY_FAILURE:
                throw new TransportException('The "'.$this->fileUrl.'" file could not be downloaded ('.trim($message).')', $messageCode);
                break;

            case STREAM_NOTIFY_AUTH_REQUIRED:
                if (401 === $messageCode) {
                    if (!$this->io->isInteractive()) {
                        $message = "The '" . $this->fileUrl . "' URL required authentication.\nYou must be using the interactive console";

                        throw new TransportException($message, 401);
                    }

                    $this->io->overwrite('    Authentication required (<info>'.parse_url($this->fileUrl, PHP_URL_HOST).'</info>):');
                    $username = $this->io->ask('      Username: ');
                    $password = $this->io->askAndHideAnswer('      Password: ');
                    $this->io->setAuthentication($this->originUrl, $username, $password);

                    $this->get($this->originUrl, $this->fileUrl, $this->progress);
                }
                break;

            case STREAM_NOTIFY_AUTH_RESULT:
                if (403 === $messageCode) {
                    $message = "The '" . $this->fileUrl . "' URL could not be accessed: " . $message;

                    throw new TransportException($message, 403);
                }
                break;

            case STREAM_NOTIFY_FILE_SIZE_IS:
                if ($this->bytesMax < $bytesMax) {
                    $this->bytesMax = $bytesMax;
                }
                break;

            case STREAM_NOTIFY_PROGRESS:
                if ($this->bytesMax > 0 && $this->progress) {
                    $progression = 0;

                    if ($this->bytesMax > 0) {
                        $progression = round($bytesTransferred / $this->bytesMax * 100);
                    }

                    if ((0 === $progression % 5) && $progression !== $this->lastProgress) {
                        $this->lastProgress = $progression;
                        $this->io->overwrite("    Downloading: <comment>$progression%</comment>", false);
                    }
                }
                break;

            default:
                break;
        }
    }

    protected function getOptionsForUrl($originUrl, $additionalOptions)
    {
        $headers = array(
            sprintf(
                'User-Agent: Composer/%s (%s; %s; PHP %s.%s.%s)',
                Composer::VERSION === '@package_version@' ? 'source' : Composer::VERSION,
                php_uname('s'),
                php_uname('r'),
                PHP_MAJOR_VERSION,
                PHP_MINOR_VERSION,
                PHP_RELEASE_VERSION
            )
        );

        if (extension_loaded('zlib')) {
            $headers[] = 'Accept-Encoding: gzip';
        }

        $options = array_replace_recursive($this->options, $additionalOptions);

        if ($this->io->hasAuthentication($originUrl)) {
            $auth = $this->io->getAuthentication($originUrl);
            if ('github.com' === $originUrl && 'x-oauth-basic' === $auth['password']) {
                $options['github-token'] = $auth['username'];
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
}
