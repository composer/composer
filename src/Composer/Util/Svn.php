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

use Composer\Config;
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
     * @var int
     */
    protected $qtyAuthTries = 0;

    /**
     * @var \Composer\Config
     */
    protected $config;

    /**
     * @param string                   $url
     * @param \Composer\IO\IOInterface $io
     * @param Config                   $config
     * @param ProcessExecutor          $process
     */
    public function __construct($url, IOInterface $io, Config $config, ProcessExecutor $process = null)
    {
        $this->url = $url;
        $this->io  = $io;
        $this->config = $config;
        $this->process = $process ?: new ProcessExecutor;
    }

    public static function cleanEnv()
    {
        // clean up env for OSX, see https://github.com/composer/composer/issues/2146#issuecomment-35478940
        putenv("DYLD_LIBRARY_PATH");
        unset($_SERVER['DYLD_LIBRARY_PATH']);
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
     * @throws \RuntimeException
     * @return string
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
                $io->writeError($buffer, false);
            }
        };
        $status = $this->process->execute($svnCommand, $handler, $cwd);
        if (0 === $status) {
            return $output;
        }

        $errorOutput = $this->process->getErrorOutput();
        $fullOutput = implode("\n", array($output, $errorOutput));

        // the error is not auth-related
        if (false === stripos($fullOutput, 'Could not authenticate to server:')
            && false === stripos($fullOutput, 'authorization failed')
            && false === stripos($fullOutput, 'svn: E170001:')
            && false === stripos($fullOutput, 'svn: E215004:')) {
            throw new \RuntimeException($fullOutput);
        }

        if (!$this->hasAuth()) {
            $this->doAuthDance();
        }

        // try to authenticate if maximum quantity of tries not reached
        if ($this->qtyAuthTries++ < self::MAX_QTY_AUTH_TRIES) {
            // restart the process
            return $this->execute($command, $url, $cwd, $path, $verbose);
        }

        throw new \RuntimeException(
            'wrong credentials provided ('.$fullOutput.')'
        );
    }

    /**
     * @param bool $cacheCredentials
     */
    public function setCacheCredentials($cacheCredentials)
    {
        $this->cacheCredentials = $cacheCredentials;
    }

    /**
     * Repositories requests credentials, let's put them in.
     *
     * @throws \RuntimeException
     * @return \Composer\Util\Svn
     */
    protected function doAuthDance()
    {
        // cannot ask for credentials in non interactive mode
        if (!$this->io->isInteractive()) {
            throw new \RuntimeException(
                'can not ask for authentication in non interactive mode'
            );
        }

        $this->io->writeError("The Subversion server ({$this->url}) requested credentials:");

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
            ProcessExecutor::escape($url)
        );

        if ($path) {
            $cmd .= ' ' . ProcessExecutor::escape($path);
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
            ProcessExecutor::escape($this->getUsername()),
            ProcessExecutor::escape($this->getPassword())
        );
    }

    /**
     * Get the password for the svn command. Can be empty.
     *
     * @throws \LogicException
     * @return string
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
     * @throws \LogicException
     * @return string
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

        if (false === $this->createAuthFromConfig()) {
            $this->createAuthFromUrl();
        }

        return $this->hasAuth;
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

    /**
     * Create the auth params from the configuration file.
     *
     * @return bool
     */
    private function createAuthFromConfig()
    {
        if (!$this->config->has('http-basic')) {
            return $this->hasAuth = false;
        }

        $authConfig = $this->config->get('http-basic');

        $host = parse_url($this->url, PHP_URL_HOST);
        if (isset($authConfig[$host])) {
            $this->credentials['username'] = $authConfig[$host]['username'];
            $this->credentials['password'] = $authConfig[$host]['password'];

            return $this->hasAuth = true;
        }

        return $this->hasAuth = false;
    }

    /**
     * Create the auth params from the url
     *
     * @return bool
     */
    private function createAuthFromUrl()
    {
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
}
