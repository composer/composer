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
use Composer\Pcre\Preg;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class GitHub
{
    public const GITHUB_TOKEN_REGEX = '{^([a-f0-9]{12,}|gh[a-z]_[a-zA-Z0-9_]+|github_pat_[a-zA-Z0-9_]+)$}';

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
     * Attempts to authorize a GitHub domain via OAuth
     *
     * @param  string $originUrl The host this GitHub instance is located at
     * @return bool   true on success
     */
    public function authorizeOAuth(string $originUrl): bool
    {
        if (!in_array($originUrl, $this->config->get('github-domains'))) {
            return false;
        }

        // if available use token from git config
        if (0 === $this->process->execute(['git', 'config', 'github.accesstoken'], $output)) {
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
    public function authorizeOAuthInteractively(string $originUrl, ?string $message = null): bool
    {
        if ($message) {
            $this->io->writeError($message);
        }

        $note = 'Composer';
        if ($this->config->get('github-expose-hostname') === true && 0 === $this->process->execute(['hostname'], $output)) {
            $note .= ' on ' . trim($output);
        }
        $note .= ' ' . date('Y-m-d Hi');

        $localAuthConfig = $this->config->getLocalAuthConfigSource();

        $this->io->writeError([
            'You need to provide a GitHub access token.',
            sprintf('Tokens will be stored in plain text in "%s" for future use by Composer.', ($localAuthConfig !== null ? $localAuthConfig->getName() . ' OR ' : '') . $this->config->getAuthConfigSource()->getName()),
            'Due to the security risk of tokens being exfiltrated, use tokens with short expiration times and only the minimum permissions necessary.',
            '',
            'Carefully consider the following options in order:',
            '',
        ]);

        $this->io->writeError([
            '1. When working with _public_ GitHub repositories only, use a fine-grained token with read-only access to public information.',
            'Use the following URL to create such a token:',
            'https://'.$originUrl.'/settings/personal-access-tokens/new?name=' . str_replace('%20', '+', rawurlencode($note)),
            '',
        ]);

        $this->io->writeError([
            '2. When you need to work with _private_ GitHub repositories as well, but they all belong to a single user or organisation,',
            'use a fine-grained token with repository read permissions only. You can start with the following URL, but you may need to',
            'change the resource owner to the right user or organisation. Additionally, you can scope permissions down to apply only to selected repositories.',
            'https://'.$originUrl.'/settings/personal-access-tokens/new?contents=read&name=' . str_replace('%20', '+', rawurlencode($note)),
            '',
        ]);

        $this->io->writeError([
            '3. A "classic" token grants broad permissions on your behalf to all repositories accessible by you.',
            'This may include write permissions, even though not needed by Composer. Use it only when you need to access',
            'private repositories across multiple organisations at the same time and using directory-specific authentication sources',
            'is not an option. You can generate a classic token here:',
            'https://'.$originUrl.'/settings/tokens/new?scopes=repo&description=' . str_replace('%20', '+', rawurlencode($note)),
            '',
        ]);

        $this->io->writeError('For additional information, check https://getcomposer.org/doc/articles/authentication-for-private-packages.md#github-oauth');

        $storeInLocalAuthConfig = false;
        if ($localAuthConfig !== null) {
            $storeInLocalAuthConfig = $this->io->askConfirmation('A local auth config source was found, do you want to store the token there?', true);
        }

        $token = trim((string) $this->io->askAndHideAnswer('Token (hidden): '));

        if ($token === '') {
            $this->io->writeError('<warning>No token given, aborting.</warning>');
            $this->io->writeError('You can also add it manually later by using "composer config --global --auth github-oauth.github.com <token>"');

            return false;
        }

        $this->io->setAuthentication($originUrl, $token, 'x-oauth-basic');

        try {
            $apiUrl = ('github.com' === $originUrl) ? 'api.github.com/' : $originUrl . '/api/v3/';

            $this->httpDownloader->get('https://'. $apiUrl, [
                'retry-auth-failure' => false,
            ]);
        } catch (TransportException $e) {
            if (in_array($e->getCode(), [403, 401])) {
                $this->io->writeError('<error>Invalid token provided.</error>');
                $this->io->writeError('You can also add it manually later by using "composer config --global --auth github-oauth.github.com <token>"');

                return false;
            }

            throw $e;
        }

        // store value in local/user config
        $authConfigSource = $storeInLocalAuthConfig && $localAuthConfig !== null ? $localAuthConfig : $this->config->getAuthConfigSource();
        $this->config->getConfigSource()->removeConfigSetting('github-oauth.'.$originUrl);
        $authConfigSource->addConfigSetting('github-oauth.'.$originUrl, $token);

        $this->io->writeError('<info>Token stored successfully.</info>');

        return true;
    }

    /**
     * Extract rate limit from response.
     *
     * @param string[] $headers Headers from Composer\Downloader\TransportException.
     *
     * @return array{limit: int|'?', reset: string}
     */
    public function getRateLimit(array $headers): array
    {
        $rateLimit = [
            'limit' => '?',
            'reset' => '?',
        ];

        foreach ($headers as $header) {
            $header = trim($header);
            if (false === stripos($header, 'x-ratelimit-')) {
                continue;
            }
            [$type, $value] = explode(':', $header, 2);
            switch (strtolower($type)) {
                case 'x-ratelimit-limit':
                    $rateLimit['limit'] = (int) trim($value);
                    break;
                case 'x-ratelimit-reset':
                    $rateLimit['reset'] = date('Y-m-d H:i:s', (int) trim($value));
                    break;
            }
        }

        return $rateLimit;
    }

    /**
     * Extract SSO URL from response.
     *
     * @param string[] $headers Headers from Composer\Downloader\TransportException.
     */
    public function getSsoUrl(array $headers): ?string
    {
        foreach ($headers as $header) {
            $header = trim($header);
            if (false === stripos($header, 'x-github-sso: required')) {
                continue;
            }
            if (Preg::isMatch('{\burl=(?P<url>[^\s;]+)}', $header, $match)) {
                return $match['url'];
            }
        }

        return null;
    }

    /**
     * Finds whether a request failed due to rate limiting
     *
     * @param string[] $headers Headers from Composer\Downloader\TransportException.
     */
    public function isRateLimited(array $headers): bool
    {
        foreach ($headers as $header) {
            if (Preg::isMatch('{^x-ratelimit-remaining: *0$}i', trim($header))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Finds whether a request failed due to lacking SSO authorization
     *
     * @see https://docs.github.com/en/rest/overview/other-authentication-methods#authenticating-for-saml-sso
     *
     * @param string[] $headers Headers from Composer\Downloader\TransportException.
     */
    public function requiresSso(array $headers): bool
    {
        foreach ($headers as $header) {
            if (Preg::isMatch('{^x-github-sso: required}i', trim($header))) {
                return true;
            }
        }

        return false;
    }
}
