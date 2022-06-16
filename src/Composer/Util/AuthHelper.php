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
use Composer\IO\IOInterface;
use Composer\Downloader\TransportException;
use Composer\Pcre\Preg;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class AuthHelper
{
    /** @var IOInterface */
    protected $io;
    /** @var Config */
    protected $config;
    /** @var array<string, string> Map of origins to message displayed */
    private $displayedOriginAuthentications = array();

    public function __construct(IOInterface $io, Config $config)
    {
        $this->io = $io;
        $this->config = $config;
    }

    /**
     * @param string      $origin
     * @param 'prompt'|bool $storeAuth
     *
     * @return void
     */
    public function storeAuth(string $origin, $storeAuth): void
    {
        $store = false;
        $configSource = $this->config->getAuthConfigSource();
        if ($storeAuth === true) {
            $store = $configSource;
        } elseif ($storeAuth === 'prompt') {
            $answer = $this->io->askAndValidate(
                'Do you want to store credentials for '.$origin.' in '.$configSource->getName().' ? [Yn] ',
                function ($value): string {
                    $input = strtolower(substr(trim($value), 0, 1));
                    if (in_array($input, array('y','n'))) {
                        return $input;
                    }
                    throw new \RuntimeException('Please answer (y)es or (n)o');
                },
                null,
                'y'
            );

            if ($answer === 'y') {
                $store = $configSource;
            }
        }
        if ($store) {
            $store->addConfigSetting(
                'http-basic.'.$origin,
                $this->io->getAuthentication($origin)
            );
        }
    }

    /**
     * @param  string      $url
     * @param  string      $origin
     * @param  int         $statusCode HTTP status code that triggered this call
     * @param  string|null $reason     a message/description explaining why this was called
     * @param  string[]    $headers
     * @param  int         $retryCount the amount of retries already done on this URL
     * @return array       containing retry (bool) and storeAuth (string|bool) keys, if retry is true the request should be
     *                                retried, if storeAuth is true then on a successful retry the authentication should be persisted to auth.json
     * @phpstan-return array{retry: bool, storeAuth: 'prompt'|bool}
     */
    public function promptAuthIfNeeded(string $url, string $origin, int $statusCode, ?string $reason = null, array $headers = array(), int $retryCount = 0): array
    {
        $storeAuth = false;

        if (in_array($origin, $this->config->get('github-domains'), true)) {
            $gitHubUtil = new GitHub($this->io, $this->config, null);
            $message = "\n";

            $rateLimited = $gitHubUtil->isRateLimited($headers);
            $requiresSso = $gitHubUtil->requiresSso($headers);

            if ($requiresSso) {
                $ssoUrl = $gitHubUtil->getSsoUrl($headers);
                $message = 'GitHub API token requires SSO authorization. Authorize this token at ' . $ssoUrl . "\n";
                $this->io->writeError($message);
                if (!$this->io->isInteractive()) {
                    throw new TransportException('Could not authenticate against ' . $origin, 403);
                }
                $this->io->ask('After authorizing your token, confirm that you would like to retry the request');

                return array('retry' => true, 'storeAuth' => $storeAuth);
            }

            if ($rateLimited) {
                $rateLimit = $gitHubUtil->getRateLimit($headers);
                if ($this->io->hasAuthentication($origin)) {
                    $message = 'Review your configured GitHub OAuth token or enter a new one to go over the API rate limit.';
                } else {
                    $message = 'Create a GitHub OAuth token to go over the API rate limit.';
                }

                $message = sprintf(
                    'GitHub API limit (%d calls/hr) is exhausted, could not fetch '.$url.'. '.$message.' You can also wait until %s for the rate limit to reset.',
                    $rateLimit['limit'],
                    $rateLimit['reset']
                )."\n";
            } else {
                $message .= 'Could not fetch '.$url.', please ';
                if ($this->io->hasAuthentication($origin)) {
                    $message .= 'review your configured GitHub OAuth token or enter a new one to access private repos';
                } else {
                    $message .= 'create a GitHub OAuth token to access private repos';
                }
            }

            if (!$gitHubUtil->authorizeOAuth($origin)
                && (!$this->io->isInteractive() || !$gitHubUtil->authorizeOAuthInteractively($origin, $message))
            ) {
                throw new TransportException('Could not authenticate against '.$origin, 401);
            }
        } elseif (in_array($origin, $this->config->get('gitlab-domains'), true)) {
            $message = "\n".'Could not fetch '.$url.', enter your ' . $origin . ' credentials ' .($statusCode === 401 ? 'to access private repos' : 'to go over the API rate limit');
            $gitLabUtil = new GitLab($this->io, $this->config, null);

            $auth = null;
            if ($this->io->hasAuthentication($origin)) {
                $auth = $this->io->getAuthentication($origin);
                if (in_array($auth['password'], array('gitlab-ci-token', 'private-token', 'oauth2'), true)) {
                    throw new TransportException("Invalid credentials for '" . $url . "', aborting.", $statusCode);
                }
            }

            if (!$gitLabUtil->authorizeOAuth($origin)
                && (!$this->io->isInteractive() || !$gitLabUtil->authorizeOAuthInteractively(parse_url($url, PHP_URL_SCHEME), $origin, $message))
            ) {
                throw new TransportException('Could not authenticate against '.$origin, 401);
            }

            if ($auth !== null && $this->io->hasAuthentication($origin)) {
                if ($auth === $this->io->getAuthentication($origin)) {
                    throw new TransportException("Invalid credentials for '" . $url . "', aborting.", $statusCode);
                }
            }
        } elseif ($origin === 'bitbucket.org' || $origin === 'api.bitbucket.org') {
            $askForOAuthToken = true;
            $origin = 'bitbucket.org';
            if ($this->io->hasAuthentication($origin)) {
                $auth = $this->io->getAuthentication($origin);
                if ($auth['username'] !== 'x-token-auth') {
                    $bitbucketUtil = new Bitbucket($this->io, $this->config);
                    $accessToken = $bitbucketUtil->requestToken($origin, $auth['username'], $auth['password']);
                    if (!empty($accessToken)) {
                        $this->io->setAuthentication($origin, 'x-token-auth', $accessToken);
                        $askForOAuthToken = false;
                    }
                } else {
                    throw new TransportException('Could not authenticate against ' . $origin, 401);
                }
            }

            if ($askForOAuthToken) {
                $message = "\n".'Could not fetch ' . $url . ', please create a bitbucket OAuth token to ' . (($statusCode === 401 || $statusCode === 403) ? 'access private repos' : 'go over the API rate limit');
                $bitBucketUtil = new Bitbucket($this->io, $this->config);
                if (!$bitBucketUtil->authorizeOAuth($origin)
                    && (!$this->io->isInteractive() || !$bitBucketUtil->authorizeOAuthInteractively($origin, $message))
                ) {
                    throw new TransportException('Could not authenticate against ' . $origin, 401);
                }
            }
        } else {
            // 404s are only handled for github
            if ($statusCode === 404) {
                return ['retry' => false, 'storeAuth' => false];
            }

            // fail if the console is not interactive
            if (!$this->io->isInteractive()) {
                if ($statusCode === 401) {
                    $message = "The '" . $url . "' URL required authentication.\nYou must be using the interactive console to authenticate";
                } elseif ($statusCode === 403) {
                    $message = "The '" . $url . "' URL could not be accessed: " . $reason;
                } else {
                    $message = "Unknown error code '" . $statusCode . "', reason: " . $reason;
                }

                throw new TransportException($message, $statusCode);
            }

            // fail if we already have auth
            if ($this->io->hasAuthentication($origin)) {
                // if two or more requests are started together for the same host, and the first
                // received authentication already, we let the others retry before failing them
                if ($retryCount === 0) {
                    return array('retry' => true, 'storeAuth' => false);
                }

                throw new TransportException("Invalid credentials for '" . $url . "', aborting.", $statusCode);
            }

            $this->io->writeError('    Authentication required (<info>'.$origin.'</info>):');
            $username = $this->io->ask('      Username: ');
            $password = $this->io->askAndHideAnswer('      Password: ');
            $this->io->setAuthentication($origin, $username, $password);
            $storeAuth = $this->config->get('store-auths');
        }

        return array('retry' => true, 'storeAuth' => $storeAuth);
    }

    /**
     * @param string[] $headers
     * @param string   $origin
     * @param string   $url
     *
     * @return string[] updated headers array
     */
    public function addAuthenticationHeader(array $headers, string $origin, string $url): array
    {
        if ($this->io->hasAuthentication($origin)) {
            $authenticationDisplayMessage = null;
            $auth = $this->io->getAuthentication($origin);
            if ($auth['password'] === 'bearer') {
                $headers[] = 'Authorization: Bearer '.$auth['username'];
            } elseif ('github.com' === $origin && 'x-oauth-basic' === $auth['password']) {
                // only add the access_token if it is actually a github API URL
                if (Preg::isMatch('{^https?://api\.github\.com/}', $url)) {
                    $headers[] = 'Authorization: token '.$auth['username'];
                    $authenticationDisplayMessage = 'Using GitHub token authentication';
                }
            } elseif (
                in_array($origin, $this->config->get('gitlab-domains'), true)
                && in_array($auth['password'], array('oauth2', 'private-token', 'gitlab-ci-token'), true)
            ) {
                if ($auth['password'] === 'oauth2') {
                    $headers[] = 'Authorization: Bearer '.$auth['username'];
                    $authenticationDisplayMessage = 'Using GitLab OAuth token authentication';
                } else {
                    $headers[] = 'PRIVATE-TOKEN: '.$auth['username'];
                    $authenticationDisplayMessage = 'Using GitLab private token authentication';
                }
            } elseif (
                'bitbucket.org' === $origin
                && $url !== Bitbucket::OAUTH2_ACCESS_TOKEN_URL
                && 'x-token-auth' === $auth['username']
            ) {
                if (!$this->isPublicBitBucketDownload($url)) {
                    $headers[] = 'Authorization: Bearer ' . $auth['password'];
                    $authenticationDisplayMessage = 'Using Bitbucket OAuth token authentication';
                }
            } else {
                $authStr = base64_encode($auth['username'] . ':' . $auth['password']);
                $headers[] = 'Authorization: Basic '.$authStr;
                $authenticationDisplayMessage = 'Using HTTP basic authentication with username "' . $auth['username'] . '"';
            }

            if ($authenticationDisplayMessage && (!isset($this->displayedOriginAuthentications[$origin]) || $this->displayedOriginAuthentications[$origin] !== $authenticationDisplayMessage)) {
                $this->io->writeError($authenticationDisplayMessage, true, IOInterface::DEBUG);
                $this->displayedOriginAuthentications[$origin] = $authenticationDisplayMessage;
            }
        } elseif (in_array($origin, array('api.bitbucket.org', 'api.github.com'), true)) {
            return $this->addAuthenticationHeader($headers, str_replace('api.', '', $origin), $url);
        }

        return $headers;
    }

    /**
     * @link https://github.com/composer/composer/issues/5584
     *
     * @param string $urlToBitBucketFile URL to a file at bitbucket.org.
     *
     * @return bool Whether the given URL is a public BitBucket download which requires no authentication.
     */
    public function isPublicBitBucketDownload(string $urlToBitBucketFile): bool
    {
        $domain = parse_url($urlToBitBucketFile, PHP_URL_HOST);
        if (!str_contains($domain, 'bitbucket.org')  ) {
            // Bitbucket downloads are hosted on amazonaws.
            // We do not need to authenticate there at all
            return true;
        }

        $path = parse_url($urlToBitBucketFile, PHP_URL_PATH);

        // Path for a public download follows this pattern /{user}/{repo}/downloads/{whatever}
        // {@link https://blog.bitbucket.org/2009/04/12/new-feature-downloads/}
        $pathParts = explode('/', $path);

        return count($pathParts) >= 4 && $pathParts[3] == 'downloads';
    }
}
