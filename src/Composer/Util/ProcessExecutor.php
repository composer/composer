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
    public function execute($command, &$output = null, $cwd = null)
    {
        if ($this->io && $this->io->isDebug()) {
            $safeCommand = preg_replace('{(://[^:/\s]+:)[^@\s/]+}i', '$1****', $command);
            $this->io->writeError('Executing command ('.($cwd ?: 'CWD').'): '.$safeCommand);
        }

        // make sure that null translate to the proper directory in case the dir is a symlink
        // and we call a git command, because msysgit does not handle symlinks properly
        if (null === $cwd && Platform::isWindows() && false !== strpos($command, 'git') && getcwd()) {
            $cwd = realpath(getcwd());
        }

        $this->captureOutput = count(func_get_args()) > 1;
        $this->errorOutput = null;
        $process = new Process($command, $cwd, null, null, static::getTimeout());

        $callback = is_callable($output) ? $output : array($this, 'outputHandler');
        $process->run($callback);

        if ($this->captureOutput && !is_callable($output)) {
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
