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
    const FAILURE = 1;
    const AUTH_REQUIRED = 2;
    const AUTH_RESULT = 3;
    const FILE_SIZE_IS = 4;
    const PROGRESS = 5;

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
    private $driver;

    /**
     * Constructor.
     *
     * @param IOInterface $io      The IO instance
     * @param array       $options The options
     */
    public function __construct(IOInterface $io, $options = array(), $driver = 'curl')
    {
        $this->io = $io;
        $this->options = $options;
           switch ($driver) {
               case 'curl':
                   if (extension_loaded('curl')) {
                       $this->driver = new CurlDriver($io, array($this, 'callbackGet'), $options);
                       break;
                   }
               default:
                   $this->driver = new FileDriver($io, array($this, 'callbackGet'), $options);
           }
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
        if (isset($options['github-token'])) {
            $fileUrl .= (false === strpos($fileUrl, '?') ? '?' : '&') . 'access_token='.$options['github-token'];
            unset($options['github-token']);
        }
        $ctx = StreamContextFactory::getContext($options, array('notification' => array($this, 'callbackGet')));

        if ($this->progress) {
            $this->io->write("    Downloading: <comment>connection...</comment>", false);
        }

		//fallback when using curl driver to user file driver for local files
		if ((is_file($fileUrl)) && ($this->driver instanceof CurlDriver)) {
			$filedriver = new FileDriver($this->io, array($this, 'callbackGet'), $this->options);
			$result = $filedriver->get($fileUrl, $originUrl, $additionalOptions);
		} else {
			$result = $this->driver->get($fileUrl, $originUrl, $additionalOptions);
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
            $e->setHeaders($this->driver->response_headers());

            throw $e;
        }
    }

    /**
     * Get notification action.
     *
     * @param integer $notificationCode The notification code
     * @param string  $message          The message
     * @param integer $messageCode      The message code
     * @param integer $bytesTransferred The loaded size
     * @param integer $bytesMax         The total size
     */
    protected function callbackGet($notificationCode, $message, $messageCode, $bytesTransferred, $bytesMax)
    {
        switch ($notificationCode) {
            case self::FAILURE:
                throw new TransportException('The "'.$this->fileUrl.'" file could not be downloaded ('.trim($message).')', $messageCode);
                break;

            case self::AUTH_REQUIRED:
                if (401 === $messageCode) {
                    if (!$this->io->isInteractive()) {
                        $message = "The '" . $this->fileUrl . "' URL required authentication.\nYou must be using the interactive console";

                        throw new TransportException($message, 401);
                    }

                    $this->io->overwrite('    Authentication required (<info>'.parse_url($this->fileUrl, PHP_URL_HOST).'</info>):');
                    $username = $this->io->ask('      Username: ');
                    $password = $this->io->askAndHideAnswer('      Password: ');
                    $this->io->setAuthentication($this->originUrl, $username, $password);

                    $this->get($this->originUrl, $this->fileUrl, $this->fileName, $this->progress);
                }
                break;

            case self::AUTH_RESULT:
                if (403 === $messageCode) {
                    $message = "The '" . $this->fileUrl . "' URL could not be accessed: " . $message;

                    throw new TransportException($message, 403);
                }
                break;

            case self::FILE_SIZE_IS:
                if ($this->bytesMax < $bytesMax) {
                    $this->bytesMax = $bytesMax;
                }
                break;

            case self::PROGRESS:
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
