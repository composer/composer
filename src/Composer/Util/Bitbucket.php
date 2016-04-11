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

use Composer\Factory;
use Composer\IO\IOInterface;
use Composer\Config;
use Composer\Downloader\TransportException;

/**
 * @author Paul Wenke <wenke.paul@gmail.com>
 */
class Bitbucket
{
    private $io;
    private $config;
    private $process;
    private $remoteFilesystem;
    private $token = array();

    /**
     * Constructor.
     *
     * @param IOInterface      $io               The IO instance
     * @param Config           $config           The composer configuration
     * @param ProcessExecutor  $process          Process instance, injectable for mocking
     * @param RemoteFilesystem $remoteFilesystem Remote Filesystem, injectable for mocking
     */
    public function __construct(IOInterface $io, Config $config, ProcessExecutor $process = null, RemoteFilesystem $remoteFilesystem = null)
    {
        $this->io = $io;
        $this->config = $config;
        $this->process = $process ?: new ProcessExecutor;
        $this->remoteFilesystem = $remoteFilesystem ?: Factory::createRemoteFilesystem($this->io, $config);
    }

    /**
     * @return array
     */
    public function getToken()
    {
        return $this->token;
    }

    /**
     * Attempts to authorize a Bitbucket domain via OAuth
     *
     * @param  string $originUrl The host this Bitbucket instance is located at
     * @return bool   true on success
     */
    public function authorizeOAuth($originUrl)
    {
        if ($originUrl !== 'bitbucket.org') {
            return false;
        }

        // if available use token from git config
        if (0 === $this->process->execute('git config bitbucket.accesstoken', $output)) {
            $this->io->setAuthentication($originUrl, 'x-token-auth', trim($output));

            return true;
        }

        return false;
    }

    /**
     * @param  string $originUrl
     * @return bool
     */
    private function requestAccessToken($originUrl)
    {
        try {
            $apiUrl = 'https://bitbucket.org/site/oauth2/access_token';

            $json = $this->remoteFilesystem->getContents($originUrl, $apiUrl, false, array(
                'retry-auth-failure' => false,
                'http' => array(
                    'method' => 'POST',
                    'content' => 'grant_type=client_credentials',
                ),
            ));

            $this->token = json_decode($json, true);
        } catch (TransportException $e) {
            if (in_array($e->getCode(), array(403, 401))) {
                $this->io->writeError('<error>Invalid consumer provided.</error>');
                $this->io->writeError('You can also add it manually later by using "composer config bitbucket-oauth.bitbucket.org <consumer-key> <consumer-secret>"');

                return false;
            }

            throw $e;
        }
    }

    /**
     * Authorizes a Bitbucket domain interactively via OAuth
     *
     * @param  string                        $originUrl The host this Bitbucket instance is located at
     * @param  string                        $message   The reason this authorization is required
     * @throws \RuntimeException
     * @throws TransportException|\Exception
     * @return bool                          true on success
     */
    public function authorizeOAuthInteractively($originUrl, $message = null)
    {
        if ($message) {
            $this->io->writeError($message);
        }

        $url = 'https://confluence.atlassian.com/bitbucket/oauth-on-bitbucket-cloud-238027431.html';
        $this->io->writeError(sprintf('Follow the instructions on %s', $url));
        $this->io->writeError(sprintf('to create a consumer. It will be stored in "%s" for future use by Composer.', $this->config->getAuthConfigSource()->getName()));

        $consumerKey = trim($this->io->askAndHideAnswer('Consumer Key (hidden): '));

        if (!$consumerKey) {
            $this->io->writeError('<warning>No consumer key given, aborting.</warning>');
            $this->io->writeError('You can also add it manually later by using "composer config bitbucket-oauth.bitbucket.org <consumer-key> <consumer-secret>"');

            return false;
        }

        $consumerSecret = trim($this->io->askAndHideAnswer('Consumer Secret (hidden): '));

        if (!$consumerSecret) {
            $this->io->writeError('<warning>No consumer secret given, aborting.</warning>');
            $this->io->writeError('You can also add it manually later by using "composer config bitbucket-oauth.bitbucket.org <consumer-key> <consumer-secret>"');

            return false;
        }

        $this->io->setAuthentication($originUrl, $consumerKey, $consumerSecret);

        $this->requestAccessToken($originUrl);

        // store value in user config
        $this->config->getConfigSource()->removeConfigSetting('bitbucket-oauth.'.$originUrl);

        $consumer = array(
            "consumer-key" => $consumerKey,
            "consumer-secret" => $consumerSecret,
        );
        $this->config->getAuthConfigSource()->addConfigSetting('bitbucket-oauth.'.$originUrl, $consumer);

        $this->io->writeError('<info>Consumer stored successfully.</info>');

        return true;
    }

    /**
     * Retrieves an access token from Bitbucket.
     *
     * @param  string $originUrl
     * @param  string $consumerKey
     * @param  string $consumerSecret
     * @return array
     */
    public function requestToken($originUrl, $consumerKey, $consumerSecret)
    {
        if (!empty($this->token)) {
            return $this->token;
        }

        $this->io->setAuthentication($originUrl, $consumerKey, $consumerSecret);
        $this->requestAccessToken($originUrl);

        return $this->token;
    }
}
