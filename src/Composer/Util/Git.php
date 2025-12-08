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
use Composer\Pcre\Preg;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class Git
{
    /** @var string|false|null */
    private static $version = false;

    /** @var IOInterface */
    protected $io;
    /** @var Config */
    protected $config;
    /** @var ProcessExecutor */
    protected $process;
    /** @var Filesystem */
    protected $filesystem;
    /** @var HttpDownloader */
    protected $httpDownloader;

    public function __construct(IOInterface $io, Config $config, ProcessExecutor $process, Filesystem $fs)
    {
        $this->io = $io;
        $this->config = $config;
        $this->process = $process;
        $this->filesystem = $fs;
    }

    /**
     * @param IOInterface|null $io If present, a warning is output there instead of throwing, so pass this in only for cases where this is a soft failure
     */
    public static function checkForRepoOwnershipError(string $output, string $path, ?IOInterface $io = null): void
    {
        if (str_contains($output, 'fatal: detected dubious ownership')) {
            $msg = 'The repository at "' . $path . '" does not have the correct ownership and git refuses to use it:' . PHP_EOL . PHP_EOL . $output;
            if ($io === null) {
                throw new \RuntimeException($msg);
            }
            $io->writeError('<warning>'.$msg.'</warning>');
        }
    }

    public function setHttpDownloader(HttpDownloader $httpDownloader): void
    {
        $this->httpDownloader = $httpDownloader;
    }

    /**
     * Runs a set of commands using the $url or a variation of it (with auth, ssh, ..)
     *
     * Commands should use %url% placeholders for the URL instead of inlining it to allow this function to do its job
     * %sanitizedUrl% is also automatically replaced by the url without user/pass
     *
     * As soon as a single command fails it will halt, so assume the commands are run as && in bash
     *
     * @param non-empty-array<non-empty-list<string>> $commands
     * @param mixed $commandOutput  the output will be written into this var if passed by ref
     *                              if a callable is passed it will be used as output handler
     */
    public function runCommands(array $commands, string $url, ?string $cwd, bool $initialClone = false, &$commandOutput = null): void
    {
        $callables = [];
        foreach ($commands as $cmd) {
            $callables[] = static function (string $url) use ($cmd): array {
                $map = [
                    '%url%' => $url,
                    '%sanitizedUrl%' => Preg::replace('{://([^@]+?):(.+?)@}', '://', $url),
                ];

                return array_map(static function ($value) use ($map): string {
                    return $map[$value] ?? $value;
                }, $cmd);
            };
        }

        // @phpstan-ignore method.deprecated
        $this->runCommand($callables, $url, $cwd, $initialClone, $commandOutput);
    }

    /**
     * @param callable|array<callable> $commandCallable
     * @param mixed       $commandOutput  the output will be written into this var if passed by ref
     *                                    if a callable is passed it will be used as output handler
     * @deprecated Use runCommands with placeholders instead of callbacks for simplicity
     */
    public function runCommand($commandCallable, string $url, ?string $cwd, bool $initialClone = false, &$commandOutput = null): void
    {
        $commandCallables = is_callable($commandCallable) ? [$commandCallable] : $commandCallable;
        $lastCommand = '';

        // Ensure we are allowed to use this URL by config
        $this->config->prohibitUrlByConfig($url, $this->io);

        if ($initialClone) {
            $origCwd = $cwd;
        }

        $runCommands = function ($url) use ($commandCallables, $cwd, &$commandOutput, &$lastCommand, $initialClone) {
            $collectOutputs = !is_callable($commandOutput);
            $outputs = [];

            $status = 0;
            $counter = 0;
            foreach ($commandCallables as $callable) {
                $lastCommand = $callable($url);
                if ($collectOutputs) {
                    $outputs[] = '';
                    $output = &$outputs[count($outputs) - 1];
                } else {
                    $output = &$commandOutput;
                }
                $status = $this->process->execute($lastCommand, $output, $initialClone && $counter === 0 ? null : $cwd);
                if ($status !== 0) {
                    break;
                }
                $counter++;
            }

            if ($collectOutputs) {
                $commandOutput = implode('', $outputs);
            }

            return $status;
        };

        if (Preg::isMatch('{^ssh://[^@]+@[^:]+:[^0-9]+}', $url)) {
            throw new \InvalidArgumentException('The source URL ' . $url . ' is invalid, ssh URLs should have a port number after ":".' . "\n" . 'Use ssh://git@example.com:22/path or just git@example.com:path if you do not want to provide a password or custom port.');
        }

        if (!$initialClone) {
            // capture username/password from URL if there is one and we have no auth configured yet
            $this->process->execute(['git', 'remote', '-v'], $output, $cwd);
            if (Preg::isMatchStrictGroups('{^(?:composer|origin)\s+https?://(.+):(.+)@([^/]+)}im', $output, $match) && !$this->io->hasAuthentication($match[3])) {
                $this->io->setAuthentication($match[3], rawurldecode($match[1]), rawurldecode($match[2]));
            }
        }

        $protocols = $this->config->get('github-protocols');
        // public github, autoswitch protocols
        // @phpstan-ignore composerPcre.maybeUnsafeStrictGroups
        if (Preg::isMatchStrictGroups('{^(?:https?|git)://' . self::getGitHubDomainsRegex($this->config) . '/(.*)}', $url, $match)) {
            $messages = [];
            foreach ($protocols as $protocol) {
                if ('ssh' === $protocol) {
                    $protoUrl = "git@" . $match[1] . ":" . $match[2];
                } else {
                    $protoUrl = $protocol . "://" . $match[1] . "/" . $match[2];
                }

                if (0 === $runCommands($protoUrl)) {
                    return;
                }
                $messages[] = '- ' . $protoUrl . "\n" . Preg::replace('#^#m', '  ', $this->process->getErrorOutput());

                if ($initialClone && isset($origCwd)) {
                    $this->filesystem->removeDirectory($origCwd);
                }
            }

            // failed to checkout, first check git accessibility
            if (!$this->io->hasAuthentication($match[1]) && !$this->io->isInteractive()) {
                $this->throwException('Failed to clone ' . $url . ' via ' . implode(', ', $protocols) . ' protocols, aborting.' . "\n\n" . implode("\n", $messages), $url);
            }
        }

        // if we have a private github url and the ssh protocol is disabled then we skip it and directly fallback to https
        $bypassSshForGitHub = Preg::isMatch('{^git@' . self::getGitHubDomainsRegex($this->config) . ':(.+?)\.git$}i', $url) && !in_array('ssh', $protocols, true);

        $auth = null;
        $credentials = [];
        if ($bypassSshForGitHub || 0 !== $runCommands($url)) {
            $errorMsg = $this->process->getErrorOutput();
            // private github repository without ssh key access, try https with auth
            // @phpstan-ignore composerPcre.maybeUnsafeStrictGroups
            if (Preg::isMatchStrictGroups('{^git@' . self::getGitHubDomainsRegex($this->config) . ':(.+?)\.git$}i', $url, $match)
                // @phpstan-ignore composerPcre.maybeUnsafeStrictGroups
                || Preg::isMatchStrictGroups('{^https?://' . self::getGitHubDomainsRegex($this->config) . '/(.*?)(?:\.git)?$}i', $url, $match)
            ) {
                if (!$this->io->hasAuthentication($match[1])) {
                    $gitHubUtil = new GitHub($this->io, $this->config, $this->process);
                    $message = 'Cloning failed using an ssh key for authentication, enter your GitHub credentials to access private repos';

                    if (!$gitHubUtil->authorizeOAuth($match[1]) && $this->io->isInteractive()) {
                        $gitHubUtil->authorizeOAuthInteractively($match[1], $message);
                    }
                }

                if ($this->io->hasAuthentication($match[1])) {
                    $auth = $this->io->getAuthentication($match[1]);
                    $authUrl = 'https://' . rawurlencode($auth['username']) . ':' . rawurlencode($auth['password']) . '@' . $match[1] . '/' . $match[2] . '.git';
                    if (0 === $runCommands($authUrl)) {
                        return;
                    }

                    $credentials = [rawurlencode($auth['username']), rawurlencode($auth['password'])];
                    $errorMsg = $this->process->getErrorOutput();
                }
            // @phpstan-ignore composerPcre.maybeUnsafeStrictGroups
            } elseif (
                Preg::isMatchStrictGroups('{^(https?)://(bitbucket\.org)/(.*?)(?:\.git)?$}i', $url, $match)
                || Preg::isMatchStrictGroups('{^(git)@(bitbucket\.org):(.+?\.git)$}i', $url, $match)
            ) { //bitbucket either through oauth or app password, with fallback to ssh.
                $bitbucketUtil = new Bitbucket($this->io, $this->config, $this->process, $this->httpDownloader);

                $domain = $match[2];
                $repo_with_git_part = $match[3];
                if (!str_ends_with($repo_with_git_part, '.git')) {
                    $repo_with_git_part .= '.git';
                }
                if (!$this->io->hasAuthentication($domain)) {
                    $message = 'Enter your Bitbucket credentials to access private repos';

                    if (!$bitbucketUtil->authorizeOAuth($domain) && $this->io->isInteractive()) {
                        $bitbucketUtil->authorizeOAuthInteractively($domain, $message);
                        $accessToken = $bitbucketUtil->getToken();
                        $this->io->setAuthentication($domain, 'x-token-auth', $accessToken);
                    }
                }

                // First we try to authenticate with whatever we have stored.
                // This will be successful if there is for example an app
                // password in there.
                if ($this->io->hasAuthentication($domain)) {
                    $auth = $this->io->getAuthentication($domain);

                    // Bitbucket API tokens use the email address as the username for HTTP API calls and
                    // either the Bitbucket username or 'x-bitbucket-api-token-auth' as the username for git operations.
                    if (strpos((string) $auth['password'], 'ATAT') === 0) {
                        $auth['username'] = 'x-bitbucket-api-token-auth';
                    }

                    $authUrl = 'https://' . rawurlencode($auth['username']) . ':' . rawurlencode($auth['password']) . '@' . $domain . '/' . $repo_with_git_part;

                    if (0 === $runCommands($authUrl)) {
                        // Well if that succeeded on our first try, let's just
                        // take the win.
                        return;
                    }

                    //We already have an access_token from a previous request.
                    if ($auth['username'] !== 'x-token-auth') {
                        $accessToken = $bitbucketUtil->requestToken($domain, $auth['username'], $auth['password']);
                        if (!empty($accessToken)) {
                            $this->io->setAuthentication($domain, 'x-token-auth', $accessToken);
                        }
                    }
                }

                if ($this->io->hasAuthentication($domain)) {
                    $auth = $this->io->getAuthentication($domain);
                    $authUrl = 'https://' . rawurlencode($auth['username']) . ':' . rawurlencode($auth['password']) . '@' . $domain . '/' . $repo_with_git_part;
                    if (0 === $runCommands($authUrl)) {
                        return;
                    }

                    $credentials = [rawurlencode($auth['username']), rawurlencode($auth['password'])];
                }
                //Falling back to ssh
                $sshUrl = 'git@bitbucket.org:' . $repo_with_git_part;
                $this->io->writeError('    No bitbucket authentication configured. Falling back to ssh.');
                if (0 === $runCommands($sshUrl)) {
                    return;
                }

                $errorMsg = $this->process->getErrorOutput();
            } elseif (
                // @phpstan-ignore composerPcre.maybeUnsafeStrictGroups
                Preg::isMatchStrictGroups('{^(git)@' . self::getGitLabDomainsRegex($this->config) . ':(.+?\.git)$}i', $url, $match)
                // @phpstan-ignore composerPcre.maybeUnsafeStrictGroups
                || Preg::isMatchStrictGroups('{^(https?)://' . self::getGitLabDomainsRegex($this->config) . '/(.*)}i', $url, $match)
            ) {
                if ($match[1] === 'git') {
                    $match[1] = 'https';
                }

                if (!$this->io->hasAuthentication($match[2])) {
                    $gitLabUtil = new GitLab($this->io, $this->config, $this->process);
                    $message = 'Cloning failed, enter your GitLab credentials to access private repos';

                    if (!$gitLabUtil->authorizeOAuth($match[2]) && $this->io->isInteractive()) {
                        $gitLabUtil->authorizeOAuthInteractively($match[1], $match[2], $message);
                    }
                }

                if ($this->io->hasAuthentication($match[2])) {
                    $auth = $this->io->getAuthentication($match[2]);
                    if ($auth['password'] === 'private-token' || $auth['password'] === 'oauth2' || $auth['password'] === 'gitlab-ci-token') {
                        $authUrl = $match[1] . '://' . rawurlencode($auth['password']) . ':' . rawurlencode((string) $auth['username']) . '@' . $match[2] . '/' . $match[3]; // swap username and password
                    } else {
                        $authUrl = $match[1] . '://' . rawurlencode((string) $auth['username']) . ':' . rawurlencode((string) $auth['password']) . '@' . $match[2] . '/' . $match[3];
                    }

                    if (0 === $runCommands($authUrl)) {
                        return;
                    }

                    $credentials = [rawurlencode((string) $auth['username']), rawurlencode((string) $auth['password'])];
                    $errorMsg = $this->process->getErrorOutput();
                }
            } elseif (null !== ($match = $this->getAuthenticationFailure($url))) { // private non-github/gitlab/bitbucket repo that failed to authenticate
                if (str_contains($match[2], '@')) {
                    [$authParts, $match[2]] = explode('@', $match[2], 2);
                }

                $storeAuth = false;
                if ($this->io->hasAuthentication($match[2])) {
                    $auth = $this->io->getAuthentication($match[2]);
                } elseif ($this->io->isInteractive()) {
                    $defaultUsername = null;
                    if (isset($authParts) && $authParts !== '') {
                        if (str_contains($authParts, ':')) {
                            [$defaultUsername] = explode(':', $authParts, 2);
                        } else {
                            $defaultUsername = $authParts;
                        }
                    }

                    $this->io->writeError('    Authentication required (<info>' . $match[2] . '</info>):');
                    $this->io->writeError('<warning>' . trim($errorMsg) . '</warning>', true, IOInterface::VERBOSE);
                    $auth = [
                        'username' => $this->io->ask('      Username: ', $defaultUsername),
                        'password' => $this->io->askAndHideAnswer('      Password: '),
                    ];
                    $storeAuth = $this->config->get('store-auths');
                }

                if (null !== $auth) {
                    $authUrl = $match[1] . rawurlencode((string) $auth['username']) . ':' . rawurlencode((string) $auth['password']) . '@' . $match[2] . $match[3];

                    if (0 === $runCommands($authUrl)) {
                        $this->io->setAuthentication($match[2], $auth['username'], $auth['password']);
                        $authHelper = new AuthHelper($this->io, $this->config);
                        $authHelper->storeAuth($match[2], $storeAuth);

                        return;
                    }

                    $credentials = [rawurlencode((string) $auth['username']), rawurlencode((string) $auth['password'])];
                    $errorMsg = $this->process->getErrorOutput();
                }
            }

            if ($initialClone && isset($origCwd)) {
                $this->filesystem->removeDirectory($origCwd);
            }

            if (\is_array($lastCommand)) {
                $lastCommand = implode(' ', $lastCommand);
            }
            if (count($credentials) > 0) {
                $lastCommand = $this->maskCredentials($lastCommand, $credentials);
                $errorMsg = $this->maskCredentials($errorMsg, $credentials);
            }
            $this->throwException('Failed to execute ' . $lastCommand . "\n\n" . $errorMsg, $url);
        }
    }

    public function syncMirror(string $url, string $dir): bool
    {
        if ((bool) Platform::getEnv('COMPOSER_DISABLE_NETWORK') && Platform::getEnv('COMPOSER_DISABLE_NETWORK') !== 'prime') {
            $this->io->writeError('<warning>Aborting git mirror sync of '.$url.' as network is disabled</warning>');

            return false;
        }

        // update the repo if it is a valid git repository
        if (is_dir($dir) && 0 === $this->process->execute(['git', 'rev-parse', '--git-dir'], $output, $dir) && trim($output) === '.') {
            try {
                $commands = [
                    ['git', 'remote', 'set-url', 'origin', '--', '%url%'],
                    ['git', 'remote', 'update', '--prune', 'origin'],
                    ['git', 'remote', 'set-url', 'origin', '--', '%sanitizedUrl%'],
                    ['git', 'gc', '--auto'],
                ];

                $this->runCommands($commands, $url, $dir);
            } catch (\Exception $e) {
                $this->io->writeError('<error>Sync mirror failed: ' . $e->getMessage() . '</error>', true, IOInterface::DEBUG);

                return false;
            }

            return true;
        }
        self::checkForRepoOwnershipError($this->process->getErrorOutput(), $dir);

        // clean up directory and do a fresh clone into it
        $this->filesystem->removeDirectory($dir);

        $this->runCommands([['git', 'clone', '--mirror', '--', '%url%', $dir]], $url, $dir, true);

        return true;
    }

    public function fetchRefOrSyncMirror(string $url, string $dir, string $ref, ?string $prettyVersion = null): bool
    {
        if ($this->checkRefIsInMirror($dir, $ref)) {
            if (Preg::isMatch('{^[a-f0-9]{40}$}', $ref) && $prettyVersion !== null) {
                $branch = Preg::replace('{(?:^dev-|(?:\.x)?-dev$)}i', '', $prettyVersion);
                $branches = null;
                $tags = null;
                if (0 === $this->process->execute(['git', 'branch'], $output, $dir)) {
                    $branches = $output;
                }
                if (0 === $this->process->execute(['git', 'tag'], $output, $dir)) {
                    $tags = $output;
                }

                // if the pretty version cannot be found as a branch (nor branch with 'v' in front of the branch as it may have been stripped when generating pretty name),
                // nor as a tag, then we sync the mirror as otherwise it will likely fail during install.
                // this can occur if a git tag gets created *after* the reference is already put into the cache, as the ref check above will then not sync the new tags
                // see https://github.com/composer/composer/discussions/11002
                if (null !== $branches && !Preg::isMatch('{^[\s*]*v?'.preg_quote($branch).'$}m', $branches)
                    && null !== $tags && !Preg::isMatch('{^[\s*]*'.preg_quote($branch).'$}m', $tags)
                ) {
                    $this->syncMirror($url, $dir);
                }
            }

            return true;
        }

        if ($this->syncMirror($url, $dir)) {
            return $this->checkRefIsInMirror($dir, $ref);
        }

        return false;
    }

    public static function getNoShowSignatureFlag(ProcessExecutor $process): string
    {
        $gitVersion = self::getVersion($process);
        if ($gitVersion !== null && version_compare($gitVersion, '2.10.0-rc0', '>=')) {
            return ' --no-show-signature';
        }

        return '';
    }

    /**
     * @return list<string>
     */
    public static function getNoShowSignatureFlags(ProcessExecutor $process): array
    {
        $flags = static::getNoShowSignatureFlag($process);
        if ('' === $flags) {
            return [];
        }

        return explode(' ', substr($flags, 1));
    }

    private function checkRefIsInMirror(string $dir, string $ref): bool
    {
        if (is_dir($dir) && 0 === $this->process->execute(['git', 'rev-parse', '--git-dir'], $output, $dir) && trim($output) === '.') {
            $exitCode = $this->process->execute(['git', 'rev-parse', '--quiet', '--verify', $ref.'^{commit}'], $ignoredOutput, $dir);
            if ($exitCode === 0) {
                return true;
            }
        }
        self::checkForRepoOwnershipError($this->process->getErrorOutput(), $dir);

        return false;
    }

    /**
     * @return array<int, string>|null
     */
    private function getAuthenticationFailure(string $url): ?array
    {
        if (!Preg::isMatchStrictGroups('{^(https?://)([^/]+)(.*)$}i', $url, $match)) {
            return null;
        }

        $authFailures = [
            'fatal: Authentication failed',
            'remote error: Invalid username or password.',
            'error: 401 Unauthorized',
            'fatal: unable to access',
            'fatal: could not read Username',
        ];

        $errorOutput = $this->process->getErrorOutput();
        foreach ($authFailures as $authFailure) {
            if (strpos($errorOutput, $authFailure) !== false) {
                return $match;
            }
        }

        return null;
    }

    public function getMirrorDefaultBranch(string $url, string $dir, bool $isLocalPathRepository): ?string
    {
        if ((bool) Platform::getEnv('COMPOSER_DISABLE_NETWORK')) {
            return null;
        }

        try {
            if ($isLocalPathRepository) {
                $this->process->execute(['git', 'remote', 'show', 'origin'], $output, $dir);
            } else {
                $commands = [
                    ['git', 'remote', 'set-url', 'origin', '--', '%url%'],
                    ['git', 'remote', 'show', 'origin'],
                    ['git', 'remote', 'set-url', 'origin', '--', '%sanitizedUrl%'],
                ];

                $this->runCommands($commands, $url, $dir, false, $output);
            }

            $lines = $this->process->splitLines($output);
            foreach ($lines as $line) {
                if (Preg::isMatch('{^\s*HEAD branch:\s(.+)\s*$}m', $line, $matches)) {
                    return $matches[1];
                }
            }
        } catch (\Exception $e) {
            $this->io->writeError('<error>Failed to fetch root identifier from remote: ' . $e->getMessage() . '</error>', true, IOInterface::DEBUG);
        }

        return null;
    }

    public static function cleanEnv(): void
    {
        $gitVersion = self::getVersion(new ProcessExecutor());
        if ($gitVersion !== null && version_compare($gitVersion, '2.3.0', '>=')) {
            // added in git 2.3.0, prevents prompting the user for username/password
            if (Platform::getEnv('GIT_TERMINAL_PROMPT') !== '0') {
                Platform::putEnv('GIT_TERMINAL_PROMPT', '0');
            }
        } else {
            // added in git 1.7.1, prevents prompting the user for username/password
            if (Platform::getEnv('GIT_ASKPASS') !== 'echo') {
                Platform::putEnv('GIT_ASKPASS', 'echo');
            }
        }

        // clean up rogue git env vars in case this is running in a git hook
        if (Platform::getEnv('GIT_DIR')) {
            Platform::clearEnv('GIT_DIR');
        }
        if (Platform::getEnv('GIT_WORK_TREE')) {
            Platform::clearEnv('GIT_WORK_TREE');
        }

        // Run processes with predictable LANGUAGE
        if (Platform::getEnv('LANGUAGE') !== 'C') {
            Platform::putEnv('LANGUAGE', 'C');
        }

        // clean up env for OSX, see https://github.com/composer/composer/issues/2146#issuecomment-35478940
        Platform::clearEnv('DYLD_LIBRARY_PATH');
    }

    /**
     * @return non-empty-string
     */
    public static function getGitHubDomainsRegex(Config $config): string
    {
        return '(' . implode('|', array_map('preg_quote', $config->get('github-domains'))) . ')';
    }

    /**
     * @return non-empty-string
     */
    public static function getGitLabDomainsRegex(Config $config): string
    {
        return '(' . implode('|', array_map('preg_quote', $config->get('gitlab-domains'))) . ')';
    }

    /**
     * @param non-empty-string $message
     *
     * @return never
     */
    private function throwException($message, string $url): void
    {
        // git might delete a directory when it fails and php will not know
        clearstatcache();

        if (0 !== $this->process->execute(['git', '--version'], $ignoredOutput)) {
            throw new \RuntimeException(Url::sanitize('Failed to clone ' . $url . ', git was not found, check that it is installed and in your PATH env.' . "\n\n" . $this->process->getErrorOutput()));
        }

        throw new \RuntimeException(Url::sanitize($message));
    }

    /**
     * Retrieves the current git version.
     *
     * @return string|null The git version number, if present.
     */
    public static function getVersion(ProcessExecutor $process): ?string
    {
        if (false === self::$version) {
            self::$version = null;
            if (0 === $process->execute(['git', '--version'], $output) && Preg::isMatch('/^git version (\d+(?:\.\d+)+)/m', $output, $matches)) {
                self::$version = $matches[1];
            }
        }

        return self::$version;
    }

    /**
     * @param string[] $credentials
     */
    private function maskCredentials(string $error, array $credentials): string
    {
        $maskedCredentials = [];

        foreach ($credentials as $credential) {
            if (in_array($credential, ['private-token', 'x-token-auth', 'oauth2', 'gitlab-ci-token', 'x-oauth-basic'])) {
                $maskedCredentials[] = $credential;
            } elseif (strlen($credential) > 6) {
                $maskedCredentials[] = substr($credential, 0, 3) . '...' . substr($credential, -3);
            } elseif (strlen($credential) > 3) {
                $maskedCredentials[] = substr($credential, 0, 3) . '...';
            } else {
                $maskedCredentials[] = 'XXX';
            }
        }

        return str_replace($credentials, $maskedCredentials, $error);
    }
}
