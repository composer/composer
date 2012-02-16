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

use Composer\IO\IOInterface;

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
    private $content;
    private $progess;

    /**
     * Constructor.
     *
     * @param IOInterface  $io  The IO instance
     */
    public function __construct(IOInterface $io)
    {
        $this->io = $io;
    }

    /**
     * Copy the remote file in local.
     *
     * @param string  $originUrl The orgin URL
     * @param string  $fileUrl   The file URL
     * @param string  $fileName  the local filename
     * @param boolean $progess   Display the progression
     */
    public function copy($originUrl, $fileUrl, $fileName, $progess = true)
    {
        $this->get($originUrl, $fileUrl, $fileName, $progess);
    }

    /**
     * Get the content.
     *
     * @param string  $originUrl The orgin URL
     * @param string  $fileUrl   The file URL
     * @param boolean $progess   Display the progression
     *
     * @return false|string The content
     */
    public function getContents($originUrl, $fileUrl, $progess = true)
    {
        $this->get($originUrl, $fileUrl, null, $progess);

        return $this->content;
    }

    /**
     * Get file content or copy action.
     *
     * @param string  $originUrl The orgin URL
     * @param string  $fileUrl   The file URL
     * @param string  $fileName  the local filename
     * @param boolean $progess   Display the progression
     *
     * @throws \RuntimeException When the openssl extension is disabled
     */
    protected function get($originUrl, $fileUrl, $fileName = null, $progess = true)
    {
        $this->firstCall = true;
        $this->bytesMax = 0;
        $this->content = null;
        $this->originUrl = $originUrl;
        $this->fileUrl = $fileUrl;
        $this->fileName = $fileName;
        $this->progress = $progess;

        // add authorization in context
        $options = array();
        if ($this->io->hasAuthorization($originUrl)) {
            $auth = $this->io->getAuthorization($originUrl);
            $authStr = base64_encode($auth['username'] . ':' . $auth['password']);
            $options['http']['header'] = "Authorization: Basic $authStr\r\n";

        } else if (null !== $this->io->getLastUsername()) {
            $authStr = base64_encode($this->io->getLastUsername() . ':' . $this->io->getLastPassword());
            $options['http'] = array('header' => "Authorization: Basic $authStr\r\n");
            $this->io->setAuthorization($originUrl, $this->io->getLastUsername(), $this->io->getLastPassword());
        }

        $ctx = StreamContextFactory::getContext($options, array('notification' => array($this, 'callbackGet')));

        if ($this->progress) {
            $this->io->overwrite("    Downloading: <comment>connection...</comment>", false);
        }

        if (null !== $fileName) {
            @copy($fileUrl, $fileName, $ctx);

        } else {
            $this->content = @file_get_contents($fileUrl, false, $ctx);
        }

        if ($this->progress) {
            $this->io->overwrite("    Downloading", false);
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
            case STREAM_NOTIFY_AUTH_REQUIRED:
            case STREAM_NOTIFY_FAILURE:
                // for private repository returning 404 error when the authorization is incorrect
                $auth = $this->io->getAuthorization($this->originUrl);
                $ps = $this->firstCall && 404 === $messageCode && null === $auth['username'];

                if (404 === $messageCode && !$this->firstCall) {
                    throw new \RuntimeException("The '" . $this->fileUrl . "' URL not found");
                }

                $this->firstCall = false;

                // get authorization informations
                if (401 === $messageCode || $ps) {
                    if (!$this->io->isInteractive()) {
                        $mess = "The '" . $this->fileUrl . "' URL was not found";

                        if (401 === $code || $ps) {
                            $mess = "The '" . $this->fileUrl . "' URL required the authorization.\nYou must be using the interactive console";
                        }

                        throw new \RuntimeException($mess);
                    }

                    $this->io->overwrite('    Authorization required (<info>' .$this->getHostname($this->fileUrl).'</info>):');
                    $username = $this->io->ask('      Username: ');
                    $password = $this->io->askAndHideAnswer('      Password: ');
                    $this->io->setAuthorization($this->originUrl, $username, $password);

                    $this->content = $this->get($this->originUrl, $this->fileUrl, $this->fileName, $this->progress);
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

                    if (0 === $progression % 5) {
                        $this->io->overwrite("    Downloading: <comment>$progression%</comment>", false);
                    }
                }
                break;

            default:
                break;
        }
    }

    /**
     * Get the hostname.
     *
     * @param string $url The file URL
     *
     * @return string The hostname
     */
    protected function getHostname($url)
    {
        $host = substr($url, strpos($url, '://') + 3);
        $host = substr($host, 0, strpos($host, '/'));

        return $host;
    }
}