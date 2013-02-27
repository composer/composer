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
 * @author Flávio Heleno <flaviohbatista@gmail.com>
 */
class FileDriver
{
    private $io;
	private $callback;
    private $options;
	private $response_headers;

	public function __construct(IOInterface $io, $callback, $options = array())
	{
		$this->io = $io;
		$this->callback = $callback;
		$this->options = $options;
	}

    public function response_headers()
    {
    	return $this->response_headers;
    }

    public function get($fileUrl, $originUrl, $additionalOptions)
    {
        $options = $this->getOptionsForUrl($originUrl, $additionalOptions);
        $ctx = StreamContextFactory::getContext($options, array('notification' => array($this, 'callback')));

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

        if (!empty($http_response_header[0])) {
            $this->response_headers = $http_response_header;
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
        return $result;
    }

    /**
     * Notification action.
     *
     * @param integer $notificationCode The notification code
     * @param integer $severity         The severity level
     * @param string  $message          The message
     * @param integer $messageCode      The message code
     * @param integer $bytesTransferred The loaded size
     * @param integer $bytesMax         The total size
     */
    protected function callback($notificationCode, $severity, $message, $messageCode, $bytesTransferred, $bytesMax)
    {
        switch ($notificationCode) {
            case STREAM_NOTIFY_FAILURE:
            	call_user_func($this->callback, RemoteFilesystem::FAILURE, $message, $messageCode, $bytesTransferred, $bytesMax);
                break;

            case STREAM_NOTIFY_AUTH_REQUIRED:
            	call_user_func($this->callback, RemoteFilesystem::AUTH_REQUIRED, $message, $messageCode, $bytesTransferred, $bytesMax);
                break;

            case STREAM_NOTIFY_AUTH_RESULT:
            	call_user_func($this->callback, RemoteFilesystem::AUTH_RESULT, $message, $messageCode, $bytesTransferred, $bytesMax);
                break;

            case STREAM_NOTIFY_FILE_SIZE_IS:
            	call_user_func($this->callback, RemoteFilesystem::FILE_SIZE_IS, $message, $messageCode, $bytesTransferred, $bytesMax);
                break;

            case STREAM_NOTIFY_PROGRESS:
            	call_user_func($this->callback, RemoteFilesystem::PROGRESS, $message, $messageCode, $bytesTransferred, $bytesMax);
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

        if ($this->io->hasAuthentication($originUrl)) {
            $auth = $this->io->getAuthentication($originUrl);
            $authStr = base64_encode($auth['username'] . ':' . $auth['password']);
            $headers[] = 'Authorization: Basic '.$authStr;
        }

        $options = array_replace_recursive($this->options, $additionalOptions);

        if (isset($options['http']['header']) && !is_array($options['http']['header'])) {
            $options['http']['header'] = explode("\r\n", trim($options['http']['header'], "\r\n"));
        }
        foreach ($headers as $header) {
            $options['http']['header'][] = $header;
        }

        return $options;
    }

}
