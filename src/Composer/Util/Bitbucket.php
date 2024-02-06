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

use Composer\Factory;
use Composer\IO\IOInterface;
use Composer\Config;
use Composer\Downloader\TransportException;

/**
 * @author Paul Wenke <wenke.paul@gmail.com>
 */
class Bitbucket
{
    /** @var IOInterface */
    private $io;
    /** @var Config */
    private $config;
    /** @var ProcessExecutor */
    private $process;
    /** @var HttpDownloader */
    private $httpDownloader;
    /** @var array{access_token: string, expires_in?: int}|null */
    private $token = null;
    /** @var int|null */
    private $time;

    public const OAUTH2_ACCESS_TOKEN_URL = 'https://bitbucket.org/site/oauth2/access_token';

    /**
     * Constructor.
     *
     * @param IOInterface     $io             The IO instance
     * @param Config          $config         The composer configuration
     * @param ProcessExecutor $process        Process instance, injectable for mocking
     * @param HttpDownloader  $httpDownloader Remote Filesystem, injectable for mocking
     * @param int             $time           Timestamp, injectable for mocking
     */
    public function __construct(IOInterface $io, Config $config, ?ProcessExecutor $process = null, ?HttpDownloader $httpDownloader = null, ?int $time = null)
    {
        $this->io = $io;
        $this->config = $config;
        $this->process = $process ?: new ProcessExecutor($io);
        $this->httpDownloader = $httpDownloader ?: Factory::createHttpDownloader($this->io, $config);
        $this->time = $time;
    }

    public function getToken(): string
    {
        if (!isset($this->token['access_token'])) {
            return '';
        }

        return $this->token['access_token'];
    }

    /**
     * Attempts to authorize a Bitbucket domain via OAuth
     *
     * @param  string $originUrl The host this Bitbucket instance is located at
     * @return bool   true on success
     */
    public function authorizeOAuth(string $originUrl): bool
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

