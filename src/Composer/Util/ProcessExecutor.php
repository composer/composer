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
use Composer\Pcre\Preg;
use Seld\Signal\SignalHandler;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\RuntimeException;
use React\Promise\Promise;
use React\Promise\PromiseInterface;
use Symfony\Component\Process\ExecutableFinder;

/**
 * @author Robert Schönthal <seroscho@googlemail.com>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class ProcessExecutor
{
    private const STATUS_QUEUED = 1;
    private const STATUS_STARTED = 2;
    private const STATUS_COMPLETED = 3;
    private const STATUS_FAILED = 4;
    private const STATUS_ABORTED = 5;

    private const BUILTIN_CMD_COMMANDS = [
        'assoc', 'break', 'call', 'cd', 'chdir', 'cls', 'color', 'copy', 'date',
        'del', 'dir', 'echo', 'endlocal', 'erase', 'exit', 'for', 'ftype', 'goto',
        'help', 'if', 'label', 'md', 'mkdir', 'mklink', 'move', 'path', 'pause',
        'popd', 'prompt', 'pushd', 'rd', 'rem', 'ren', 'rename', 'rmdir', 'set',
        'setlocal', 'shift', 'start', 'time', 'title', 'type', 'ver', 'vol',
    ];

    private const GIT_CMDS_NEED_GIT_DIR = [
        ['show'],
        ['log'],
        ['branch'],
        ['remote', 'set-url']
    ];

    /** @var int */
    protected static $timeout = 300;

    /** @var bool */
    protected $captureOutput = false;
    /** @var string */
    protected $errorOutput = '';
    /** @var ?IOInterface */
    protected $io;

    /**
     * @phpstan-var array<int, array<string, mixed>>
     */
    private $jobs = [];
    /** @var int */
    private $runningJobs = 0;
    /** @var int */
    private $maxJobs = 10;
    /** @var int */
    private $idGen = 0;
    /** @var bool */
    private $allowAsync = false;

    /** @var array<string, string> */
    private static $executables = [];

    public function __construct(?IOInterface $io = null)
    {
        $this->io = $io;
    }

    /**
     * runs a process on the commandline
     *
     * @param  string|non-empty-list<string> $command the command to execute
     * @param  mixed   $output  the output will be written into this var if passed by ref
     *                          if a callable is passed it will be used as output handler
     * @param  null|string $cwd     the working directory
     * @return int     statuscode
     */
    public function execute($command, &$output = null, ?string $cwd = null): int
    {
        if (func_num_args() > 1) {
            return $this->doExecute($command, $cwd, false, $output);
        }

        return $this->doExecute($command, $cwd, false);
    }

    /**
     * runs a process on the commandline in TTY mode
     *
     * @param  string|non-empty-list<string>  $command the command to execute
     * @param  null|string $cwd     the working directory
     * @return int     statuscode
     */
    public function executeTty($command, ?string $cwd = null): int
    {
        if (Platform::isTty()) {
            return $this->doExecute($command, $cwd, true);
        }

        return $this->doExecute($command, $cwd, false);
    }

    /**
     * @param  string|non-empty-list<string> $command
     * @param  array<string, string>|null $env
     * @param  mixed   $output
     */
    private function runProcess($command, ?string $cwd, ?array $env, bool $tty, &$output = null): ?int
    {
        // On Windows, we don't rely on the OS to find the executable if possible to avoid lookups
        // in the current directory which could be untrusted. Instead we use the ExecutableFinder.

        if (is_string($command)) {
            if (Platform::isWindows() && Preg::isMatch('{^([^:/\\\\]++) }', $command, $match)) {
                $command = substr_replace($command, self::escape(self::getExecutable($match[1])), 0, strlen($match[1]));
            }

            $process = Process::fromShellCommandline($command, $cwd, $env, null, static::getTimeout());
        } else {
            if (Platform::isWindows() && \strlen($command[0]) === strcspn($command[0], ':/\\')) {
                $command[0] = self::getExecutable($command[0]);
            }

            $process = new Process($command, $cwd, $env, null, static::getTimeout());
        }

        if (! Platform::isWindows() && $tty) {
            try {
                $process->setTty(true);
            } catch (RuntimeException $e) {
                // ignore TTY enabling errors
            }
        }

        $callback = is_callable($output) ? $output : function (string $type, string $buffer): void {
            $this->outputHandler($type, $buffer);
        };

        $signalHandler = SignalHandler::create(
            [SignalHandler::SIGINT, SignalHandler::SIGTERM, SignalHandler::SIGHUP],
            function (string $signal) {
                if ($this->io !== null) {
                    $this->io->writeError(
                        'Received '.$signal.', aborting when child process is done',
                        true,
                        IOInterface::DEBUG
                    );
                }
            }
        );

        try {
            $process->run($callback);

            if ($this->captureOutput && !is_callable($output)) {
                $output = $process->getOutput();
            }

            $this->errorOutput = $process->getErrorOutput();
        } catch (ProcessSignaledException $e) {
            if ($signalHandler->isTriggered()) {
                // exiting as we were signaled and the child process exited too due to the signal
                $signalHandler->exitWithLastSignal();
            }
        } finally {
            $signalHandler->unregister();
        }

        return $process->getExitCode();
    }

    /**
     * @param  string|non-empty-list<string> $command
     * @param  mixed   $output
     */
    private function doExecute($command, ?string $cwd, bool $tty, &$output = null): int
    {
        $this->outputCommandRun($command, $cwd, false);

        $this->captureOutput = func_num_args() > 3;
        $this->errorOutput = '';

        $env = null;

        $requiresGitDirEnv = $this->requiresGitDirEnv($command);
        if ($cwd !== null && $requiresGitDirEnv) {
            $isBareRepository = !is_dir(sprintf('%s/.git', rtrim($cwd, '/')));
            if ($isBareRepository) {
                $configValue = '';
                $this->runProcess(['git', 'config', 'safe.bareRepository'], $cwd, ['GIT_DIR' => $cwd], $tty, $configValue);
                $configValue = trim($configValue);
                if ($configValue === 'explicit') {
                    $env = ['GIT_DIR' => $cwd];
                }
            }
        }

        return $this->runProcess($command, $cwd, $env, $tty, $output);
    }

    /**
     * starts a process on the commandline in async mode
     *
     * @param  string|list<string> $command the command to execute
     * @param  string              $cwd     the working directory
     * @phpstan-return PromiseInterface<Process>
     */
    public function executeAsync($command, ?string $cwd = null): PromiseInterface
    {
        if (!$this->allowAsync) {
            throw new \LogicException('You must use the ProcessExecutor instance which is part of a Composer\Loop instance to be able to run async processes');
        }

        $job = [
            'id' => $this->idGen++,
            'status' => self::STATUS_QUEUED,
            'command' => $command,
            'cwd' => $cwd,
        ];

        $resolver = static function ($resolve, $reject) use (&$job): void {
            $job['status'] = ProcessExecutor::STATUS_QUEUED;
            $job['resolve'] = $resolve;
            $job['reject'] = $reject;
        };

        $canceler = static function () use (&$job): void {
            if ($job['status'] === ProcessExecutor::STATUS_QUEUED) {
                $job['status'] = ProcessExecutor::STATUS_ABORTED;
            }
            if ($job['status'] !== ProcessExecutor::STATUS_STARTED) {
                return;
            }
            $job['status'] = ProcessExecutor::STATUS_ABORTED;
            try {
                if (defined('SIGINT')) {
                    $job['process']->signal(SIGINT);
                }
            } catch (\Exception $e) {
                // signal can throw in various conditions, but we don't care if it fails
            }
            $job['process']->stop(1);

            throw new \RuntimeException('Aborted process');
        };

        $promise = new Promise($resolver, $canceler);
        $promise = $promise->then(function () use (&$job) {
            if ($job['process']->isSuccessful()) {
                $job['status'] = ProcessExecutor::STATUS_COMPLETED;
            } else {
                $job['status'] = ProcessExecutor::STATUS_FAILED;
            }

            $this->markJobDone();

            return $job['process'];
        }, function ($e) use (&$job): void {
            $job['status'] = ProcessExecutor::STATUS_FAILED;

            $this->markJobDone();

            throw $e;
        });
        $this->jobs[$job['id']] = &$job;

        if ($this->runningJobs < $this->maxJobs) {
            $this->startJob($job['id']);
        }

        return $promise;
    }

    protected function outputHandler(string $type, string $buffer): void
    {
        if ($this->captureOutput) {
            return;
        }

        if (null === $this->io) {
            echo $buffer;

            return;
        }

        if (Process::ERR === $type) {
            $this->io->writeErrorRaw($buffer, false);
        } else {
            $this->io->writeRaw($buffer, false);
        }
    }

    private function startJob(int $id): void
    {
        $job = &$this->jobs[$id];
        if ($job['status'] !== self::STATUS_QUEUED) {
            return;
        }

        // start job
        $job['status'] = self::STATUS_STARTED;
        $this->runningJobs++;

        $command = $job['command'];
        $cwd = $job['cwd'];

        $this->outputCommandRun($command, $cwd, true);

        try {
            if (is_string($command)) {
                $process = Process::fromShellCommandline($command, $cwd, null, null, static::getTimeout());
            } else {
                $process = new Process($command, $cwd, null, null, static::getTimeout());
            }
        } catch (\Throwable $e) {
            $job['reject']($e);

            return;
        }

        $job['process'] = $process;

        try {
            $process->start();
        } catch (\Throwable $e) {
            $job['reject']($e);

            return;
        }
    }

    public function setMaxJobs(int $maxJobs): void
    {
        $this->maxJobs = $maxJobs;
    }

    public function resetMaxJobs(): void
    {
        $this->maxJobs = 10;
    }

    /**
     * @param  ?int $index job id
     */
    public function wait($index = null): void
    {
        while (true) {
            if (0 === $this->countActiveJobs($index)) {
                return;
            }

            usleep(1000);
        }
    }

    /**
     * @internal
     */
    public function enableAsync(): void
    {
        $this->allowAsync = true;
    }

    /**
     * @internal
     *
     * @param  ?int $index job id
     * @return int         number of active (queued or started) jobs
     */
    public function countActiveJobs($index = null): int
    {
        // tick
        foreach ($this->jobs as $job) {
            if ($job['status'] === self::STATUS_STARTED) {
                if (!$job['process']->isRunning()) {
                    call_user_func($job['resolve'], $job['process']);
                }

                $job['process']->checkTimeout();
            }

            if ($this->runningJobs < $this->maxJobs) {
                if ($job['status'] === self::STATUS_QUEUED) {
                    $this->startJob($job['id']);
                }
            }
        }

        if (null !== $index) {
            return $this->jobs[$index]['status'] < self::STATUS_COMPLETED ? 1 : 0;
        }

        $active = 0;
        foreach ($this->jobs as $job) {
            if ($job['status'] < self::STATUS_COMPLETED) {
                $active++;
            } else {
                unset($this->jobs[$job['id']]);
            }
        }

        return $active;
    }

    private function markJobDone(): void
    {
        $this->runningJobs--;
    }

    /**
     * @return string[]
     */
    public function splitLines(?string $output): array
    {
        $output = trim((string) $output);

        return $output === '' ? [] : Preg::split('{\r?\n}', $output);
    }

    /**
     * Get any error output from the last command
     */
    public function getErrorOutput(): string
    {
        return $this->errorOutput;
    }

    /**
     * @return int the timeout in seconds
     */
    public static function getTimeout(): int
    {
        return static::$timeout;
    }

    /**
     * @param  int  $timeout the timeout in seconds
     */
    public static function setTimeout(int $timeout): void
    {
        static::$timeout = $timeout;
    }

    /**
     * Escapes a string to be used as a shell argument.
     *
     * @param string|false|null $argument The argument that will be escaped
     *
     * @return string The escaped argument
     */
    public static function escape($argument): string
    {
        return self::escapeArgument($argument);
    }

    /**
     * @param string|list<string> $command
     */
    private function outputCommandRun($command, ?string $cwd, bool $async): void
    {
        if (null === $this->io || !$this->io->isDebug()) {
            return;
        }

        $commandString = is_string($command) ? $command : implode(' ', array_map(self::class.'::escape', $command));
        $safeCommand = Preg::replaceCallback('{://(?P<user>[^:/\s]+):(?P<password>[^@\s/]+)@}i', static function ($m): string {
            // if the username looks like a long (12char+) hex string, or a modern github token (e.g. ghp_xxx) we obfuscate that
            if (Preg::isMatch('{^([a-f0-9]{12,}|gh[a-z]_[a-zA-Z0-9_]+)$}', $m['user'])) {
                return '://***:***@';
            }
            if (Preg::isMatch('{^[a-f0-9]{12,}$}', $m['user'])) {
                return '://***:***@';
            }

            return '://'.$m['user'].':***@';
        }, $commandString);
        $safeCommand = Preg::replace("{--password (.*[^\\\\]\') }", '--password \'***\' ', $safeCommand);
        $this->io->writeError('Executing'.($async ? ' async' : '').' command ('.($cwd ?: 'CWD').'): '.$safeCommand);
    }

    /**
     * Escapes a string to be used as a shell argument for Symfony Process.
     *
     * This method expects cmd.exe to be started with the /V:ON option, which
     * enables delayed environment variable expansion using ! as the delimiter.
     * If this is not the case, any escaped ^^!var^^! will be transformed to
     * ^!var^! and introduce two unintended carets.
     *
     * Modified from https://github.com/johnstevenson/winbox-args
     * MIT Licensed (c) John Stevenson <john-stevenson@blueyonder.co.uk>
     *
     * @param string|false|null $argument
     */
    private static function escapeArgument($argument): string
    {
        if ('' === ($argument = (string) $argument)) {
            return escapeshellarg($argument);
        }

        if (!Platform::isWindows()) {
            return "'".str_replace("'", "'\\''", $argument)."'";
        }

        // New lines break cmd.exe command parsing
        // and special chars like the fullwidth quote can be used to break out
        // of parameter encoding via "Best Fit" encoding conversion
        $argument = strtr($argument, [
            "\n" => ' ',
            "\u{ff02}" => '"',
            "\u{02ba}" => '"',
            "\u{301d}" => '"',
            "\u{301e}" => '"',
            "\u{030e}" => '"',
            "\u{ff1a}" => ':',
            "\u{0589}" => ':',
            "\u{2236}" => ':',
            "\u{ff0f}" => '/',
            "\u{2044}" => '/',
            "\u{2215}" => '/',
            "\u{00b4}" => '/',
        ]);

        // In addition to whitespace, commas need quoting to preserve paths
        $quote = strpbrk($argument, " \t,") !== false;
        $argument = Preg::replace('/(\\\\*)"/', '$1$1\\"', $argument, -1, $dquotes);
        $meta = $dquotes > 0 || Preg::isMatch('/%[^%]+%|![^!]+!/', $argument);

        if (!$meta && !$quote) {
            $quote = strpbrk($argument, '^&|<>()') !== false;
        }

        if ($quote) {
            $argument = '"'.Preg::replace('/(\\\\*)$/', '$1$1', $argument).'"';
        }

        if ($meta) {
            $argument = Preg::replace('/(["^&|<>()%])/', '^$1', $argument);
            $argument = Preg::replace('/(!)/', '^^$1', $argument);
        }

        return $argument;
    }

    /**
     * @param string[]|string $command
     */
    public function requiresGitDirEnv($command): bool
    {
        $cmd = !is_array($command) ? explode(' ', $command) : $command;
        if ($cmd[0] !== 'git') {
            return false;
        }

        foreach (self::GIT_CMDS_NEED_GIT_DIR as $gitCmd) {
            if (array_intersect($cmd, $gitCmd) === $gitCmd) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolves executable paths on Windows
     */
    private static function getExecutable(string $name): string
    {
        if (\in_array(strtolower($name), self::BUILTIN_CMD_COMMANDS, true)) {
            return $name;
        }

        if (!isset(self::$executables[$name])) {
            $path = (new ExecutableFinder())->find($name, $name);
            if ($path !== null) {
                self::$executables[$name] = $path;
            }
        }

        return self::$executables[$name] ?? $name;
    }
}
