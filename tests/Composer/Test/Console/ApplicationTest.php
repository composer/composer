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

namespace Composer\Test\Console;

use Composer\Command\AboutCommand;
use Composer\Console\Application;
use Composer\Command\ScriptAliasCommand;
use Composer\Test\TestCase;
use Composer\Util\Platform;
use Composer\Util\ProcessExecutor;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Command\HelpCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

class ApplicationTest extends TestCase
{
    /** @var string|false */
    private $originalTerminalStatus;

    /** @dataProvider provideTerminalStatusSettings */
    public function testProgramStatusIsEmittedByCliToTerminal(string $setting, bool $rich, bool $progress): void
    {
        Platform::putEnv('COMPOSER_TERMINAL_STATUS', $setting);
        $command = [PHP_BINARY, dirname(__DIR__, 4).'/bin/composer', '--ansi', '--no-plugins', 'about'];
        $process = $this->createTerminalProcess($command);
        self::assertSame(0, $process->run(), $process->getErrorOutput());
        $written = $process->getOutput();
        self::assertSame($rich, strpos($written, "\033]7501;state=working:app=composer\033\\") !== false);
        self::assertSame($rich, strpos($written, "\033]7501;state=done:app=composer\033\\") !== false);
        self::assertSame($progress, strpos($written, "\033]9;4;3\033\\") !== false);
        self::assertSame($progress, strpos($written, "\033]9;4;0\033\\") !== false);
        self::assertStringContainsString('Composer - Dependency Manager for PHP', $process->getOutput());
    }

    public function testMissingComposerFileReportsErrorBeforeExiting(): void
    {
        $directory = self::getUniqueTmpDirectory();
        try {
            Platform::putEnv('COMPOSER_TERMINAL_STATUS', '7501,9;4');
            $command = [PHP_BINARY, dirname(__DIR__, 4).'/bin/composer', '--ansi', '--no-plugins', '--working-dir', $directory, 'install'];
            $process = $this->createTerminalProcess($command);
            $process->run();
            $written = $process->getOutput();
            self::assertStringContainsString('Composer could not find a composer.json file', $written);
            self::assertStringContainsString("\033]7501;state=working:app=composer\033\\", $written);
            self::assertStringEndsWith("\033]9;4;2\033\\\033]7501;state=error:app=composer\033\\", $written);
        } finally {
            self::removeTestDirectory($directory);
        }
    }

    /** @param non-empty-list<string> $command */
    private function createTerminalProcess(array $command): Process
    {
        if (Platform::isWindows()) {
            $this->markTestSkipped('Requires a Unix pseudo-terminal');
        }
        $this->skipIfNotExecutable('script');

        // script gives Composer a terminal even though PHPUnit captures its output.
        $scriptCommand = PHP_OS === 'Darwin'
            ? array_merge(['script', '-q', '/dev/null'], $command)
            : ['script', '-q', '-e', '-c', implode(' ', array_map([ProcessExecutor::class, 'escape'], $command)), '/dev/null'];

        return new Process($scriptCommand);
    }

    public static function provideTerminalStatusSettings(): array
    {
        return [
            ['7501', true, false],
            ['9;4', false, true],
            ['7501,9;4', true, true],
            ['off', false, false],
        ];
    }

    public function testProgramStatusIsNotEmittedByCliToRedirectedOutput(): void
    {
        $process = new Process([PHP_BINARY, dirname(__DIR__, 4).'/bin/composer', '--ansi', '--no-plugins', 'about']);

        self::assertSame(0, $process->run(), $process->getErrorOutput());
        self::assertStringNotContainsString("\033]7501;", $process->getOutput().$process->getErrorOutput());
        self::assertStringContainsString('Composer - Dependency Manager for PHP', $process->getOutput());
    }

    /**
     * @dataProvider programStatusExitCodeProvider
     */
    public function testProgramStatusReportsCommandOutcome(int $exitCode, string $state): void
    {
        $application = new Application();
        $command = new class('status-test') extends SymfonyCommand {
            /** @var int */
            public $exitCode = 0;

            protected function execute(InputInterface $input, OutputInterface $output): int
            {
                return $this->exitCode;
            }
        };
        $command->exitCode = $exitCode;
        // Compatibility layer for symfony/console <7.4
        // @phpstan-ignore method.notFound, function.alreadyNarrowedType, method.deprecated
        method_exists($application, 'addCommand') ? $application->addCommand($command) : $application->add($command);
        $output = new BufferedOutput(BufferedOutput::VERBOSITY_NORMAL, true);
        self::assertSame($exitCode, $application->doRun(new ArrayInput(['command' => 'status-test', '--no-plugins' => true]), $output));
        $written = $output->fetch();
        self::assertStringStartsWith("\033]7501;state=working:app=composer\033\\", $written);
        self::assertStringEndsWith("\033]7501;state=".$state.":app=composer\033\\", $written);
    }

    public static function programStatusExitCodeProvider(): array
    {
        return [[0, 'done'], [1, 'error'], [130, 'idle'], [143, 'idle']];
    }

