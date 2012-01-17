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

/**
 * A driver implementation for driver with authentification interaction.
 *
 * @author François Pluchino <francois.pluchino@opendisplay.com>
 */
abstract class VcsDriver
{
    protected $url;
    protected $io;
    private $firstCall;
    private $contentUrl;
    private $content;

    /**
     * Constructor.
     *
     * @param string      $url The URL
     * @param IOInterface $io  The IO instance
     */
    public function __construct($url, IOInterface $io)
    {
        $this->url = $url;
        $this->io = $io;
        $this->firstCall = true;
    }

    /**
     * Get the https or http protocol.
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
        $auth = $this->io->getAuthentification($this->url);
        $params = array();

        // add authorization to curl options
        if ($this->io->hasAuthentification($this->url)) {
            $authStr = base64_encode($auth['username'] . ':' . $auth['password']);
            $params['http'] = array('header' => "Authorization: Basic $authStr\r\n");

        } else if (null !== $this->io->getLastUsername()) {
            $authStr = base64_encode($this->io->getLastUsername() . ':' . $this->io->getLastPassword());
            $params['http'] = array('header' => "Authorization: Basic $authStr\r\n");
        }

        $ctx = stream_context_create($params);
        stream_context_set_params($ctx, array("notification" => array($this, 'callbackGet')));

        $content = @file_get_contents($url, false, $ctx);

        // content get after authentification
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
                // for private repository returning 404 error when the authentification is incorrect
                $auth = $this->io->getAuthentification($this->url);
                $ps = $this->firstCall && 404 === $messageCode
                        && null === $this->io->getLastUsername()
                        && null === $auth['username'];

                if (404 === $messageCode && !$this->firstCall) {
                    throw new \LogicException("The '" . $this->contentUrl . "' URL not found");
                }

                if ($this->firstCall) {
                    $this->firstCall = false;
                }

                // get authentification informations
                if (401 === $messageCode || $ps) {
                    if (!$this->io->isInteractive()) {
                        $mess = "The '" . $this->contentUrl . "' URL not found";

                        if (401 === $code || $ps) {
                            $mess = "The '" . $this->contentUrl . "' URL required the authentification.\nYou must be used the interactive console";
                        }

                        throw new \LogicException($mess);
                    }

                    $this->io->writeln("Authorization for <info>" . $this->contentUrl . "</info>:");
                    $username = $this->io->ask('    Username: ');
                    $password = $this->io->askAndHideAnswer('    Password: ');
                    $this->io->setAuthentification($this->url, $username, $password);

                    $this->content = $this->getContents($this->contentUrl);
                }
                break;

            default:
                break;
        }
    }
}
