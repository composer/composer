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
use Symfony\Component\Process\Process;
use Symfony\Component\Process\ProcessUtils;

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
            $safeCommand = preg_replace_callback('{://(?P<user>[^:/\s]+):(?P<password>[^@\s/]+)@}i', function ($m) {
                // if the username looks like a long (12char+) hex string, or a modern github token (e.g. ghp_xxx) we obfuscate that
                if (preg_match('{^([a-f0-9]{12,}|gh[a-z]_[a-zA-Z0-9_]+)$}', $m['user'])) {
                    return '://***:***@';
                }

                return '://'.$m['user'].':***@';
            }, $command);
            $safeCommand = preg_replace("{--password (.*[^\\\\]\') }", '--password \'***\' ', $safeCommand);
            $this->io->writeError('Executing command ('.($cwd ?: 'CWD').'): '.$safeCommand);
        }

        // make sure that null translate to the proper directory in case the dir is a symlink
        // and we call a git command, because msysgit does not handle symlinks properly
        if (null === $cwd && Platform::isWindows() && false !== strpos($command, 'git') && getcwd()) {
            $cwd = realpath(getcwd());
        }

        if (null !== $cwd && !is_dir($cwd)) {
            throw new \RuntimeException('The given CWD for the process does not exist: '.$cwd);
        }

        $this->captureOutput = func_num_args() > 1;
        $this->errorOutput = null;

        // TODO in v3, commands should be passed in as arrays of cmd + args
        if (method_exists('Symfony\Component\Process\Process', 'fromShellCommandline')) {
            $process = Process::fromShellCommandline($command, $cwd, null, null, static::getTimeout());
        } else {
            $process = new Process($command, $cwd, null, null, static::getTimeout());
        }

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

        if (null === $this->io) {
            echo $buffer;

            return;
        }

        if (method_exists($this->io, 'writeRaw')) {
            if (Process::ERR === $type) {
                $this->io->writeErrorRaw($buffer, false);
            } else {
                $this->io->writeRaw($buffer, false);
            }
        } else {
            if (Process::ERR === $type) {
                $this->io->writeError($buffer, false);
            } else {
                $this->io->write($buffer, false);
            }
        }
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
        return self::escapeArgument($argument);
    }

    /**
     * Copy of ProcessUtils::escapeArgument() that is deprecated in Symfony 3.3 and removed in Symfony 4.
     *
     * @param string $argument
     *
     * @return string
     */
    private static function escapeArgument($argument)
    {
        //Fix for PHP bug #43784 escapeshellarg removes % from given string
        //Fix for PHP bug #49446 escapeshellarg doesn't work on Windows
        //@see https://bugs.php.net/bug.php?id=43784
        //@see https://bugs.php.net/bug.php?id=49446
        if ('\\' === DIRECTORY_SEPARATOR) {
            if ((string) $argument === '') {
                return escapeshellarg($argument);
            }

            $escapedArgument = '';
            $quote = false;
            foreach (preg_split('/(")/', $argument, -1, PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE) as $part) {
                if ('"' === $part) {
                    $escapedArgument .= '\\"';
                } elseif (self::isSurroundedBy($part, '%')) {
                    // Avoid environment variable expansion
                    $escapedArgument .= '^%"'.substr($part, 1, -1).'"^%';
                } else {
                    // escape trailing backslash
                    if ('\\' === substr($part, -1)) {
                        $part .= '\\';
                    }
                    $quote = true;
                    $escapedArgument .= $part;
                }
            }
            if ($quote) {
                $escapedArgument = '"'.$escapedArgument.'"';
            }

            return $escapedArgument;
        }

        return "'".str_replace("'", "'\\''", $argument)."'";
    }

    private static function isSurroundedBy($arg, $char)
    {
        return 2 < strlen($arg) && $char === $arg[0] && $char === $arg[strlen($arg) - 1];
    }
}
