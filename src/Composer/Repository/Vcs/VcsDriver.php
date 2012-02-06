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

namespace Composer\Repository\Vcs;

use Composer\IO\IOInterface;
use Composer\Util\ProcessExecutor;

/**
 * A driver implementation for driver with authorization interaction.
 *
 * @author François Pluchino <francois.pluchino@opendisplay.com>
 */
abstract class VcsDriver
{
    protected $url;
    protected $io;
    protected $process;
    private $firstCall;
    private $contentUrl;
    private $content;

    /**
     * Constructor.
     *
     * @param string      $url The URL
     * @param IOInterface $io  The IO instance
     * @param ProcessExecutor $process  Process instance, injectable for mocking
     */
    public function __construct($url, IOInterface $io, ProcessExecutor $process = null)
    {
        $this->url = $url;
        $this->io = $io;
        $this->process = $process ?: new ProcessExecutor;
        $this->firstCall = true;
    }

    /**
     * Get the https or http protocol depending on SSL support.
     *
     * Call this only if you know that the server supports both.
     *
     * @return string The correct type of protocol
     */
    protected function getScheme()
    {
        if (extension_loaded('openssl')) {
            return 'https';
        }
        return 'http';
    }

    /**
     * Get the remote content.
     *
     * @param string $url The URL of content
     *
     * @return mixed The result
     */
    protected function getContents($url)
    {
        $this->contentUrl = $url;
        $auth = $this->io->getAuthorization($this->url);
        $params = array();

        // add authorization to curl options
        if ($this->io->hasAuthorization($this->url)) {
            $authStr = base64_encode($auth['username'] . ':' . $auth['password']);
            $params['http'] = array('header' => "Authorization: Basic $authStr\r\n");
        } else if (null !== $this->io->getLastUsername()) {
            $authStr = base64_encode($this->io->getLastUsername() . ':' . $this->io->getLastPassword());
            $params['http'] = array('header' => "Authorization: Basic $authStr\r\n");
            $this->io->setAuthorization($this->url, $this->io->getLastUsername(), $this->io->getLastPassword());
        }

        $ctx = stream_context_create($params);
        stream_context_set_params($ctx, array("notification" => array($this, 'callbackGet')));

        $content = @file_get_contents($url, false, $ctx);

        // content get after authorization
        if (false === $content) {
            $content = $this->content;
            $this->content = null;
            $this->contentUrl = null;
        }

        return $content;
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
                $auth = $this->io->getAuthorization($this->url);
                $ps = $this->firstCall && 404 === $messageCode
                        && null === $this->io->getLastUsername()
                        && null === $auth['username'];

                if (404 === $messageCode && !$this->firstCall) {
                    throw new \RuntimeException("The '" . $this->contentUrl . "' URL not found");
                }

                $this->firstCall = false;

                // get authorization informations
                if (401 === $messageCode || $ps) {
                    if (!$this->io->isInteractive()) {
                        $mess = "The '" . $this->contentUrl . "' URL not found";

                        if (401 === $code || $ps) {
                            $mess = "The '" . $this->contentUrl . "' URL required the authorization.\nYou must be used the interactive console";
                        }

                        throw new \RuntimeException($mess);
                    }

                    $this->io->write("Authorization for <info>" . $this->contentUrl . "</info>:");
                    $username = $this->io->ask('    Username: ');
                    $password = $this->io->askAndHideAnswer('    Password: ');
                    $this->io->setAuthorization($this->url, $username, $password);

                    $this->content = $this->getContents($this->contentUrl);
                }
                break;

            default:
                break;
        }
    }
}
