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
        if (is_file($fileUrl)) {
            $fd = new FileDriver($this->io, $this->options);
            return $fd->get($originUrl, $fileUrl, $additionalOptions, $progress);
        }
        $options = $this->getOptionsForUrl($originUrl, $additionalOptions);
        if (isset($options['github-token'])) {
            $fileUrl .= (false === strpos($fileUrl, '?') ? '?' : '&') . 'access_token='.$options['github-token'];
            unset($options['github-token']);
        }
        $options[CURLOPT_URL] = $fileUrl;
        echo "{$fileUrl}\n";
        curl_setopt_array($this->curl, $options);
        $result = curl_exec($this->curl);
        if ($result === false) {
        	throw new TransportException(curl_error($this->curl), curl_errno($this->curl));
        }
        return $result;
    }

    protected function headerCallback()
    {
    }

    protected function passwdCallback()
    {
    }

    protected function progressCallback($dtotal, $dsize, $utotal, $usize)
    {

    }

    protected function readCallback()
    {
    }

    protected function writeCallback()
    {
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
            CURLOPT_PROGRESSFUNCTION => array($this, 'progressCallback')
        );
        if ($this->io->hasAuthentication($originUrl)) {
            $auth = $this->io->getAuthentication($originUrl);
            if ('github.com' === $originUrl && 'x-oauth-basic' === $auth['password']) {
            	echo "github!\n";
                $options['github-token'] = $auth['username'];
            }
            $options[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
            $options[CURLOPT_USERPWD] = $auth['username'] . ':' . $auth['password'];
        }
        return $options;
    }
}
