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

namespace Composer\IO;

use Composer\Config;
use Composer\Pcre\Preg;
use Composer\Util\ProcessExecutor;
use Composer\Util\Silencer;
use Psr\Log\LogLevel;

abstract class BaseIO implements IOInterface
{
    /** @var array<string, array{username: string|null, password: string|null}> */
    protected $authentications = [];

    /**
     * @inheritDoc
     */
    public function getAuthentications()
    {
        return $this->authentications;
    }

    /**
     * @return void
     */
    public function resetAuthentications()
    {
        $this->authentications = [];
    }

    /**
     * @inheritDoc
     */
    public function hasAuthentication($repositoryName)
    {
        return isset($this->authentications[$repositoryName]);
    }

    /**
     * @inheritDoc
     */
    public function getAuthentication($repositoryName)
    {
        if (isset($this->authentications[$repositoryName])) {
            return $this->authentications[$repositoryName];
        }

        return ['username' => null, 'password' => null];
    }

    /**
     * @inheritDoc
     */
    public function setAuthentication($repositoryName, $username, $password = null)
    {
        $this->authentications[$repositoryName] = ['username' => $username, 'password' => $password];
    }

    /**
     * @inheritDoc
     */
    public function writeRaw($messages, bool $newline = true, int $verbosity = self::NORMAL)
    {
        $this->write($messages, $newline, $verbosity);
    }

    /**
     * @inheritDoc
     */
    public function writeErrorRaw($messages, bool $newline = true, int $verbosity = self::NORMAL)
    {
        $this->writeError($messages, $newline, $verbosity);
    }

    /**
     * Check for overwrite and set the authentication information for the repository.
     *
     * @param string $repositoryName The unique name of repository
     * @param string $username       The username
     * @param string $password       The password
     *
     * @return void
     */
    protected function checkAndSetAuthentication(string $repositoryName, string $username, ?string $password = null)
    {
        if ($this->hasAuthentication($repositoryName)) {
            $auth = $this->getAuthentication($repositoryName);
            if ($auth['username'] === $username && $auth['password'] === $password) {
                return;
            }

            $this->writeError(
                sprintf(
                    "<warning>Warning: You should avoid overwriting already defined auth settings for %s.</warning>",
                    $repositoryName
                )
            );
        }
        $this->setAuthentication($repositoryName, $username, $password);
    }

    /**
     * @inheritDoc
     */
    public function loadConfiguration(Config $config)
    {
        $bitbucketOauth = $config->get('bitbucket-oauth');
        $githubOauth = $config->get('github-oauth');
        $gitlabOauth = $config->get('gitlab-oauth');
        $gitlabToken = $config->get('gitlab-token');
        $httpBasic = $config->get('http-basic');
        $bearerToken = $config->get('bearer');
        $customHeaders = $config->get('custom-headers');

        // reload oauth tokens from config if available

        foreach ($bitbucketOauth as $domain => $cred) {
            $this->checkAndSetAuthentication($domain, $cred['consumer-key'], $cred['consumer-secret']);
        }

        foreach ($githubOauth as $domain => $token) {
            if ($domain !== 'github.com' && !in_array($domain, $config->get('github-domains'), true)) {
                $this->debug($domain.' is not in the configured github-domains, adding it implicitly as authentication is configured for this domain');
                $config->merge(['config' => ['github-domains' => array_merge($config->get('github-domains'), [$domain])]], 'implicit-due-to-auth');
            }

            // allowed chars for GH tokens are from https://github.blog/changelog/2021-03-04-authentication-token-format-updates/
            // plus dots which were at some point used for GH app integration tokens
            if (!Preg::isMatch('{^[.A-Za-z0-9_]+$}', $token)) {
                throw new \UnexpectedValueException('Your github oauth token for '.$domain.' contains invalid characters: "'.$token.'"');
            }
            $this->checkAndSetAuthentication($domain, $token, 'x-oauth-basic');
        }

        foreach ($gitlabOauth as $domain => $token) {
            if ($domain !== 'gitlab.com' && !in_array($domain, $config->get('gitlab-domains'), true)) {
                $this->debug($domain.' is not in the configured gitlab-domains, adding it implicitly as authentication is configured for this domain');
                $config->merge(['config' => ['gitlab-domains' => array_merge($config->get('gitlab-domains'), [$domain])]], 'implicit-due-to-auth');
            }

            $token = is_array($token) ? $token["token"] : $token;
            $this->checkAndSetAuthentication($domain, $token, 'oauth2');
        }

        foreach ($gitlabToken as $domain => $token) {
            if ($domain !== 'gitlab.com' && !in_array($domain, $config->get('gitlab-domains'), true)) {
                $this->debug($domain.' is not in the configured gitlab-domains, adding it implicitly as authentication is configured for this domain');
                $config->merge(['config' => ['gitlab-domains' => array_merge($config->get('gitlab-domains'), [$domain])]], 'implicit-due-to-auth');
            }

            $username = is_array($token) ? $token["username"] : $token;
            $password = is_array($token) ? $token["token"] : 'private-token';
            $this->checkAndSetAuthentication($domain, $username, $password);
        }

        // reload http basic credentials from config if available
        foreach ($httpBasic as $domain => $cred) {
            $this->checkAndSetAuthentication($domain, $cred['username'], $cred['password']);
        }

        foreach ($bearerToken as $domain => $token) {
            $this->checkAndSetAuthentication($domain, $token, 'bearer');
        }

        // load custom HTTP headers from config
        foreach ($customHeaders as $domain => $headers) {
            if ($headers !== null) {
                $this->checkAndSetAuthentication($domain, (string) json_encode($headers), 'custom-headers');
            }
        }

        // setup process timeout
        ProcessExecutor::setTimeout($config->get('process-timeout'));
    }

