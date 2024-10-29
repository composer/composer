<?php declare(strict_types=1);

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
use Composer\Factory;
use Composer\Downloader\TransportException;
use Composer\Pcre\Preg;

/**
 * @author Roshan Gautam <roshan.gautam@hotmail.com>
 */
class GitLab
{
    /** @var IOInterface */
    protected $io;
    /** @var Config */
    protected $config;
    /** @var ProcessExecutor */
    protected $process;
    /** @var HttpDownloader */
    protected $httpDownloader;

    /**
     * Constructor.
     *
     * @param IOInterface     $io             The IO instance
     * @param Config          $config         The composer configuration
     * @param ProcessExecutor $process        Process instance, injectable for mocking
     * @param HttpDownloader  $httpDownloader Remote Filesystem, injectable for mocking
     */
    public function __construct(IOInterface $io, Config $config, ?ProcessExecutor $process = null, ?HttpDownloader $httpDownloader = null)
    {
        $this->io = $io;
        $this->config = $config;
        $this->process = $process ?: new ProcessExecutor($io);
        $this->httpDownloader = $httpDownloader ?: Factory::createHttpDownloader($this->io, $config);
    }

    /**
     * Attempts to authorize a GitLab domain via OAuth.
     *
     * @param string $originUrl The host this GitLab instance is located at
     *
     * @return bool true on success
     */
    public function authorizeOAuth(string $originUrl): bool
    {
        // before composer 1.9, origin URLs had no port number in them
        $bcOriginUrl = Preg::replace('{:\d+}', '', $originUrl);

        if (!in_array($originUrl, $this->config->get('gitlab-domains'), true) && !in_array($bcOriginUrl, $this->config->get('gitlab-domains'), true)) {
            return false;
        }

        // if available use token from git config
        if (0 === $this->process->execute(['git', 'config', 'gitlab.accesstoken'], $output)) {
            $this->io->setAuthentication($originUrl, trim($output), 'oauth2');

            return true;
        }

        // if available use deploy token from git config
        if (0 === $this->process->execute(['git', 'config', 'gitlab.deploytoken.user'], $tokenUser) && 0 === $this->process->execute(['git', 'config', 'gitlab.deploytoken.token'], $tokenPassword)) {
            $this->io->setAuthentication($originUrl, trim($tokenUser), trim($tokenPassword));

            return true;
        }

        // if available use token from composer config
        $authTokens = $this->config->get('gitlab-token');

        if (isset($authTokens[$originUrl])) {
            $token = $authTokens[$originUrl];
        }

        if (isset($authTokens[$bcOriginUrl])) {
            $token = $authTokens[$bcOriginUrl];
        }

        if (isset($token)) {
            $username = is_array($token) ? $token["username"] : $token;
            $password = is_array($token) ? $token["token"] : 'private-token';

            // Composer expects the GitLab token to be stored as username and 'private-token' or 'gitlab-ci-token' to be stored as password
            // Detect cases where this is reversed and resolve automatically resolve it
            if (in_array($username, ['private-token', 'gitlab-ci-token',  'oauth2'], true)) {
                $this->io->setAuthentication($originUrl, $password, $username);
            } else {
                $this->io->setAuthentication($originUrl, $username, $password);
            }

            return true;
        }

        return false;
    }

    /**
     * Authorizes a GitLab domain interactively via OAuth.
     *
     * @param string $scheme    Scheme used in the origin URL
     * @param string $originUrl The host this GitLab instance is located at
     * @param string $message   The reason this authorization is required
     *
     * @throws \RuntimeException
     * @throws TransportException|\Exception
     *
     * @return bool true on success
     */
    public function authorizeOAuthInteractively(string $scheme, string $originUrl, ?string $message = null): bool
    {
        if ($message) {
            $this->io->writeError($message);
        }

        $localAuthConfig = $this->config->getLocalAuthConfigSource();
        $personalAccessTokenLink = $scheme.'://'.$originUrl.'/-/profile/personal_access_tokens';
        $revokeLink = $scheme.'://'.$originUrl.'/-/profile/applications';
        $this->io->writeError(sprintf('A token will be created and stored in "%s", your password will never be stored', ($localAuthConfig !== null ? $localAuthConfig->getName() . ' OR ' : '') . $this->config->getAuthConfigSource()->getName()));
        $this->io->writeError('To revoke access to this token you can visit:');
        $this->io->writeError($revokeLink);
        $this->io->writeError('Alternatively you can setup an personal access token on:');
        $this->io->writeError($personalAccessTokenLink);
        $this->io->writeError('and store it under "gitlab-token" see https://getcomposer.org/doc/articles/authentication-for-private-packages.md#gitlab-token for more details.');
        $this->io->writeError('https://getcomposer.org/doc/articles/authentication-for-private-packages.md#gitlab-token');
        $this->io->writeError('for more details.');

        $storeInLocalAuthConfig = false;
        if ($localAuthConfig !== null) {
            $storeInLocalAuthConfig = $this->io->askConfirmation('A local auth config source was found, do you want to store the token there?', true);
        }

        $attemptCounter = 0;

        while ($attemptCounter++ < 5) {
            try {
                $response = $this->createToken($scheme, $originUrl);
            } catch (TransportException $e) {
                // 401 is bad credentials,
                // 403 is max login attempts exceeded
                if (in_array($e->getCode(), [403, 401])) {
                    if (401 === $e->getCode()) {
                        $response = json_decode($e->getResponse(), true);
                        if (isset($response['error']) && $response['error'] === 'invalid_grant') {
                            $this->io->writeError('Bad credentials. If you have two factor authentication enabled you will have to manually create a personal access token');
                        } else {
                            $this->io->writeError('Bad credentials.');
                        }
                    } else {
                        $this->io->writeError('Maximum number of login attempts exceeded. Please try again later.');
                    }

                    $this->io->writeError('You can also manually create a personal access token enabling the "read_api" scope at:');
                    $this->io->writeError($scheme.'://'.$originUrl.'/profile/personal_access_tokens');
                    $this->io->writeError('Add it using "composer config --global --auth gitlab-token.'.$originUrl.' <token>"');

                    continue;
                }

                throw $e;
            }

            $this->io->setAuthentication($originUrl, $response['access_token'], 'oauth2');

            $authConfigSource = $storeInLocalAuthConfig && $localAuthConfig !== null ? $localAuthConfig : $this->config->getAuthConfigSource();
            // store value in user config in auth file
            if (isset($response['expires_in'])) {
                $authConfigSource->addConfigSetting(
                    'gitlab-oauth.'.$originUrl,
                    [
                        'expires-at' => intval($response['created_at']) + intval($response['expires_in']),
                        'refresh-token' => $response['refresh_token'],
                        'token' => $response['access_token'],
                    ]
                );
            } else {
                $authConfigSource->addConfigSetting('gitlab-oauth.'.$originUrl, $response['access_token']);
            }

            return true;
        }

        throw new \RuntimeException('Invalid GitLab credentials 5 times in a row, aborting.');
    }

