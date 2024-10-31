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
 * @author Till Klampaeckel <till@php.net>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class Svn
{
    private const MAX_QTY_AUTH_TRIES = 5;

    /**
     * @var ?array{username: string, password: string}
     */
    protected $credentials;

    /**
     * @var bool
     */
    protected $hasAuth;

    /**
     * @var \Composer\IO\IOInterface
     */
    protected $io;

    /**
     * @var string
     */
    protected $url;

    /**
     * @var bool
     */
    protected $cacheCredentials = true;

    /**
     * @var ProcessExecutor
     */
    protected $process;

    /**
     * @var int
     */
    protected $qtyAuthTries = 0;

    /**
     * @var \Composer\Config
     */
    protected $config;

    /**
     * @var string|null
     */
    private static $version;

    /**
     * @param ProcessExecutor          $process
     */
    public function __construct(string $url, IOInterface $io, Config $config, ?ProcessExecutor $process = null)
    {
        $this->url = $url;
        $this->io = $io;
        $this->config = $config;
        $this->process = $process ?: new ProcessExecutor($io);
    }

    public static function cleanEnv(): void
    {
        // clean up env for OSX, see https://github.com/composer/composer/issues/2146#issuecomment-35478940
        Platform::clearEnv('DYLD_LIBRARY_PATH');
    }

    /**
     * Execute an SVN remote command and try to fix up the process with credentials
     * if necessary.
     *
     * @param non-empty-list<string> $command SVN command to run
     * @param string  $url     SVN url
     * @param ?string $cwd     Working directory
     * @param ?string $path    Target for a checkout
     * @param bool    $verbose Output all output to the user
     *
     * @throws \RuntimeException
     */
    public function execute(array $command, string $url, ?string $cwd = null, ?string $path = null, bool $verbose = false): string
    {
        // Ensure we are allowed to use this URL by config
        $this->config->prohibitUrlByConfig($url, $this->io);

        return $this->executeWithAuthRetry($command, $cwd, $url, $path, $verbose);
    }

    /**
     * Execute an SVN local command and try to fix up the process with credentials
     * if necessary.
     *
     * @param non-empty-list<string> $command SVN command to run
     * @param string $path    Path argument passed thru to the command
     * @param string $cwd     Working directory
     * @param bool   $verbose Output all output to the user
     *
     * @throws \RuntimeException
     */
    public function executeLocal(array $command, string $path, ?string $cwd = null, bool $verbose = false): string
    {
        // A local command has no remote url
        return $this->executeWithAuthRetry($command, $cwd, '', $path, $verbose);
    }

    /**
     * @param non-empty-list<string> $svnCommand
     */
    private function executeWithAuthRetry(array $svnCommand, ?string $cwd, string $url, ?string $path, bool $verbose): ?string
    {
        // Regenerate the command at each try, to use the newly user-provided credentials
        $command = $this->getCommand($svnCommand, $url, $path);

        $output = null;
        $io = $this->io;
        $handler = static function ($type, $buffer) use (&$output, $io, $verbose) {
            if ($type !== 'out') {
                return null;
            }
            if (strpos($buffer, 'Redirecting to URL ') === 0) {
                return null;
            }
            $output .= $buffer;
            if ($verbose) {
                $io->writeError($buffer, false);
            }
        };
        $status = $this->process->execute($command, $handler, $cwd);
        if (0 === $status) {
            return $output;
        }

        $errorOutput = $this->process->getErrorOutput();
        $fullOutput = trim(implode("\n", [$output, $errorOutput]));

        // the error is not auth-related
        if (false === stripos($fullOutput, 'Could not authenticate to server:')
            && false === stripos($fullOutput, 'authorization failed')
            && false === stripos($fullOutput, 'svn: E170001:')
            && false === stripos($fullOutput, 'svn: E215004:')) {
            throw new \RuntimeException($fullOutput);
        }

        if (!$this->hasAuth()) {
            $this->doAuthDance();
        }

        // try to authenticate if maximum quantity of tries not reached
        if ($this->qtyAuthTries++ < self::MAX_QTY_AUTH_TRIES) {
            // restart the process
            return $this->executeWithAuthRetry($svnCommand, $cwd, $url, $path, $verbose);
        }

        throw new \RuntimeException(
            'wrong credentials provided ('.$fullOutput.')'
        );
    }

    public function setCacheCredentials(bool $cacheCredentials): void
    {
        $this->cacheCredentials = $cacheCredentials;
    }

    /**
     * Repositories requests credentials, let's put them in.
     *
     * @throws \RuntimeException
     * @return \Composer\Util\Svn
     */
    protected function doAuthDance(): Svn
    {
        // cannot ask for credentials in non interactive mode
        if (!$this->io->isInteractive()) {
            throw new \RuntimeException(
                'can not ask for authentication in non interactive mode'
            );
        }

        $this->io->writeError("The Subversion server ({$this->url}) requested credentials:");

        $this->hasAuth = true;
        $this->credentials = [
            'username' => (string) $this->io->ask("Username: ", ''),
            'password' => (string) $this->io->askAndHideAnswer("Password: "),
        ];

        $this->cacheCredentials = $this->io->askConfirmation("Should Subversion cache these credentials? (yes/no) ");

        return $this;
    }

    /**
     * A method to create the svn commands run.
     *
     * @param non-empty-list<string> $cmd  Usually 'svn ls' or something like that.
     * @param string $url  Repo URL.
     * @param string $path Target for a checkout
     *
     * @return non-empty-list<string>
     */
    protected function getCommand(array $cmd, string $url, ?string $path = null): array
    {
        $cmd = array_merge(
            $cmd,
            ['--non-interactive'],
            $this->getCredentialArgs(),
            ['--', $url]
        );

        if ($path !== null) {
            $cmd[] = $path;
        }

        return $cmd;
    }

    /**
     * Return the credential string for the svn command.
     *
     * Adds --no-auth-cache when credentials are present.
     *
     * @return list<string>
     */
    protected function getCredentialArgs(): array
    {
        if (!$this->hasAuth()) {
            return [];
        }

        return array_merge(
            $this->getAuthCacheArgs(),
            ['--username', $this->getUsername(), '--password', $this->getPassword()]
        );
    }

    /**
     * Get the password for the svn command. Can be empty.
     *
     * @throws \LogicException
     */
    protected function getPassword(): string
    {
        if ($this->credentials === null) {
            throw new \LogicException("No svn auth detected.");
        }

        return $this->credentials['password'];
    }

    /**
     * Get the username for the svn command.
     *
     * @throws \LogicException
     */
    protected function getUsername(): string
    {
        if ($this->credentials === null) {
            throw new \LogicException("No svn auth detected.");
        }

        return $this->credentials['username'];
    }

    /**
     * Detect Svn Auth.
     */
    protected function hasAuth(): bool
    {
        if (null !== $this->hasAuth) {
            return $this->hasAuth;
        }

        if (false === $this->createAuthFromConfig()) {
            $this->createAuthFromUrl();
        }

        return (bool) $this->hasAuth;
    }

    /**
     * Return the no-auth-cache switch.
     *
     * @return list<string>
     */
    protected function getAuthCacheArgs(): array
    {
        return $this->cacheCredentials ? [] : ['--no-auth-cache'];
    }

    /**
     * Create the auth params from the configuration file.
     */
    private function createAuthFromConfig(): bool
    {
        if (!$this->config->has('http-basic')) {
            return $this->hasAuth = false;
        }

        $authConfig = $this->config->get('http-basic');

        $host = parse_url($this->url, PHP_URL_HOST);
        if (isset($authConfig[$host])) {
            $this->credentials = [
                'username' => $authConfig[$host]['username'],
                'password' => $authConfig[$host]['password'],
            ];

            return $this->hasAuth = true;
        }

        return $this->hasAuth = false;
    }

    /**
     * Create the auth params from the url
     */
    private function createAuthFromUrl(): bool
    {
        $uri = parse_url($this->url);
        if (empty($uri['user'])) {
            return $this->hasAuth = false;
        }

        $this->credentials = [
            'username' => $uri['user'],
            'password' => !empty($uri['pass']) ? $uri['pass'] : '',
        ];

        return $this->hasAuth = true;
    }

    /**
     * Returns the version of the svn binary contained in PATH
     */
    public function binaryVersion(): ?string
    {
        if (!self::$version) {
            if (0 === $this->process->execute(['svn', '--version'], $output)) {
                if (Preg::isMatch('{(\d+(?:\.\d+)+)}', $output, $match)) {
                    self::$version = $match[1];
                }
            }
        }

        return self::$version;
    }
}
