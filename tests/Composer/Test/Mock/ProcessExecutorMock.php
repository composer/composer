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

namespace Composer\Test\Mock;

use Composer\Util\ProcessExecutor;
use Composer\Util\Platform;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\AssertionFailedError;
use Symfony\Component\Process\Process;
use React\Promise\Promise;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class ProcessExecutorMock extends ProcessExecutor
{
    private $expectations = array();
    private $strict = false;
    private $defaultHandler = array('return' => 0, 'stdout' => '', 'stderr' => '');
    private $log = array();

    /**
     * @param array<string|array{cmd: string, return?: int, stdout?: string, stderr?: string, callback?: callable}> $expectations
     * @param bool                                                                                                 $strict         set to true if you want to provide *all* expected commands, and not just a subset you are interested in testing
     * @param array{return: int, stdout?: string, stderr?: string}                                                 $defaultHandler default command handler for undefined commands if not in strict mode
     */
    public function expects(array $expectations, $strict = false, array $defaultHandler = array('return' => 0, 'stdout' => '', 'stderr' => ''))
    {
        $default = array('cmd' => '', 'return' => 0, 'stdout' => '', 'stderr' => '', 'callback' => null);
        $this->expectations = array_map(function ($expect) use ($default) {
            if (is_string($expect)) {
                $expect = array('cmd' => $expect);
            } elseif ($diff = array_diff_key(array_merge($default, $expect), $default)) {
                throw new \UnexpectedValueException('Unexpected keys in process execution step: '.implode(', ', array_keys($diff)));
            }

            return array_merge($default, $expect);
        }, $expectations);
        $this->strict = $strict;
        $this->defaultHandler = array_merge($default, $defaultHandler);
    }

    public function assertComplete(TestCase $testCase)
    {
        if ($this->expectations) {
            $expectations = array_map(function ($expect) {
                return $expect['cmd'];
            }, $this->expectations);
            throw new AssertionFailedError(
                'There are still '.count($this->expectations).' expected process calls which have not been consumed:'.PHP_EOL.
                implode(PHP_EOL, $expectations).PHP_EOL.PHP_EOL.
                'Received calls:'.PHP_EOL.implode(PHP_EOL, $this->log)
            );
        }

        $testCase->assertTrue(true);
    }

    public function execute($command, &$output = null, $cwd = null)
    {
        if (func_num_args() > 1) {
            return $this->doExecute($command, $cwd, false, $output);
        }

        return $this->doExecute($command, $cwd, false);
    }

    public function executeTty($command, $cwd = null)
    {
        if (Platform::isTty()) {
            return $this->doExecute($command, $cwd, true);
        }

        return $this->doExecute($command, $cwd, false);
    }

    private function doExecute($command, $cwd, $tty, &$output = null)
    {
        $this->captureOutput = func_num_args() > 3;
        $this->errorOutput = '';

        $callback = is_callable($output) ? $output : array($this, 'outputHandler');

        $this->log[] = $command;

        if ($this->expectations && $command === $this->expectations[0]['cmd']) {
            $expect = array_shift($this->expectations);
            $stdout = $expect['stdout'];
            $stderr = $expect['stderr'];
            $return = $expect['return'];
            if (isset($expect['callback'])) {
                call_user_func($expect['callback']);
            }
        } elseif (!$this->strict) {
            $stdout = $this->defaultHandler['stdout'];
            $stderr = $this->defaultHandler['stderr'];
            $return = $this->defaultHandler['return'];
        } else {
            throw new AssertionFailedError(
                'Received unexpected command "'.$command.'" in "'.$cwd.'"'.PHP_EOL.
                ($this->expectations ? 'Expected "'.$this->expectations[0]['cmd'].'" at this point.' : 'Expected no more calls at this point.').PHP_EOL.
                'Received calls:'.PHP_EOL.implode(PHP_EOL, array_slice($this->log, 0, -1))
            );
        }

        if ($stdout) {
            call_user_func($callback, Process::STDOUT, $stdout);
        }
        if ($stderr) {
            call_user_func($callback, Process::ERR, $stderr);
        }

        if ($this->captureOutput && !is_callable($output)) {
            $output = $stdout;
        }

        $this->errorOutput = $stderr;

        return $return;
    }

    public function executeAsync($command, $cwd = null)
    {
        $resolver = function ($resolve, $reject) {
            // TODO strictly speaking this should resolve with a mock Process instance here
            $resolve();
        };

        $canceler = function () {
            throw new \RuntimeException('Aborted process');
        };

        return new Promise($resolver, $canceler);
    }

    public function getErrorOutput()
    {
        return $this->errorOutput;
    }
}
