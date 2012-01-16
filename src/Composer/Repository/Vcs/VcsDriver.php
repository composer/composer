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
        $auth = $this->io->getAuthentification($this->url);

        // curl options
        $defaults = array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_BINARYTRANSFER => true,
            CURLOPT_BUFFERSIZE => 64000,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_NOPROGRESS => true,
            CURLOPT_URL => $url,
            CURLOPT_HTTPGET => true,
            CURLOPT_SSL_VERIFYPEER => false
        );

        // add authorization to curl options
        if ($this->io->hasAuthentification($this->url)) {
            $defaults[CURLOPT_USERPWD] = $auth['username'] . ':' . $auth['password'];

        } else if (null !== $this->io->getLastUsername()) {
            $defaults[CURLOPT_USERPWD] = $this->io->getLastUsername() . ':' . $this->io->getLastPassword();
        }

        // init curl
        $ch = curl_init();
        curl_setopt_array($ch, $defaults);

        // run curl
        $curl_result = curl_exec($ch);
        $curl_info = curl_getinfo($ch);
        $curl_errorCode = curl_errno($ch);
        $curl_error = curl_error($ch);
        $code = $curl_info['http_code'];
        $code = null ? 0 : $code;

        //close streams
        curl_close($ch);

        // for private repository returning 404 error when the authentification is incorrect
        $ps = $this->firstCall && 404 === $code && null === $this->io->getLastUsername() && null === $auth['username'];

        if ($this->firstCall) {
            $this->firstCall = false;
        }

        // auth required
        if (401 === $code || $ps) {
            if (!$this->io->isInteractive()) {
                $mess = "The '$url' URL not found";

                if (401 === $code || $ps) {
                    $mess = "The '$url' URL required the authentification.\nYou must be used the interactive console";
                }

                throw new \LogicException($mess);
            }

            $this->io->writeln("Authorization required for <info>" . $this->owner.'/' . $this->repository . "</info>:");
            $username = $this->io->ask('    Username: ');
            $password = $this->io->askAndHideAnswer('    Password: ');
            $this->io->setAuthentification($this->url, $username, $password);

            return $this->getContents($url);

        } else if (404 === $code) {
            throw new \LogicException("The '$url' URL not found");
        }

        return $curl_result;
    }
}
