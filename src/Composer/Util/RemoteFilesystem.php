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
    protected $io;
    private $bytesMax;
    private $originUrl;
    private $fileUrl;
    private $fileName;

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
     * @param string $originUrl The origin URL
     * @param string $fileName  The local filename
     * @param string $fileUrl   The file URL
     *
     * @throws \RuntimeException When opensll extension is disabled
     */
    public function copy($originUrl, $fileName, $fileUrl)
    {
        $this->firstCall = true;
        $this->originUrl = $originUrl;
        $this->fileName = $fileName;
        $this->fileUrl = $fileUrl;
        $this->bytesMax = 0;

        // Handle system proxy
        $params = array('http' => array());

        if (isset($_SERVER['HTTP_PROXY'])) {
            // http(s):// is not supported in proxy
            $proxy = str_replace(array('http://', 'https://'), array('tcp://', 'ssl://'), $_SERVER['HTTP_PROXY']);

            if (0 === strpos($proxy, 'ssl:') && !extension_loaded('openssl')) {
                throw new \RuntimeException('You must enable the openssl extension to use a proxy over https');
            }

            $params['http'] = array(
                    'proxy'           => $proxy,
                    'request_fulluri' => true,
            );
        }

        if ($this->io->hasAuthorization($originUrl)) {
            $auth = $this->io->getAuthorization($originUrl);
            $authStr = base64_encode($auth['username'] . ':' . $auth['password']);
            $params['http'] = array_merge($params['http'], array('header' => "Authorization: Basic $authStr\r\n"));
        }

        $ctx = stream_context_create($params);
        stream_context_set_params($ctx, array("notification" => array($this, 'callbackGet')));

        $this->io->overwrite("    Downloading: <comment>connection...</comment>", false);
        @copy($fileUrl, $fileName, $ctx);
        $this->io->overwrite("    Downloading", false);
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
                $ps = $this->firstCall && 404 === $messageCode
                && null === $auth['username'];

                if (404 === $messageCode && !$this->firstCall) {
                    throw new \RuntimeException("The '" . $this->fileUrl . "' URL not found");
                }

                $this->firstCall = false;

                // get authorization informations
                if (401 === $messageCode || $ps) {
                    if (!$this->io->isInteractive()) {
                        $mess = "The '" . $this->fileUrl . "' URL not found";

                        if (401 === $code || $ps) {
                            $mess = "The '" . $this->fileUrl . "' URL required the authorization.\nYou must be used the interactive console";
                        }

                        throw new \RuntimeException($mess);
                    }

                    $this->io->overwrite('    Authorization required:');
                    $username = $this->io->ask('      Username: ');
                    $password = $this->io->askAndHideAnswer('      Password: ');
                    $this->io->setAuthorization($this->originUrl, $username, $password);

                    $this->copy($this->originUrl, $this->fileName, $this->fileUrl);
                }
                break;

            case STREAM_NOTIFY_FILE_SIZE_IS:
                if ($this->bytesMax < $bytesMax) {
                    $this->bytesMax = $bytesMax;
                }
                break;

            case STREAM_NOTIFY_PROGRESS:
                if ($this->bytesMax > 0) {
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
}