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

namespace Composer\Util\Transfer;

use Composer\Config;
use Composer\IO\IOInterface;
use Composer\Util\GitHub;
use Composer\Downloader\TransportException;

/**
 * Base class for transfer drivers.
 * @author François Pluchino <francois.pluchino@opendisplay.com>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 * @author Nils Adermann <naderman@naderman.de>
 * @author Alexander Goryachev <mail@a-goryachev.ru>
 */
abstract class BaseDriver implements DriverInterface
{

    protected $io;
    protected $config;
    protected $options;
    protected $rfs;
    protected $retry;
    protected $bytesMax;
    protected $originUrl;
    protected $fileUrl;
    protected $fileName;
    protected $progress;
    protected $lastProgress;
    protected $retryAuthFailure;
    protected $lastHeaders;
    protected $storeAuth;

    public function __construct(IOInterface $io, Config $config = null, array $options = array(), $rfs)
    {
        $this->io = $io;
        $this->config = $config;
        $this->options = $options;
        $this->rfs = $rfs;
    }

    /**
     * 
     * @param integer $httpStatus HTTP status code, received from host
     * @param string $reason HTTP status message
     * @return null
     * @throws TransportException when can't authenticate
     */
    protected function promptAuthAndRetry($httpStatus, $reason = null)
    {
        if ($this->config && in_array($this->originUrl, $this->config->get('github-domains'), true)) {
            $message = "\n" . 'Could not fetch ' . $this->fileUrl . ', enter your GitHub credentials ' . ($httpStatus === 404 ? 'to access private repos' : 'to go over the API rate limit');
            $gitHubUtil = new GitHub($this->io, $this->config, null, $this->rfs);
            if (!$gitHubUtil->authorizeOAuth($this->originUrl) && (!$this->io->isInteractive() || !$gitHubUtil->authorizeOAuthInteractively($this->originUrl, $message))
            ) {
                throw new TransportException('Could not authenticate against ' . $this->originUrl, 401);
            }
        } else {
            // 404s are only handled for github
            if ($httpStatus === 404) {
                return;
            }

            // fail if the console is not interactive
            if (!$this->io->isInteractive()) {
                if ($httpStatus === 401) {
                    $message = "The '" . $this->fileUrl . "' URL required authentication.\nYou must be using the interactive console to authenticate";
                }
                if ($httpStatus === 403) {
                    $message = "The '" . $this->fileUrl . "' URL could not be accessed: " . $reason;
                }

                throw new TransportException($message, $httpStatus);
            }
            // fail if we already have auth
            if ($this->io->hasAuthentication($this->originUrl)) {
                throw new TransportException("Invalid credentials for '" . $this->fileUrl . "', aborting.", $httpStatus);
            }

            $this->io->overwrite('    Authentication required (<info>' . parse_url($this->fileUrl, PHP_URL_HOST) . '</info>):');
            $username = $this->io->ask('      Username: ');
            $password = $this->io->askAndHideAnswer('      Password: ');
            $this->io->setAuthentication($this->originUrl, $username, $password);
            $this->storeAuth = $this->config->get('store-auths');
        }

        $this->retry = true;
        throw new TransportException('RETRY');
    }

    public function getOptions()
    {
        return $this->options;
    }

    public function getLastHeaders()
    {
        return $this->lastHeaders;
    }

}
