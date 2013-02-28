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
class CurlDriver
{
    private $curl;
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
        $this->curl = curl_init();
        if ($this->curl === false)
            throw new TransportException('Error initializing cURL object');
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
        $this->originUrl = $originUrl;
        $this->fileUrl = $fileUrl;
        $this->progress = $progress;
        $this->lastProgress = null;

        if (is_file($fileUrl)) {
            $sd = new StreamDriver($this->io, $this->options);
            return $sd->get($originUrl, $fileUrl, $additionalOptions, $progress);
        }
        $options = $this->getOptionsForUrl($originUrl, $additionalOptions);
        if (isset($options['github-token'])) {
            $fileUrl .= (false === strpos($fileUrl, '?') ? '?' : '&') . 'access_token='.$options['github-token'];
            unset($options['github-token']);
        }
        $options[CURLOPT_URL] = $fileUrl;
        curl_setopt_array($this->curl, $options);
        $result = curl_exec($this->curl);
        if ($result === false) {
            throw new TransportException('The "'.$this->fileUrl.'" file could not be downloaded ('.trim(curl_error($this->curl)).')', curl_errno($this->curl));
        }
        return $result;
    }

    protected function headerCallback($curl, $header)
    {
        if (preg_match('/Content-Length: ([0-9]+)/', $header, $match)) {
            $this->bytesMax = $match[1];
        }
        return strlen($header);
    }

    protected function passwdCallback()
    {
        if (!$this->io->isInteractive()) {
            $message = "The '" . $this->fileUrl . "' URL required authentication.\nYou must be using the interactive console";

            throw new TransportException($message, 401);
        }

        $this->io->overwrite('    Authentication required (<info>'.parse_url($this->fileUrl, PHP_URL_HOST).'</info>):');
        $username = $this->io->ask('      Username: ');
        $password = $this->io->askAndHideAnswer('      Password: ');
        $this->io->setAuthentication($this->originUrl, $username, $password);
        return $password;
    }

    protected function progressCallback($downloadTotal, $bytesTransferred, $uploadTotal, $bytesUploaded)
    {
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
    }

    protected function readCallback($curl, $content)
    {
        return strlen($content);
    }

    protected function getOptionsForUrl($originUrl, $additionalOptions)
    {
        $options = array(
            CURLOPT_AUTOREFERER => true,
            CURLOPT_FAILONERROR => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_BUFFERSIZE => 64000,
            CURLOPT_ENCODING => '',
            CURLOPT_USERAGENT => sprintf(
                'User-Agent: Composer/%s (%s; %s; PHP %s.%s.%s)',
                Composer::VERSION === '@package_version@' ? 'source' : Composer::VERSION,
                php_uname('s'),
                php_uname('r'),
                PHP_MAJOR_VERSION,
                PHP_MINOR_VERSION,
                PHP_RELEASE_VERSION
            ),
            CURLOPT_NOPROGRESS => false,
            CURLOPT_HEADERFUNCTION => array($this, 'headerCallback'),
            //CURLOPT_PASSWDFUNCTION => array($this, 'passwdCallback'), //not implemented in php-curl?
            //CURLOPT_READFUNCTION => array($this, 'readCallback'), //not really using this
            CURLOPT_PROGRESSFUNCTION => array($this, 'progressCallback')
        );
        if ($this->io->hasAuthentication($originUrl)) {
            $auth = $this->io->getAuthentication($originUrl);
            if ('github.com' === $originUrl && 'x-oauth-basic' === $auth['password']) {
                $options['github-token'] = $auth['username'];
            }
            $options[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
            $options[CURLOPT_USERPWD] = $auth['username'] . ':' . $auth['password'];
        }
        return $options;
    }
}