    /**
     * @param string|\Stringable $message
     */
    public function emergency($message, array $context = []): void
    {
        $this->log(LogLevel::EMERGENCY, $message, $context);
    }

    /**
     * @param string|\Stringable $message
     */
    public function alert($message, array $context = []): void
    {
        $this->log(LogLevel::ALERT, $message, $context);
    }

    /**
     * @param string|\Stringable $message
     */
    public function critical($message, array $context = []): void
    {
        $this->log(LogLevel::CRITICAL, $message, $context);
    }

    /**
     * @param string|\Stringable $message
     */
    public function error($message, array $context = []): void
    {
        $this->log(LogLevel::ERROR, $message, $context);
    }

    /**
     * @param string|\Stringable $message
     */
    public function warning($message, array $context = []): void
    {
        $this->log(LogLevel::WARNING, $message, $context);
    }

    /**
     * @param string|\Stringable $message
     */
    public function notice($message, array $context = []): void
    {
        $this->log(LogLevel::NOTICE, $message, $context);
    }

    /**
     * @param string|\Stringable $message
     */
    public function info($message, array $context = []): void
    {
        $this->log(LogLevel::INFO, $message, $context);
    }

    /**
     * @param string|\Stringable $message
     */
    public function debug($message, array $context = []): void
    {
        $this->log(LogLevel::DEBUG, $message, $context);
    }

    /**
     * @param mixed|LogLevel::* $level
     * @param string|\Stringable $message
     */
    public function log($level, $message, array $context = []): void
    {
        $message = (string) $message;

        if ($context !== []) {
            $json = Silencer::call('json_encode', $context, JSON_INVALID_UTF8_IGNORE|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
            if ($json !== false) {
                $message .= ' ' . $json;
            }
        }

        if (in_array($level, [LogLevel::EMERGENCY, LogLevel::ALERT, LogLevel::CRITICAL, LogLevel::ERROR])) {
            $this->writeError('<error>'.$message.'</error>');
        } elseif ($level === LogLevel::WARNING) {
            $this->writeError('<warning>'.$message.'</warning>');
        } elseif ($level === LogLevel::NOTICE) {
            $this->writeError('<info>'.$message.'</info>', true, self::VERBOSE);
        } elseif ($level === LogLevel::INFO) {
            $this->writeError('<info>'.$message.'</info>', true, self::VERY_VERBOSE);
        } else {
            $this->writeError($message, true, self::DEBUG);
        }
    }
}
