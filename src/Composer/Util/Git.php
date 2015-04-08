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

use Composer\Config;
use Composer\IO\IOInterface;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class Git
{
    protected $io;
    protected $config;
    protected $process;
    protected $filesystem;

    public function __construct(IOInterface $io, Config $config, ProcessExecutor $process, Filesystem $fs)
    {
        $this->io = $io;
        $this->config = $config;
        $this->process = $process;
        $this->filesystem = $fs;
    }

    public function runCommand($commandCallable, $url, $cwd, $initialClone = false)
    {
        if ($initialClone) {
            $origCwd = $cwd;
            $cwd = null;
        }

        if (preg_match('{^ssh://[^@]+@[^:]+:[^0-9]+}', $url)) {
            throw new \InvalidArgumentException('The source URL '.$url.' is invalid, ssh URLs should have a port number after ":".'."\n".'Use ssh://git@example.com:22/path or just git@example.com:path if you do not want to provide a password or custom port.');
        }

        if (!$initialClone) {
            // capture username/password from URL if there is one
            $this->process->execute('git remote -v', $output, $cwd);
            if (preg_match('{^(?:composer|origin)\s+https?://(.+):(.+)@([^/]+)}im', $output, $match)) {
                $this->io->setAuthentication($match[3], urldecode($match[1]), urldecode($match[2]));
            }
        }

        $protocols = $this->config->get('github-protocols');
        if (!is_array($protocols)) {
            throw new \RuntimeException('Config value "github-protocols" must be an array, got '.gettype($protocols));
        }

        // public github, autoswitch protocols
        if (preg_match('{^(?:https?|git)://'.self::getGitHubDomainsRegex($this->config).'/(.*)}', $url, $match)) {
            $messages = array();
            foreach ($protocols as $protocol) {
                if ('ssh' === $protocol) {
                    $url = "git@" . $match[1] . ":" . $match[2];
                } else {
                    $url = $protocol ."://" . $match[1] . "/" . $match[2];
                }

                if (0 === $this->process->execute(call_user_func($commandCallable, $url), $ignoredOutput, $cwd)) {
                    return;
                }
                $messages[] = '- ' . $url . "\n" . preg_replace('#^#m', '  ', $this->process->getErrorOutput());
                if ($initialClone) {
                    $this->filesystem->removeDirectory($origCwd);
                }
            }

            // failed to checkout, first check git accessibility
            $this->throwException('Failed to clone ' . self::sanitizeUrl($url) .' via '.implode(', ', $protocols).' protocols, aborting.' . "\n\n" . implode("\n", $messages), $url);
        }

        // if we have a private github url and the ssh protocol is disabled then we skip it and directly fallback to https
        $bypassSshForGitHub = preg_match('{^git@'.self::getGitHubDomainsRegex($this->config).':(.+?)\.git$}i', $url) && !in_array('ssh', $protocols, true);

        $command = call_user_func($commandCallable, $url);
        if ($bypassSshForGitHub || 0 !== $this->process->execute($command, $ignoredOutput, $cwd)) {
            // private github repository without git access, try https with auth
            if (preg_match('{^git@'.self::getGitHubDomainsRegex($this->config).':(.+?)\.git$}i', $url, $match)) {
                if (!$this->io->hasAuthentication($match[1])) {
                    $gitHubUtil = new GitHub($this->io, $this->config, $this->process);
                    $message = 'Cloning failed using an ssh key for authentication, enter your GitHub credentials to access private repos';

                    if (!$gitHubUtil->authorizeOAuth($match[1]) && $this->io->isInteractive()) {
                        $gitHubUtil->authorizeOAuthInteractively($match[1], $message);
                    }
                }

                if ($this->io->hasAuthentication($match[1])) {
                    $auth = $this->io->getAuthentication($match[1]);
                    $url = 'https://'.rawurlencode($auth['username']) . ':' . rawurlencode($auth['password']) . '@'.$match[1].'/'.$match[2].'.git';

                    $command = call_user_func($commandCallable, $url);
                    if (0 === $this->process->execute($command, $ignoredOutput, $cwd)) {
                        return;
                    }
                }
            } elseif ( // private non-github repo that failed to authenticate
                $this->isAuthenticationFailure($url, $match)
            ) {
                if (strpos($match[2], '@')) {
                    list($authParts, $match[2]) = explode('@', $match[2], 2);
                }

                $storeAuth = false;
                if ($this->io->hasAuthentication($match[2])) {
                    $auth = $this->io->getAuthentication($match[2]);
                } elseif ($this->io->isInteractive()) {
                    $defaultUsername = null;
                    if (isset($authParts) && $authParts) {
                        if (false !== strpos($authParts, ':')) {
                            list($defaultUsername,) = explode(':', $authParts, 2);
                        } else {
                            $defaultUsername = $authParts;
                        }
                    }

                    $this->io->writeError('    Authentication required (<info>'.parse_url($url, PHP_URL_HOST).'</info>):');
                    $auth = array(
                        'username'  => $this->io->ask('      Username: ', $defaultUsername),
                        'password'  => $this->io->askAndHideAnswer('      Password: '),
                    );
                    $storeAuth = $this->config->get('store-auths');
                }

                if ($auth) {
                    $url = $match[1].rawurlencode($auth['username']).':'.rawurlencode($auth['password']).'@'.$match[2].$match[3];

                    $command = call_user_func($commandCallable, $url);
                    if (0 === $this->process->execute($command, $ignoredOutput, $cwd)) {
                        $this->io->setAuthentication($match[2], $auth['username'], $auth['password']);
                        $authHelper = new AuthHelper($this->io, $this->config);
                        $authHelper->storeAuth($match[2], $storeAuth);

                        return;
                    }
                }
            }

            if ($initialClone) {
                $this->filesystem->removeDirectory($origCwd);
            }
            $this->throwException('Failed to execute ' . self::sanitizeUrl($command) . "\n\n" . $this->process->getErrorOutput(), $url);
        }
    }

    private function isAuthenticationFailure ($url, &$match) {
        if (!preg_match('{(https?://)([^/]+)(.*)$}i', $url, $match)) {
            return false;
        }

        $authFailures = array('fatal: Authentication failed', 'remote error: Invalid username or password.');
        foreach ($authFailures as $authFailure) {
            if (strpos($this->process->getErrorOutput(), $authFailure) !== false) {
                return true;
            }
        }

        return false;
    }

    public static function cleanEnv()
    {
        if (ini_get('safe_mode') && false === strpos(ini_get('safe_mode_allowed_env_vars'), 'GIT_ASKPASS')) {
            throw new \RuntimeException('safe_mode is enabled and safe_mode_allowed_env_vars does not contain GIT_ASKPASS, can not set env var. You can disable safe_mode with "-dsafe_mode=0" when running composer');
        }

        // added in git 1.7.1, prevents prompting the user for username/password
        if (getenv('GIT_ASKPASS') !== 'echo') {
            putenv('GIT_ASKPASS=echo');
            unset($_SERVER['GIT_ASKPASS']);
        }

        // clean up rogue git env vars in case this is running in a git hook
        if (getenv('GIT_DIR')) {
            putenv('GIT_DIR');
            unset($_SERVER['GIT_DIR']);
        }
        if (getenv('GIT_WORK_TREE')) {
            putenv('GIT_WORK_TREE');
            unset($_SERVER['GIT_WORK_TREE']);
        }

        // Run processes with predictable LANGUAGE
        if (getenv('LANGUAGE') !== 'C') {
            putenv('LANGUAGE=C');
        }

        // clean up env for OSX, see https://github.com/composer/composer/issues/2146#issuecomment-35478940
        putenv("DYLD_LIBRARY_PATH");
        unset($_SERVER['DYLD_LIBRARY_PATH']);
    }

    public static function getGitHubDomainsRegex(Config $config)
    {
        return '('.implode('|', array_map('preg_quote', $config->get('github-domains'))).')';
    }

    public static function sanitizeUrl($message)
    {
        return preg_replace('{://([^@]+?):.+?@}', '://$1:***@', $message);
    }

    private function throwException($message, $url)
    {
        if (0 !== $this->process->execute('git --version', $ignoredOutput)) {
            throw new \RuntimeException('Failed to clone '.self::sanitizeUrl($url).', git was not found, check that it is installed and in your PATH env.' . "\n\n" . $this->process->getErrorOutput());
        }

        throw new \RuntimeException($message);
    }
}
