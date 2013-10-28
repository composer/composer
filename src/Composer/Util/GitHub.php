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
use Composer\Config;
use Composer\Downloader\TransportException;
use Composer\Json\JsonFile;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class GitHub
{
    protected $io;
    protected $config;
    protected $process;
    protected $remoteFilesystem;

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
        $this->remoteFilesystem = $remoteFilesystem ?: new RemoteFilesystem($io);
    }

    /**
     * Attempts to authorize a GitHub domain via OAuth
     *
     * @param  string $originUrl The host this GitHub instance is located at
     * @return bool   true on success
     */
    public function authorizeOAuth($originUrl)
    {
        if (!in_array($originUrl, $this->config->get('github-domains'))) {
            return false;
        }

        // if available use token from git config
        if (0 === $this->process->execute('git config github.accesstoken', $output)) {
            $this->io->setAuthentication($originUrl, trim($output), 'x-oauth-basic');

            return true;
        }

        return false;
    }

    /**
     * Authorizes a GitHub domain interactively via OAuth
     *
     * @param  string                        $originUrl The host this GitHub instance is located at
     * @param  string                        $message   The reason this authorization is required
     * @throws \RuntimeException
     * @throws TransportException|\Exception
     * @return bool                          true on success
     */
    public function authorizeOAuthInteractively($originUrl, $message = null)
    {
        $attemptCounter = 0;

        $apiUrl = ('github.com' === $originUrl) ? 'api.github.com' : $originUrl . '/api/v3';

        if ($message) {
            $this->io->write($message);
        }
        $this->io->write('The credentials will be swapped for an OAuth token stored in '.$this->config->get('home').'/config.json, your password will not be stored');
        $this->io->write('To revoke access to this token you can visit https://github.com/settings/applications');
        while ($attemptCounter++ < 5) {
            try {
                $username = $this->io->ask('Username: ');
                $password = $this->io->askAndHideAnswer('Password: ');
                $this->io->setAuthentication($originUrl, $username, $password);

                // build up OAuth app name
                $appName = 'Composer';
                if (0 === $this->process->execute('hostname', $output)) {
                    $appName .= ' on ' . trim($output);
                }

                $contents = JsonFile::parseJson($this->remoteFilesystem->getContents($originUrl, 'https://'. $apiUrl . '/authorizations', false, array(
                    'http' => array(
                        'method' => 'POST',
                        'follow_location' => false,
                        'header' => "Content-Type: application/json\r\n",
                        'content' => json_encode(array(
                            'scopes' => array('repo'),
                            'note' => $appName,
                            'note_url' => 'https://getcomposer.org/',
                        )),
                    )
                )));
            } catch (TransportException $e) {
                if (in_array($e->getCode(), array(403, 401))) {
                    $this->io->write('Invalid credentials.');
                    continue;
                }

                throw $e;
            }

            $this->io->setAuthentication($originUrl, $contents['token'], 'x-oauth-basic');

            // store value in user config
            $githubTokens = $this->config->get('github-oauth') ?: array();
            $githubTokens[$originUrl] = $contents['token'];
            $this->config->getConfigSource()->addConfigSetting('github-oauth', $githubTokens);

            return true;
        }

        throw new \RuntimeException("Invalid GitHub credentials 5 times in a row, aborting.");
    }
}
