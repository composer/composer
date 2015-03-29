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
        $this->remoteFilesystem = $remoteFilesystem ?: new RemoteFilesystem($io, $config);
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
        if ($message) {
            $this->io->writeError($message);
        }

        $this->io->writeError(sprintf('A token will be created and stored in "%s", your password will never be stored', $this->config->getAuthConfigSource()->getName()));
        $this->io->writeError('To revoke access to this token you can visit https://github.com/settings/applications');

        $otp = null;
        $attemptCounter = 0;

        while ($attemptCounter++ < 5) {
            try {
                $response = $this->createToken($originUrl, $otp);
            } catch (TransportException $e) {
                // https://developer.github.com/v3/#authentication && https://developer.github.com/v3/auth/#working-with-two-factor-authentication
                // 401 is bad credentials, or missing otp code
                // 403 is max login attempts exceeded
                if (in_array($e->getCode(), array(403, 401))) {
                    // in case of a 401, and authentication was previously provided
                    if (401 === $e->getCode() && $this->io->hasAuthentication($originUrl)) {
                        // check for the presence of otp headers and get otp code from user
                        $otp = $this->checkTwoFactorAuthentication($e->getHeaders());
                        // if given, retry creating a token using the user provided code
                        if (null !== $otp) {
                            continue;
                        }
                    }

                    if (401 === $e->getCode()) {
                        $this->io->writeError('Bad credentials.');
                    } else {
                        $this->io->writeError('Maximum number of login attempts exceeded. Please try again later.');
                    }

                    $this->io->writeError('You can also manually create a personal token at https://github.com/settings/applications');
                    $this->io->writeError('Add it using "composer config github-oauth.github.com <token>"');

                    continue;
                }

                throw $e;
            }

            $this->io->setAuthentication($originUrl, $response['token'], 'x-oauth-basic');
            $this->config->getConfigSource()->removeConfigSetting('github-oauth.'.$originUrl);
            // store value in user config
            $this->config->getAuthConfigSource()->addConfigSetting('github-oauth.'.$originUrl, $response['token']);

            return true;
        }

        throw new \RuntimeException("Invalid GitHub credentials 5 times in a row, aborting.");
    }

    private function createToken($originUrl, $otp = null)
    {
        if (null === $otp || !$this->io->hasAuthentication($originUrl)) {
            $username = $this->io->ask('Username: ');
            $password = $this->io->askAndHideAnswer('Password: ');

            $this->io->setAuthentication($originUrl, $username, $password);
        }

        $headers = array('Content-Type: application/json');
        if ($otp) {
            $headers[] = 'X-GitHub-OTP: ' . $otp;
        }

        $note = 'Composer';
        if ($this->config->get('github-expose-hostname') === true && 0 === $this->process->execute('hostname', $output)) {
            $note .= ' on ' . trim($output);
        }
        $note .= ' [' . date('YmdHis') . ']';

        $apiUrl = ('github.com' === $originUrl) ? 'api.github.com' : $originUrl . '/api/v3';

        $json = $this->remoteFilesystem->getContents($originUrl, 'https://'. $apiUrl . '/authorizations', false, array(
            'retry-auth-failure' => false,
            'http' => array(
                'method' => 'POST',
                'follow_location' => false,
                'header' => $headers,
                'content' => json_encode(array(
                    'scopes' => array('repo'),
                    'note' => $note,
                    'note_url' => 'https://getcomposer.org/',
                )),
            )
        ));

        $this->io->writeError('Token successfully created');

        return JsonFile::parseJson($json);
    }

    private function checkTwoFactorAuthentication(array $headers)
    {
        $headerNames = array_map(
            function ($header) {
                return strtolower(strstr($header, ':', true));
            },
            $headers
        );

        if (false !== ($key = array_search('x-github-otp', $headerNames))) {
            list($required, $method) = array_map('trim', explode(';', substr(strstr($headers[$key], ':'), 1)));

            if ('required' === $required) {
                $this->io->writeError('Two-factor Authentication');

                if ('app' === $method) {
                    $this->io->writeError('Open the two-factor authentication app on your device to view your authentication code and verify your identity.');
                }

                if ('sms' === $method) {
                    $this->io->writeError('You have been sent an SMS message with an authentication code to verify your identity.');
                }

                return $this->io->ask('Authentication Code: ');
            }
        }

        return null;
    }
}
