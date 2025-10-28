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

use Composer\Config;
use Composer\Downloader\TransportException;
use Composer\IO\IOInterface;

/**
 * @internal
 * @readonly
 */
final class Forgejo
{
    /** @var IOInterface */
    private $io;
    /** @var Config */
    private $config;
    /** @var HttpDownloader */
    private $httpDownloader;

    /**
     * @param IOInterface     $io
     * @param Config          $config
     * @param HttpDownloader  $httpDownloader
     */
    public function __construct(IOInterface $io, Config $config, HttpDownloader $httpDownloader)
    {
        $this->io = $io;
        $this->config = $config;
        $this->httpDownloader = $httpDownloader;
    }

    /**
     * Authorizes a Forgejo domain interactively
     *
     * @param  string                        $originUrl The host this Forgejo instance is located at
     * @param  string                        $message   The reason this authorization is required
     * @throws \RuntimeException
     * @throws TransportException|\Exception
     * @return bool                          true on success
     */
    public function authorizeOAuthInteractively(string $originUrl, ?string $message = null): bool
    {
        if ($message !== null) {
            $this->io->writeError($message);
        }

        $url = 'https://'.$originUrl.'/user/settings/applications';
        $this->io->writeError('Setup a personal access token with repository:read permissions on:');
        $this->io->writeError($url);
        $localAuthConfig = $this->config->getLocalAuthConfigSource();
        $this->io->writeError(sprintf('Tokens will be stored in plain text in "%s" for future use by Composer.', ($localAuthConfig !== null ? $localAuthConfig->getName() . ' OR ' : '') . $this->config->getAuthConfigSource()->getName()));
        $this->io->writeError('For additional information, check https://getcomposer.org/doc/articles/authentication-for-private-packages.md#forgejo-token');

        $storeInLocalAuthConfig = false;
        if ($localAuthConfig !== null) {
            $storeInLocalAuthConfig = $this->io->askConfirmation('A local auth config source was found, do you want to store the token there?', true);
        }

        $username = trim((string) $this->io->ask('Username: '));
        $token = trim((string) $this->io->askAndHideAnswer('Token (hidden): '));

        $addTokenManually = sprintf('You can also add it manually later by using "composer config --global --auth forgejo-token.%s <username> <token>"', $originUrl);
        if ($token === '' || $username === '') {
            $this->io->writeError('<warning>No username/token given, aborting.</warning>');
            $this->io->writeError($addTokenManually);

            return false;
        }

        $this->io->setAuthentication($originUrl, $username, $token);

        try {
            $this->httpDownloader->get('https://'. $originUrl . '/api/v1/version', [
                'retry-auth-failure' => false,
            ]);
        } catch (TransportException $e) {
            if (in_array($e->getCode(), [403, 401, 404], true)) {
                $this->io->writeError('<error>Invalid access token provided.</error>');
                $this->io->writeError($addTokenManually);

                return false;
            }

            throw $e;
        }

        // store value in local/user config
        $authConfigSource = $storeInLocalAuthConfig && $localAuthConfig !== null ? $localAuthConfig : $this->config->getAuthConfigSource();
        $this->config->getConfigSource()->removeConfigSetting('forgejo-token.'.$originUrl);
        $authConfigSource->addConfigSetting('forgejo-token.'.$originUrl, ['username' => $username, 'token' => $token]);

        $this->io->writeError('<info>Token stored successfully.</info>');

        return true;
    }
}
