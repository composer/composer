<?php

/*
 * This file is part of Composer.
 *
 * (c) Roshan Gautam <roshan.gautam@hotmail.com>
 *     
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
 * @author Roshan Gautam <roshan.gautam@hotmail.com>
 */
class GitLab
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
        $this->remoteFilesystem = $remoteFilesystem ?: new RemoteFilesystem($io, $config);
    }

    /**
     * Attempts to authorize a GitLab domain via OAuth
     *
     * @param  string $originUrl The host this GitLab instance is located at
     * @return bool   true on success
     */
    public function authorizeOAuth($originUrl)
    {
        if (!in_array($originUrl, $this->config->get('gitlab-domains'))) {
            return false;
        }

        // if available use token from git config
        if (0 === $this->process->execute('git config gitlab.accesstoken', $output)) {
            $this->io->setAuthentication($originUrl, trim($output), 'oauth2');

            return true;
        }

        return false;
    }

    /**
     * Authorizes a GitLab domain interactively via OAuth
     *
     * @param  string                        $originUrl The host this GitLab instance is located at
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


        $this->io->writeError(sprintf('A token will be created and stored in "%s", your password will never be stored', $this->config->getAuthConfigSource()->getName()));
        $this->io->writeError('To revoke access to this token you can visit ' . $this->config->get('gitlab-domains')[0] . '/profile/applications');

        $attemptCounter = 0;

        while ($attemptCounter++ < 5) {
            try {
                $response = $this->createToken($originUrl);
            } catch (TransportException $e) {
                // 401 is bad credentials, 
                // 403 is max login attempts exceeded
                if (in_array($e->getCode(), array(403, 401))) {

                    if (401 === $e->getCode()) {
                        $this->io->writeError('Bad credentials.');
                    } else {
                        $this->io->writeError('Maximum number of login attempts exceeded. Please try again later.');
                    }

                    $this->io->writeError('You can also manually create a personal token at ' . $this->config->get('gitlab-domains')[0] . '/profile/applications');
                    $this->io->writeError('Add it using "composer config gitlab-oauth.' . $this->config->get('gitlab-domains')[0] . ' <token>"');

                    continue;
                }

                throw $e;
            }

            $this->io->setAuthentication($originUrl, $response['access_token'], 'oauth2');
            $this->config->getConfigSource()->removeConfigSetting('gitlab-oauth.'.$originUrl);
            // store value in user config
            $this->config->getAuthConfigSource()->addConfigSetting('gitlab-oauth.'.$originUrl, $response['access_token']);

            return true;
        }

        throw new \RuntimeException("Invalid GitLab credentials 5 times in a row, aborting.");
    }

    private function createToken($originUrl)
    {
        if (!$this->io->hasAuthentication($originUrl)) {
            $username = $this->io->ask('Username: ');
            $password = $this->io->askAndHideAnswer('Password: ');

            $this->io->setAuthentication($originUrl, $username, $password);
        }


        $headers = array('Content-Type: application/x-www-form-urlencoded');

        $note = 'Composer';
        if ($this->config->get('GitLab-expose-hostname') === true && 0 === $this->process->execute('hostname', $output)) {
            $note .= ' on ' . trim($output);
        }
        $note .= ' [' . date('YmdHis') . ']';

        $apiUrl = $originUrl ;
        $data = http_build_query(
            array(
                'username'  => $username,
                'password'  => $password,
                'grant_type' => 'password',            
                )
            );
        $options = array(
            'retry-auth-failure' => false,
            'http' => array(
                'method' => 'POST',
                'header' => $headers,
                'content' => $data
            ));

        $json = $this->remoteFilesystem->getContents($originUrl, 'http://'. $apiUrl . '/oauth/token', false, $options);

        $this->io->writeError('Token successfully created');

        return JsonFile::parseJson($json);
    }
}
