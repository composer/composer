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
 */
class RemoteFilesystem
{
    private $io;
    private $firstCall;
    private $bytesMax;
    private $originUrl;
    private $fileUrl;
    private $fileName;
    private $result;
    private $progress;
    private $lastProgress;
    private $options;

    /**
     * Constructor.
     *
     * @param IOInterface $io The IO instance
     */
    public function __construct(IOInterface $io, $options = array())
    {
        $this->io = $io;
        $this->options = $options;
    }

    /**
     * Copy the remote file in local.
     *
     * @param string  $originUrl The origin URL
     * @param string  $fileUrl   The file URL
     * @param string  $fileName  the local filename
     * @param boolean $progress  Display the progression
     * @param array   $options   Additional context options
     *
     * @return bool true
     */
    public function copy($originUrl, $fileUrl, $fileName, $progress = true, $options = array())
    {
        $this->get($originUrl, $fileUrl, $options, $fileName, $progress);

        return $this->result;
    }

    /**
     * Get the content.
     *
     * @param string  $originUrl The origin URL
     * @param string  $fileUrl   The file URL
     * @param boolean $progress  Display the progression
     * @param array   $options   Additional context options
     *
     * @return string The content
     */
    public function getContents($originUrl, $fileUrl, $progress = true, $options = array())
    {
        $this->get($originUrl, $fileUrl, $options, null, $progress);

        return $this->result;
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
     * @throws TransportException When the file could not be downloaded
     */
    protected function get($originUrl, $fileUrl, $additionalOptions = array(), $fileName = null, $progress = true)
    {
        $this->bytesMax = 0;
        $this->result = null;
        $this->originUrl = $originUrl;
        $this->fileUrl = $fileUrl;
        $this->fileName = $fileName;
        $this->progress = $progress;
        $this->lastProgress = null;

        $options = $this->getOptionsForUrl($originUrl, $additionalOptions);
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

        // handle copy command if download was successful
        if (false !== $result && null !== $fileName) {
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
                throw new TransportException('The "'.$fileUrl.'" file could not be written to '.$fileName.': '.$errorMessage);
            }
        }

        // avoid overriding if content was loaded by a sub-call to get()
        if (null === $this->result) {
            $this->result = $result;
        }

        if (false === $this->result) {
            $e = new TransportException('The "'.$fileUrl.'" file could not be downloaded: '.$errorMessage, $errorCode);
            if (!empty($http_response_header[0])) {
                $e->setHeaders($http_response_header);
            }

            throw $e;
        }
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
                    $this->io->setAuthorization($this->originUrl, $username, $password);

                    $this->get($this->originUrl, $this->fileUrl, $this->fileName, $this->progress);
                }
                break;

            case STREAM_NOTIFY_AUTH_RESULT:
                if (403 === $messageCode) {
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
        $header = sprintf(
            "User-Agent: Composer/%s (%s; %s; PHP %s.%s.%s)\r\n",
            Composer::VERSION,
            php_uname('s'),
            php_uname('r'),
            PHP_MAJOR_VERSION,
            PHP_MINOR_VERSION,
            PHP_RELEASE_VERSION
        );
        if (extension_loaded('zlib')) {
            $header .= 'Accept-Encoding: gzip'."\r\n";
        }

        if ($this->io->hasAuthorization($originUrl)) {
            $auth = $this->io->getAuthorization($originUrl);
            $authStr = base64_encode($auth['username'] . ':' . $auth['password']);
            $header .= "Authorization: Basic $authStr\r\n";
        }

        $options = array_replace_recursive($this->options, $additionalOptions);

        if (isset($options['http']['header'])) {
            $options['http']['header'] = rtrim($options['http']['header'], "\r\n") . "\r\n" . $header;
        } else {
            $options['http']['header'] = $header;
        }

        return $options;
    }
}