    private function requestAccessToken(): bool
    {
        try {
            $response = $this->httpDownloader->get(self::OAUTH2_ACCESS_TOKEN_URL, [
                'retry-auth-failure' => false,
                'http' => [
                    'method' => 'POST',
                    'content' => 'grant_type=client_credentials',
                ],
            ]);

            $token = $response->decodeJson();
            if (!isset($token['expires_in']) || !isset($token['access_token'])) {
                throw new \LogicException('Expected a token configured with expires_in and access_token present, got '.json_encode($token));
            }

            $this->token = $token;
        } catch (TransportException $e) {
            if ($e->getCode() === 400) {
                $this->io->writeError('<error>Invalid OAuth consumer provided.</error>');
                $this->io->writeError('This can have three reasons:');
                $this->io->writeError('1. You are authenticating with a bitbucket username/password combination');
                $this->io->writeError('2. You are using an OAuth consumer, but didn\'t configure a (dummy) callback url');
                $this->io->writeError('3. You are using an OAuth consumer, but didn\'t configure it as private consumer');

                return false;
            }
            if (in_array($e->getCode(), [403, 401])) {
                $this->io->writeError('<error>Invalid OAuth consumer provided.</error>');
                $this->io->writeError('You can also add it manually later by using "composer config --global --auth bitbucket-oauth.bitbucket.org <consumer-key> <consumer-secret>"');

                return false;
            }

            throw $e;
        }

        return true;
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
    public function authorizeOAuthInteractively(string $originUrl, ?string $message = null): bool
    {
        if ($message) {
            $this->io->writeError($message);
        }

        $localAuthConfig = $this->config->getLocalAuthConfigSource();
        $url = 'https://support.atlassian.com/bitbucket-cloud/docs/use-oauth-on-bitbucket-cloud/';
        $this->io->writeError('Follow the instructions here:');
        $this->io->writeError($url);
        $this->io->writeError(sprintf('to create a consumer. It will be stored in "%s" for future use by Composer.', ($localAuthConfig !== null ? $localAuthConfig->getName() . ' OR ' : '') . $this->config->getAuthConfigSource()->getName()));
        $this->io->writeError('Ensure you enter a "Callback URL" (http://example.com is fine) or it will not be possible to create an Access Token (this callback url will not be used by composer)');

        $storeInLocalAuthConfig = false;
        if ($localAuthConfig !== null) {
            $storeInLocalAuthConfig = $this->io->askConfirmation('A local auth config source was found, do you want to store the token there?', true);
        }

        $consumerKey = trim((string) $this->io->askAndHideAnswer('Consumer Key (hidden): '));

        if (!$consumerKey) {
            $this->io->writeError('<warning>No consumer key given, aborting.</warning>');
            $this->io->writeError('You can also add it manually later by using "composer config --global --auth bitbucket-oauth.bitbucket.org <consumer-key> <consumer-secret>"');

            return false;
        }

        $consumerSecret = trim((string) $this->io->askAndHideAnswer('Consumer Secret (hidden): '));

        if (!$consumerSecret) {
            $this->io->writeError('<warning>No consumer secret given, aborting.</warning>');
            $this->io->writeError('You can also add it manually later by using "composer config --global --auth bitbucket-oauth.bitbucket.org <consumer-key> <consumer-secret>"');

            return false;
        }

        $this->io->setAuthentication($originUrl, $consumerKey, $consumerSecret);

        if (!$this->requestAccessToken()) {
            return false;
        }

        // store value in user config
        $authConfigSource = $storeInLocalAuthConfig && $localAuthConfig !== null ? $localAuthConfig : $this->config->getAuthConfigSource();
        $this->storeInAuthConfig($authConfigSource, $originUrl, $consumerKey, $consumerSecret);

        // Remove conflicting basic auth credentials (if available)
        $this->config->getAuthConfigSource()->removeConfigSetting('http-basic.' . $originUrl);

        $this->io->writeError('<info>Consumer stored successfully.</info>');

        return true;
    }

    /**
     * Retrieves an access token from Bitbucket.
     */
    public function requestToken(string $originUrl, string $consumerKey, string $consumerSecret): string
    {
        if ($this->token !== null || $this->getTokenFromConfig($originUrl)) {
            return $this->token['access_token'];
        }

        $this->io->setAuthentication($originUrl, $consumerKey, $consumerSecret);
        if (!$this->requestAccessToken()) {
            return '';
        }

        $this->storeInAuthConfig($this->config->getLocalAuthConfigSource() ?? $this->config->getAuthConfigSource(), $originUrl, $consumerKey, $consumerSecret);

        if (!isset($this->token['access_token'])) {
            throw new \LogicException('Failed to initialize token above');
        }

        return $this->token['access_token'];
    }

    /**
     * Store the new/updated credentials to the configuration
     */
    private function storeInAuthConfig(Config\ConfigSourceInterface $authConfigSource, string $originUrl, string $consumerKey, string $consumerSecret): void
    {
        $this->config->getConfigSource()->removeConfigSetting('bitbucket-oauth.'.$originUrl);

        if (null === $this->token || !isset($this->token['expires_in'])) {
            throw new \LogicException('Expected a token configured with expires_in present, got '.json_encode($this->token));
        }

        $time = null === $this->time ? time() : $this->time;
        $consumer = [
            "consumer-key" => $consumerKey,
            "consumer-secret" => $consumerSecret,
            "access-token" => $this->token['access_token'],
            "access-token-expiration" => $time + $this->token['expires_in'],
        ];

        $this->config->getAuthConfigSource()->addConfigSetting('bitbucket-oauth.'.$originUrl, $consumer);
    }

    /**
     * @phpstan-assert-if-true array{access_token: string} $this->token
     */
    private function getTokenFromConfig(string $originUrl): bool
    {
        $authConfig = $this->config->get('bitbucket-oauth');

        if (
            !isset($authConfig[$originUrl]['access-token'], $authConfig[$originUrl]['access-token-expiration'])
            || time() > $authConfig[$originUrl]['access-token-expiration']
        ) {
            return false;
        }

        $this->token = [
            'access_token' => $authConfig[$originUrl]['access-token'],
        ];

        return true;
    }
}
