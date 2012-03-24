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
 */
class Svn
{
    /**
     * @var mixed
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
     * Cache credentials.
     * @var bool
     */
    protected $useCache = false;

    /**
     * @param string                   $url
     * @param \Composer\IO\IOInterface $io
     *
     * @return \Composer\Util\Svn
     */
    public function __construct($url, IOInterface $io)
    {
        $this->url = $url;
        $this->io  = $io;
    }

    /**
     * Repositories requests credentials, let's put them in.
     *
     * @return \Composer\Util\Svn
     */
    public function doAuthDance()
    {
        $this->io->write("The Subversion server ({$this->url}) requested credentials:");

        $this->hasAuth     = true;
        $this->credentials = new \stdClass();

        $this->credentials->username = $this->io->ask("Username: ");
        $this->credentials->password = $this->io->askAndHideAnswer("Password: ");

        $pleaseCache = $this->io->askConfirmation("Should Subversion cache these credentials? (yes/no) ", false);
        if ($pleaseCache) {
            $this->useCache = true;
        }
        return $this;
    }
    /**
     * Return the no-auth-cache switch.
     *
     * @return string
     */
    public function getAuthCache()
    {
        if (!$this->useCache) {
            return '--no-auth-cache ';
        }
        return '';
    }

    /**
     * A method to create the svn commands run.
     *
     * @param string $cmd  Usually 'svn ls' or something like that.
     * @param string $url  Repo URL.
     * @param string $path The path to run this against (e.g. a 'co' into)
     *
     * @return string
     */
    public function getCommand($cmd, $url, $path = '')
    {
        $cmd = sprintf('%s %s%s %s',
            $cmd,
            '--non-interactive ',
            $this->getCredentialString(),
            escapeshellarg($url)
        );
        if (!empty($path)) {
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
     * @uses   self::$useAuth
     */
    public function getCredentialString()
    {
        if ($this->hasAuth === null) {
            $this->hasAuth();
        }
        if (!$this->hasAuth) {
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
    public function getPassword()
    {
        if ($this->credentials === null) {
            throw new \LogicException("No auth detected.");
        }
        if (isset($this->credentials->password)) {
            return $this->credentials->password;
        }
        return ''; // could be empty
    }

    /**
     * Get the username for the svn command.
     *
     * @return string
     * @throws \LogicException
     */
    public function getUsername()
    {
        if ($this->credentials === null) {
            throw new \LogicException("No auth detected.");
        }
        return $this->credentials->username;
    }

    /**
     * Detect Svn Auth.
     *
     * @param string $url
     *
     * @return \stdClass
     */
    public function hasAuth()
    {
        if ($this->hasAuth !== null) {
            return $this->hasAuth;
        }

        $uri = parse_url($this->url);
        if (empty($uri['user'])) {
            $this->hasAuth = false;
            return $this->hasAuth;
        }

        $this->hasAuth     = true;
        $this->credentials = new \stdClass();

        $this->credentials->username = $uri['user'];

        if (!empty($uri['pass'])) {
            $this->credentials->password = $uri['pass'];
        }

        return $this->hasAuth;
    }
}