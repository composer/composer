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
 * @author Till Klampaeckel <till@php.net>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class Svn
{
    const MAX_QTY_AUTH_TRIES = 5;

    /**
     * @var array
     */
    protected $credentials;

    /**
     * @var bool
     */
    protected $hasAuth;

    /**
     * @var \Composer\IO\IOInterface
     */
    protected $io;

    /**
     * @var string
     */
    protected $url;

    /**
     * @var bool
     */
    protected $cacheCredentials = true;

    /**
     * @var ProcessExecutor
     */
    protected $process;

    /**
     * @var integer
     */
    protected $qtyAuthTries = 0;

    /**
     * @param string                   $url
     * @param \Composer\IO\IOInterface $io
     * @param ProcessExecutor          $process
     */
    public function __construct($url, IOInterface $io, ProcessExecutor $process = null)
    {
        $this->url = $url;
        $this->io  = $io;
        $this->process = $process ?: new ProcessExecutor;
    }

    /**
     * Execute an SVN command and try to fix up the process with credentials
     * if necessary.
     *
     * @param string $command SVN command to run
     * @param string $url     SVN url
     * @param string $cwd     Working directory
     * @param string $path    Target for a checkout
     * @param bool   $verbose Output all output to the user
     *
     * @return string
     *
     * @throws \RuntimeException
     */
    public function execute($command, $url, $cwd = null, $path = null, $verbose = false)
    {
        $svnCommand = $this->getCommand($command, $url, $path);
        $output = null;
        $io = $this->io;
        $handler = function ($type, $buffer) use (&$output, $io, $verbose) {
            if ($type !== 'out') {
                return;
            }
            if ('Redirecting to URL ' === substr($buffer, 0, 19)) {
                return;
            }
            $output .= $buffer;
            if ($verbose) {
                $io->write($buffer, false);
            }
        };
        $status = $this->process->execute($svnCommand, $handler, $cwd);
        if (0 === $status) {
            return $output;
        }

        if (empty($output)) {
            $output = $this->process->getErrorOutput();
        }

        // the error is not auth-related
        if (false === stripos($output, 'Could not authenticate to server:')
            && false === stripos($output, 'authorization failed')
            && false === stripos($output, 'svn: E170001:')
            && false === stripos($output, 'svn: E215004:')) {
            throw new \RuntimeException($output);
        }

        // no auth supported for non interactive calls
        if (!$this->io->isInteractive()) {
            throw new \RuntimeException(
                'can not ask for authentication in non interactive mode ('.$output.')'
            );
        }

        // try to authenticate if maximum quantity of tries not reached
        if ($this->qtyAuthTries++ < self::MAX_QTY_AUTH_TRIES || !$this->hasAuth()) {
            $this->doAuthDance();

            // restart the process
            return $this->execute($command, $url, $cwd, $path, $verbose);
        }

        throw new \RuntimeException(
            'wrong credentials provided ('.$output.')'
        );
    }

    /**
     * Repositories requests credentials, let's put them in.
     *
     * @return \Composer\Util\Svn
     */
    protected function doAuthDance()
    {
        $this->io->write("The Subversion server ({$this->url}) requested credentials:");

        $this->hasAuth = true;
        $this->credentials['username'] = $this->io->ask("Username: ");
        $this->credentials['password'] = $this->io->askAndHideAnswer("Password: ");

        $this->cacheCredentials = $this->io->askConfirmation("Should Subversion cache these credentials? (yes/no) ", true);

        return $this;
    }

    /**
     * A method to create the svn commands run.
     *
     * @param string $cmd  Usually 'svn ls' or something like that.
     * @param string $url  Repo URL.
     * @param string $path Target for a checkout
     *
     * @return string
     */
    protected function getCommand($cmd, $url, $path = null)
    {
        $cmd = sprintf('%s %s%s %s',
            $cmd,
            '--non-interactive ',
            $this->getCredentialString(),
            escapeshellarg($url)
        );

        if ($path) {
            $cmd .= ' ' . escapeshellarg($path);
        }

        return $cmd;
    }

    /**
     * Return the credential string for the svn command.
     *
     * Adds --no-auth-cache when credentials are present.
     *
     * @return string
     */
    protected function getCredentialString()
    {
        if (!$this->hasAuth()) {
            return '';
        }

        return sprintf(
            ' %s--username %s --password %s ',
            $this->getAuthCache(),
            escapeshellarg($this->getUsername()),
            escapeshellarg($this->getPassword())
        );
    }

    /**
     * Get the password for the svn command. Can be empty.
     *
     * @return string
     * @throws \LogicException
     */
    protected function getPassword()
    {
        if ($this->credentials === null) {
            throw new \LogicException("No svn auth detected.");
        }

        return isset($this->credentials['password']) ? $this->credentials['password'] : '';
    }

    /**
     * Get the username for the svn command.
     *
     * @return string
     * @throws \LogicException
     */
    protected function getUsername()
    {
        if ($this->credentials === null) {
            throw new \LogicException("No svn auth detected.");
        }

        return $this->credentials['username'];
    }

    /**
     * Detect Svn Auth.
     *
     * @return bool
     */
    protected function hasAuth()
    {
        if (null !== $this->hasAuth) {
            return $this->hasAuth;
        }

        $uri = parse_url($this->url);
        if (empty($uri['user'])) {
            return $this->hasAuth = false;
        }

        $this->credentials['username'] = $uri['user'];
        if (!empty($uri['pass'])) {
            $this->credentials['password'] = $uri['pass'];
        }

        return $this->hasAuth = true;
    }

    /**
     * Return the no-auth-cache switch.
     *
     * @return string
     */
    protected function getAuthCache()
    {
        return $this->cacheCredentials ? '' : '--no-auth-cache ';
    }
}