    public function testProgramStatusReportsEarlyException(): void
    {
        $application = new Application();
        $output = new BufferedOutput(BufferedOutput::VERBOSITY_NORMAL, true);
        try {
            $application->doRun(new ArrayInput(['--working-dir' => __FILE__]), $output);
            self::fail('Expected invalid working directory');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('Invalid working directory', $e->getMessage());
        }
        self::assertStringEndsWith("\033]7501;state=error:app=composer\033\\", $output->fetch());
        restore_error_handler();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        Platform::clearEnv('COMPOSER_DISABLE_XDEBUG_WARN');
        if ($this->originalTerminalStatus === false) {
            Platform::clearEnv('COMPOSER_TERMINAL_STATUS');
        } else {
            Platform::putEnv('COMPOSER_TERMINAL_STATUS', $this->originalTerminalStatus);
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        Platform::putEnv('COMPOSER_DISABLE_XDEBUG_WARN', '1');
        $this->originalTerminalStatus = Platform::getEnv('COMPOSER_TERMINAL_STATUS');
        Platform::putEnv('COMPOSER_TERMINAL_STATUS', '7501');
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testDevWarning(): void
    {
        $application = new Application;

        if (!defined('COMPOSER_DEV_WARNING_TIME')) {
            define('COMPOSER_DEV_WARNING_TIME', time() - 1);
        }

        $output = new BufferedOutput();
        $application->doRun(new ArrayInput(['command' => 'about']), $output);

        $expectedOutput = sprintf('<warning>Warning: This development build of Composer is over 60 days old. It is recommended to update it by running "%s self-update" to get the latest version.</warning>', $_SERVER['PHP_SELF']).PHP_EOL;
        self::assertStringContainsString($expectedOutput, $output->fetch());
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testDevWarningSuppressedForSelfUpdate(): void
    {
        if (Platform::isWindows()) {
            $this->markTestSkipped('Does not run on windows');
        }

        $application = new Application;
        // Compatibility layer for symfony/console <7.4
        // @phpstan-ignore method.notFound, function.alreadyNarrowedType, method.deprecated
        method_exists($application, 'addCommand') ? $application->addCommand(new \Composer\Command\SelfUpdateCommand) : $application->add(new \Composer\Command\SelfUpdateCommand);

        if (!defined('COMPOSER_DEV_WARNING_TIME')) {
            define('COMPOSER_DEV_WARNING_TIME', time() - 1);
        }

        $output = new BufferedOutput();
        $application->doRun(new ArrayInput(['command' => 'self-update']), $output);

        self::assertSame(
            'This instance of Composer does not have the self-update command.'.PHP_EOL.
            'This could be due to a number of reasons, such as Composer being installed as a system package on your OS, or Composer being installed as a package in the current project.'.PHP_EOL,
            $output->fetch()
        );
    }

    /**
     * @runInSeparateProcess
     * @see https://github.com/composer/composer/issues/12107
     */
    public function testProcessIsolationWorksMultipleTimes(): void
    {
        $application = new Application;
        // Compatibility layer for symfony/console <7.4
        // @phpstan-ignore method.notFound, function.alreadyNarrowedType, method.deprecated
        method_exists($application, 'addCommand') ? $application->addCommand(new AboutCommand) : $application->add(new AboutCommand);
        self::assertSame(0, $application->doRun(new ArrayInput(['command' => 'about']), new BufferedOutput()));
        self::assertSame(0, $application->doRun(new ArrayInput(['command' => 'about']), new BufferedOutput()));
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testNoPluginsDisablesPluginsWhenScriptCommandsExist(): void
    {
        $dir = $this->initTempComposer([
            'scripts' => [
                'my-script' => 'echo hello',
            ],
        ]);

        $application = new Application();
        $application->setAutoExit(false);
        $application->setCatchExceptions(false);
        // @phpstan-ignore function.alreadyNarrowedType
        if (method_exists($application, 'setCatchErrors')) {
            $application->setCatchErrors(false);
        }

        // Run list command with --no-plugins, this triggers script command registration which previously
        // created a Composer instance with plugins enabled regardless of the --no-plugins flag
        $application->doRun(new ArrayInput(['command' => 'list', '--no-plugins' => true]), new BufferedOutput());

        $composer = $application->getComposer(false);
        self::assertNotNull($composer, 'Composer instance should have been created during script command registration');
        self::assertTrue($composer->getPluginManager()->arePluginsDisabled('local'), 'Plugins should be disabled when --no-plugins is used');
        self::assertTrue($composer->getPluginManager()->arePluginsDisabled('global'), 'Global plugins should be disabled when --no-plugins is used');
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     * @see https://github.com/composer/composer/issues/12802
     */
    public function testScriptCommandTakesPriorityOverAbbreviatedBuiltinCommand(): void
    {
        $this->initTempComposer([
            'scripts' => [
                'check' => 'echo hello',
            ],
        ]);

        $application = new Application();
        $application->setAutoExit(false);
        $application->setCatchExceptions(false);
        // @phpstan-ignore function.alreadyNarrowedType
        if (method_exists($application, 'setCatchErrors')) {
            $application->setCatchErrors(false);
        }

        $appOutput = new BufferedOutput();
        $exitCode = $application->doRun(new ArrayInput(['command' => 'check']), $appOutput);

        self::assertSame(0, $exitCode, 'Script command should have run successfully');
        self::assertStringContainsString('hello', $appOutput->fetch(), 'The "check" script should have been executed instead of the check-platform-reqs command');
    }

    /**
     * @dataProvider provideTelemetryCommandNames
     */
    public function testGetTelemetryCommandName(SymfonyCommand $command, string $expected): void
    {
        $method = new \ReflectionMethod(Application::class, 'getTelemetryCommandName');
        (\PHP_VERSION_ID < 80100) and $method->setAccessible(true);

        self::assertSame($expected, $method->invoke(null, $command));
    }

    /**
     * @return array<string, array{SymfonyCommand, string}>
     */
    public static function provideTelemetryCommandNames(): array
    {
        return [
            // built-in Composer command reports its own name
            'composer command' => [new AboutCommand(), 'about'],
            // composer.json script aliases are reported generically as "script"
            'script alias' => [new ScriptAliasCommand('myscript', null), 'script'],
            // Symfony's built-in console commands report their own name
            'symfony builtin' => [new HelpCommand(), 'help'],
        ];
    }
}
