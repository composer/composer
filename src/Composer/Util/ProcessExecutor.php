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

use Symfony\Component\Process\Process;
use Symfony\Component\Process\ProcessUtils;
use Composer\IO\IOInterface;
use React;
use React\Promise\Deferred;

/**
 * @author Robert Schönthal <seroscho@googlemail.com>
 */
class ProcessExecutor
{
    protected static $timeout = 300;

    protected $captureOutput;
    protected $errorOutput;
    protected $io;

    public function __construct(IOInterface $io = null)
    {
        $this->io = $io;
    }

    /**
     * runs a process on the commandline
     *
     * @param  string $command the command to execute
     * @param  mixed  $output  the output will be written into this var if passed by ref
     *                         if a callable is passed it will be used as output handler
     * @param  string $cwd     the working directory
     * @return int    statuscode
     */
    public function execute($command, &$output = null, $cwd = null, $loop = null)
    {
        $that = clone $this;

        if ($that->io && $that->io->isDebug()) {
            $safeCommand = preg_replace('{(://[^:/\s]+:)[^@\s/]+}i', '$1****', $command);
            $that->io->writeError('Executing command ('.($cwd ?: 'CWD').'): '.$safeCommand);
        }

        // make sure that null translate to the proper directory in case the dir is a symlink
        // and we call a git command, because msysgit does not handle symlinks properly
        if (null === $cwd && defined('PHP_WINDOWS_VERSION_BUILD') && false !== strpos($command, 'git') && getcwd()) {
            $cwd = realpath(getcwd());
        }

        $that->captureOutput = func_num_args() > 1;
        $this->errorOutput = null;

        $timeout = static::getTimeout();
        $result = (object) array(
            'status' => null,
            'stdout' => null,
            'stderr' => null,
        );

        if (is_callable($output)) {
            $callback = $output;
        } else {
            $callback = array($that, 'outputHandler');
            if ($that->captureOutput) {
                $result->stdout =& $output;
                $output = '';
            }
        }

        if ($loop) {
            $deferred = new Deferred();
            $process = new React\ChildProcess\Process($command, $cwd);

            $timer = $loop->addTimer($timeout, function () use ($process, $deferred, $timeout, $command) {
                if ($process->isRunning()) {
                    $process->terminate();

                    $deferred->reject(new \RuntimeException(sprintf('The process "%s" exceeded the timeout of %s seconds.', $command, $timeout)));
                }
            });
            $process->on('exit', function($status, $signal) use ($timer, $deferred, $result) {
                $timer->cancel();
                $result->status = $status;
                $deferred->resolve($result);
            });

            $process->start($loop);

            $process->stdout->on('data', function ($data) use ($result, $callback) {
                if (null !== $result->stdout) {
                    $result->stdout .= $data;
                }
                call_user_func($callback, 'out', $data);
            });

            $process->stderr->on('data', function ($data) use ($result, $callback) {
                $result->stderr .= $data;
                call_user_func($callback, 'err', $data);
            });

            return $deferred->promise();
        }

        $process = new Process($command, $cwd, null, null, $timeout);

        $callback = is_callable($output) ? $output : array($that, 'outputHandler');
        $process->run($callback);

        if ('' === $output) {
            $output = $process->getOutput();
        }

        $this->errorOutput = $process->getErrorOutput();

        return $process->getExitCode();
    }

    public function splitLines($output)
    {
        $output = trim($output);

        return ((string) $output === '') ? array() : preg_split('{\r?\n}', $output);
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

    public function outputHandler($type, $buffer)
    {
        if ($this->captureOutput) {
            return;
        }

        echo $buffer;
    }

    public static function getTimeout()
    {
        return static::$timeout;
    }

    public static function setTimeout($timeout)
    {
        static::$timeout = $timeout;
    }

    /**
     * Escapes a string to be used as a shell argument.
     *
     * @param string $argument The argument that will be escaped
     *
     * @return string The escaped argument
     */

    public static function escape($argument)
    {
        return ProcessUtils::escapeArgument($argument);
    }
}
