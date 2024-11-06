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

namespace Composer\Test\Mock;

use PHPUnit\Framework\MockObject\MockBuilder;
use React\Promise\PromiseInterface;
use Composer\Util\ProcessExecutor;
use Composer\Util\Platform;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\AssertionFailedError;
use Symfony\Component\Process\Process;
use React\Promise\Promise;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class ProcessExecutorMock extends ProcessExecutor
{
    /**
     * @var array<array{cmd: string|list<string>, return: int, stdout: string, stderr: string, callback: callable|null}>|null
     */
    private $expectations = null;
    /**
     * @var bool
     */
    private $strict = false;
    /**
     * @var array{return: int, stdout: string, stderr: string}
     */
    private $defaultHandler = ['return' => 0, 'stdout' => '', 'stderr' => ''];
    /**
     * @var string[]
     */
    private $log = [];
    /**
     * @var MockBuilder<Process>
     */
    private $processMockBuilder;

    /**
     * @param MockBuilder<Process> $processMockBuilder
     */
    public function __construct(MockBuilder $processMockBuilder)
    {
        parent::__construct();
        $this->processMockBuilder = $processMockBuilder->disableOriginalConstructor();
    }

    /**
     * @param array<string|non-empty-list<string>|array{cmd: string|non-empty-list<string>, return?: int, stdout?: string, stderr?: string, callback?: callable}> $expectations
     * @param bool                                                                                                               $strict         set to true if you want to provide *all* expected commands, and not just a subset you are interested in testing
     * @param array{return: int, stdout?: string, stderr?: string}                                                               $defaultHandler default command handler for undefined commands if not in strict mode
     */
    public function expects(array $expectations, bool $strict = false, array $defaultHandler = ['return' => 0, 'stdout' => '', 'stderr' => '']): void
    {
        /** @var array{cmd: string|non-empty-list<string>, return: int, stdout: string, stderr: string, callback: callable|null} $default */
        $default = ['cmd' => '', 'return' => 0, 'stdout' => '', 'stderr' => '', 'callback' => null];
        $this->expectations = array_map(static function ($expect) use ($default): array {
            if (is_string($expect) || array_is_list($expect)) {
                $command = $expect;
                $expect = $default;
                $expect['cmd'] = $command;
            } elseif (count($diff = array_diff_key(array_merge($default, $expect), $default)) > 0) {
                throw new \UnexpectedValueException('Unexpected keys in process execution step: '.implode(', ', array_keys($diff)));
            }

            return array_merge($default, $expect);
        }, $expectations);
        $this->strict = $strict;

        $this->defaultHandler = array_merge($this->defaultHandler, $defaultHandler);
    }

    public function assertComplete(): void
    {
        // this was not configured to expect anything, so no need to react here
        if (!is_array($this->expectations)) {
            return;
        }

        if (count($this->expectations) > 0) {
            $expectations = array_map(static function ($expect): string {
                return is_array($expect['cmd']) ? implode(' ', $expect['cmd']) : $expect['cmd'];
            }, $this->expectations);
            throw new AssertionFailedError(
                'There are still '.count($this->expectations).' expected process calls which have not been consumed:'.PHP_EOL.
                implode(PHP_EOL, $expectations).PHP_EOL.PHP_EOL.
                'Received calls:'.PHP_EOL.implode(PHP_EOL, $this->log)
            );
        }

        // dummy assertion to ensure the test is not marked as having no assertions
        Assert::assertTrue(true); // @phpstan-ignore staticMethod.alreadyNarrowedType
    }

    public function execute($command, &$output = null, ?string $cwd = null): int
    {
        $cwd = $cwd ?? Platform::getCwd();
        if (func_num_args() > 1) {
            return $this->doExecute($command, $cwd, false, $output);
        }

        return $this->doExecute($command, $cwd, false);
    }

    public function executeTty($command, ?string $cwd = null): int
    {
        $cwd = $cwd ?? Platform::getCwd();
        if (Platform::isTty()) {
            return $this->doExecute($command, $cwd, true);
        }

        return $this->doExecute($command, $cwd, false);
    }

    /**
     * @param string|list<string> $command
     * @param callable|string|null $output
     * @return mixed
     */
    private function doExecute($command, string $cwd, bool $tty, &$output = null)
    {
        $this->captureOutput = func_num_args() > 3;
        $this->errorOutput = '';

        $callback = is_callable($output) ? $output : function (string $type, string $buffer): void {
            $this->outputHandler($type, $buffer);
        };

        $commandString = is_array($command) ? implode(' ', $command) : $command;
        $this->log[] = $commandString;

        if (is_array($this->expectations) && count($this->expectations) > 0 && $command === $this->expectations[0]['cmd']) {
            $expect = array_shift($this->expectations);
            $stdout = $expect['stdout'];
            $stderr = $expect['stderr'];
            $return = $expect['return'];
            if (isset($expect['callback'])) {
                $expect['callback']();
            }
        } elseif (!$this->strict) {
            $stdout = $this->defaultHandler['stdout'];
            $stderr = $this->defaultHandler['stderr'];
            $return = $this->defaultHandler['return'];
        } else {
            throw new AssertionFailedError(
                'Received unexpected command '.var_export($command, true).' in "'.$cwd.'"'.PHP_EOL.
                (is_array($this->expectations) && count($this->expectations) > 0 ? 'Expected '.var_export($this->expectations[0]['cmd'], true).' at this point.' : 'Expected no more calls at this point.').PHP_EOL.
                'Received calls:'.PHP_EOL.implode(PHP_EOL, array_slice($this->log, 0, -1))
            );
        }

        if ($stdout) {
            $callback(Process::OUT, $stdout);
        }
        if ($stderr) {
            $callback(Process::ERR, $stderr);
        }

        if ($this->captureOutput && !is_callable($output)) {
            $output = $stdout;
        }

        $this->errorOutput = $stderr;

        return $return;
    }

    public function executeAsync($command, ?string $cwd = null): PromiseInterface
    {
        $cwd = $cwd ?? Platform::getCwd();

        $resolver = function ($resolve, $reject) use ($command, $cwd): void {
            $result = $this->doExecute($command, $cwd, false, $output);
            $procMock = $this->processMockBuilder->getMock();
            $procMock->method('getOutput')->willReturn($output);
            $procMock->method('isSuccessful')->willReturn($result === 0);
            $procMock->method('getExitCode')->willReturn($result);

            $resolve($procMock);
        };

        $canceler = static function (): void {
            throw new \RuntimeException('Aborted process');
        };

        return new Promise($resolver, $canceler);
    }

    public function getErrorOutput(): string
    {
        return $this->errorOutput;
    }
}