    /**
     * Authorizes a GitLab domain interactively via OAuth.
     *
     * @param string $scheme    Scheme used in the origin URL
     * @param string $originUrl The host this GitLab instance is located at
     *
     * @throws \RuntimeException
     * @throws TransportException|\Exception
     *
     * @return bool true on success
     */
    public function authorizeOAuthRefresh(string $scheme, string $originUrl): bool
    {
        try {
            $response = $this->refreshToken($scheme, $originUrl);
        } catch (TransportException $e) {
            $this->io->writeError("Couldn't refresh access token: ".$e->getMessage());

            return false;
        }

        $this->io->setAuthentication($originUrl, $response['access_token'], 'oauth2');

        // store value in user config in auth file
        $this->config->getAuthConfigSource()->addConfigSetting(
            'gitlab-oauth.'.$originUrl,
            [
                'expires-at' => intval($response['created_at']) + intval($response['expires_in']),
                'refresh-token' => $response['refresh_token'],
                'token' => $response['access_token'],
            ]
        );

        return true;
    }

    /**
     * @return array{access_token: non-empty-string, refresh_token: non-empty-string, token_type: non-empty-string, expires_in?: positive-int, created_at: positive-int}
     *
     * @see https://docs.gitlab.com/ee/api/oauth2.html#resource-owner-password-credentials-flow
     */
    private function createToken(string $scheme, string $originUrl): array
    {
        $username = $this->io->ask('Username: ');
        $password = $this->io->askAndHideAnswer('Password: ');

        $headers = ['Content-Type: application/x-www-form-urlencoded'];

        $apiUrl = $originUrl;
        $data = http_build_query([
            'username' => $username,
            'password' => $password,
            'grant_type' => 'password',
        ], '', '&');
        $options = [
            'retry-auth-failure' => false,
            'http' => [
                'method' => 'POST',
                'header' => $headers,
                'content' => $data,
            ],
        ];

        $token = $this->httpDownloader->get($scheme.'://'.$apiUrl.'/oauth/token', $options)->decodeJson();

        $this->io->writeError('Token successfully created');

        return $token;
    }

    /**
     * Is the OAuth access token expired?
     *
     * @return bool true on expired token, false if token is fresh or expiration date is not set
     */
    public function isOAuthExpired(string $originUrl): bool
    {
        $authTokens = $this->config->get('gitlab-oauth');
        if (isset($authTokens[$originUrl]['expires-at'])) {
            if ($authTokens[$originUrl]['expires-at'] < time()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{access_token: non-empty-string, refresh_token: non-empty-string, token_type: non-empty-string, expires_in: positive-int, created_at: positive-int}
     *
     * @see https://docs.gitlab.com/ee/api/oauth2.html#resource-owner-password-credentials-flow
     */
    private function refreshToken(string $scheme, string $originUrl): array
    {
        $authTokens = $this->config->get('gitlab-oauth');
        if (!isset($authTokens[$originUrl]['refresh-token'])) {
            throw new \RuntimeException('No GitLab refresh token present for '.$originUrl.'.');
        }

        $refreshToken = $authTokens[$originUrl]['refresh-token'];
        $headers = ['Content-Type: application/x-www-form-urlencoded'];

        $data = http_build_query([
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
        ], '', '&');
        $options = [
            'retry-auth-failure' => false,
            'http' => [
                'method' => 'POST',
                'header' => $headers,
                'content' => $data,
            ],
        ];

        $token = $this->httpDownloader->get($scheme.'://'.$originUrl.'/oauth/token', $options)->decodeJson();
        $this->io->writeError('GitLab token successfully refreshed', true, IOInterface::VERY_VERBOSE);
        $this->io->writeError('To revoke access to this token you can visit '.$scheme.'://'.$originUrl.'/-/profile/applications', true, IOInterface::VERY_VERBOSE);

        return $token;
    }
}
