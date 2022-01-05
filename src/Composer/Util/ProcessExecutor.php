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

use Composer\IO\IOInterface;
use Composer\Pcre\Preg;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\RuntimeException;
use React\Promise\Promise;
use React\Promise\PromiseInterface;

/**
 * @author Robert Schönthal <seroscho@googlemail.com>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class ProcessExecutor
{
    const STATUS_QUEUED = 1;
    const STATUS_STARTED = 2;
    const STATUS_COMPLETED = 3;
    const STATUS_FAILED = 4;
    const STATUS_ABORTED = 5;

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
    private $jobs = array();
    /** @var int */
    private $runningJobs = 0;
    /** @var int */
    private $maxJobs = 10;
    /** @var int */
    private $idGen = 0;
    /** @var bool */
    private $allowAsync = false;

    public function __construct(IOInterface $io = null)
    {
        $this->io = $io;
    }

    /**
     * runs a process on the commandline
     *
     * @param  string  $command the command to execute
     * @param  mixed   $output  the output will be written into this var if passed by ref
     *                          if a callable is passed it will be used as output handler
     * @param  ?string $cwd     the working directory
     * @return int     statuscode
     */
    public function execute($command, &$output = null, $cwd = null)
    {
        if (func_num_args() > 1) {
            return $this->doExecute($command, $cwd, false, $output);
        }

        return $this->doExecute($command, $cwd, false);
    }

    /**
     * runs a process on the commandline in TTY mode
     *
     * @param  string  $command the command to execute
     * @param  ?string $cwd     the working directory
     * @return int     statuscode
     */
    public function executeTty($command, $cwd = null)
    {
        if (Platform::isTty()) {
            return $this->doExecute($command, $cwd, true);
        }

        return $this->doExecute($command, $cwd, false);
    }

    /**
     * @param  string  $command
     * @param  ?string $cwd
     * @param  bool    $tty
     * @param  mixed   $output
     * @return int
     */
    private function doExecute($command, $cwd, $tty, &$output = null)
    {
        $this->outputCommandRun($command, $cwd, false);

        // TODO in 2.2, these two checks can be dropped as Symfony 4+ supports them out of the box
        // make sure that null translate to the proper directory in case the dir is a symlink
        // and we call a git command, because msysgit does not handle symlinks properly
        if (null === $cwd && Platform::isWindows() && false !== strpos($command, 'git') && getcwd()) {
            $cwd = realpath(getcwd());
        }
        if (null !== $cwd && !is_dir($cwd)) {
            throw new \RuntimeException('The given CWD for the process does not exist: '.$cwd);
        }

        $this->captureOutput = func_num_args() > 3;
        $this->errorOutput = '';

        // TODO in v3, commands should be passed in as arrays of cmd + args
        if (method_exists('Symfony\Component\Process\Process', 'fromShellCommandline')) {
            $process = Process::fromShellCommandline($command, $cwd, null, null, static::getTimeout());
        } else {
            /** @phpstan-ignore-next-line */
            $process = new Process($command, $cwd, null, null, static::getTimeout());
        }
        if (!Platform::isWindows() && $tty) {
            try {
                $process->setTty(true);
            } catch (RuntimeException $e) {
                // ignore TTY enabling errors
            }
        }

        $callback = is_callable($output) ? $output : array($this, 'outputHandler');
        $process->run($callback);

        if ($this->captureOutput && !is_callable($output)) {
            $output = $process->getOutput();
        }

        $this->errorOutput = $process->getErrorOutput();

        return $process->getExitCode();
    }

    /**
     * starts a process on the commandline in async mode
     *
     * @param  string           $command the command to execute
     * @param  string           $cwd     the working directory
     * @return PromiseInterface
     */
    public function executeAsync($command, $cwd = null)
    {
        if (!$this->allowAsync) {
            throw new \LogicException('You must use the ProcessExecutor instance which is part of a Composer\Loop instance to be able to run async processes');
        }

        $job = array(
            'id' => $this->idGen++,
            'status' => self::STATUS_QUEUED,
            'command' => $command,
            'cwd' => $cwd,
        );

        $resolver = function ($resolve, $reject) use (&$job) {
            $job['status'] = ProcessExecutor::STATUS_QUEUED;
            $job['resolve'] = $resolve;
            $job['reject'] = $reject;
        };

        $self = $this;

        $canceler = function () use (&$job) {
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
        $promise = $promise->then(function () use (&$job, $self) {
            if ($job['process']->isSuccessful()) {
                $job['status'] = ProcessExecutor::STATUS_COMPLETED;
            } else {
                $job['status'] = ProcessExecutor::STATUS_FAILED;
            }

            // TODO 3.0 this should be done directly on $this when PHP 5.3 is dropped
            $self->markJobDone();

            return $job['process'];
        }, function ($e) use (&$job, $self) {
            $job['status'] = ProcessExecutor::STATUS_FAILED;

            $self->markJobDone();

            throw $e;
        });
        $this->jobs[$job['id']] = &$job;

        if ($this->runningJobs < $this->maxJobs) {
            $this->startJob($job['id']);
        }

        return $promise;
    }

    /**
     * @param  int  $id
     * @return void
     */
    private function startJob($id)
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

        // TODO in 2.2, these two checks can be dropped as Symfony 4+ supports them out of the box
        // make sure that null translate to the proper directory in case the dir is a symlink
        // and we call a git command, because msysgit does not handle symlinks properly
        if (null === $cwd && Platform::isWindows() && false !== strpos($command, 'git') && getcwd()) {
            $cwd = realpath(getcwd());
        }
        if (null !== $cwd && !is_dir($cwd)) {
            throw new \RuntimeException('The given CWD for the process does not exist: '.$cwd);
        }

        try {
            // TODO in v3, commands should be passed in as arrays of cmd + args
            if (method_exists('Symfony\Component\Process\Process', 'fromShellCommandline')) {
                $process = Process::fromShellCommandline($command, $cwd, null, null, static::getTimeout());
            } else {
                $process = new Process($command, $cwd, null, null, static::getTimeout());
            }
        } catch (\Exception $e) {
            call_user_func($job['reject'], $e);

            return;
        } catch (\Throwable $e) {
            call_user_func($job['reject'], $e);

            return;
        }

        $job['process'] = $process;

        try {
            $process->start();
        } catch (\Exception $e) {
            call_user_func($job['reject'], $e);

            return;
        } catch (\Throwable $e) {
            call_user_func($job['reject'], $e);

            return;
        }
    }

    /**
     * @param  ?int $index job id
     * @return void
     */
    public function wait($index = null)
    {
        while (true) {
            if (!$this->countActiveJobs($index)) {
                return;
            }

            usleep(1000);
        }
    }

    /**
     * @internal
     *
     * @return void
     */
    public function enableAsync()
    {
        $this->allowAsync = true;
    }

    /**
     * @internal
     *
     * @param  ?int $index job id
     * @return int         number of active (queued or started) jobs
     */
    public function countActiveJobs($index = null)
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

    /**
     * @private
     *
     * @return void
     */
    public function markJobDone()
    {
        $this->runningJobs--;
    }

    /**
     * @param  ?string  $output
     * @return string[]
     */
    public function splitLines($output)
    {
        $output = trim((string) $output);

        return $output === '' ? array() : Preg::split('{\r?\n}', $output);
    }

    /**
     * Get any error output from the last command
     *
     * @return string
     */
    public function getErrorOutput()
    {
        return $this->errorOutput;
    }

    /**
     * @private
     *
     * @param Process::ERR|Process::OUT $type
     * @param string                    $buffer
     *
     * @return void
     */
    public function outputHandler($type, $buffer)
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

    /**
     * @return int the timeout in seconds
     */
    public static function getTimeout()
    {
        return static::$timeout;
    }

    /**
     * @param  int  $timeout the timeout in seconds
     * @return void
     */
    public static function setTimeout($timeout)
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
    public static function escape($argument)
    {
        return self::escapeArgument($argument);
    }

    /**
     * @param string  $command
     * @param ?string $cwd
     * @param bool    $async
     * @return void
     */
    private function outputCommandRun($command, $cwd, $async)
    {
        if (null === $this->io || !$this->io->isDebug()) {
            return;
        }

        $safeCommand = Preg::replaceCallback('{://(?P<user>[^:/\s]+):(?P<password>[^@\s/]+)@}i', function ($m) {
            // if the username looks like a long (12char+) hex string, or a modern github token (e.g. ghp_xxx) we obfuscate that
            if (Preg::isMatch('{^([a-f0-9]{12,}|gh[a-z]_[a-zA-Z0-9_]+)$}', $m['user'])) {
                return '://***:***@';
            }
            if (Preg::isMatch('{^[a-f0-9]{12,}$}', $m['user'])) {
                return '://***:***@';
            }

            return '://'.$m['user'].':***@';
        }, $command);
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
     *
     * @return string
     */
    private static function escapeArgument($argument)
    {
        if ('' === ($argument = (string) $argument)) {
            return escapeshellarg($argument);
        }

        if (!Platform::isWindows()) {
            return "'".str_replace("'", "'\\''", $argument)."'";
        }

        // New lines break cmd.exe command parsing
        $argument = strtr($argument, "\n", ' ');

        $quote = strpbrk($argument, " \t") !== false;
        $argument = Preg::replace('/(\\\\*)"/', '$1$1\\"', $argument, -1, $dquotes);
        $meta = $dquotes || Preg::isMatch('/%[^%]+%|![^!]+!/', $argument);

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
}
