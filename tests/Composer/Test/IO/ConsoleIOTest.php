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

namespace Composer\Test\IO;

use Composer\Console\ProgramStatus;
use Composer\Console\OscHelper;
use Composer\Console\Osc7501ProgramStatusEncoder;
use Composer\Util\Platform;
use Composer\IO\ConsoleIO;
use Composer\Pcre\Preg;
use Composer\Test\TestCase;
use Symfony\Component\Console\Helper\HelperSet;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;

class ConsoleIOTest extends TestCase
{
    /** @var string|false */
    private $originalTerminalStatus;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalTerminalStatus = Platform::getEnv('COMPOSER_TERMINAL_STATUS');
        Platform::putEnv('COMPOSER_TERMINAL_STATUS', '7501');
    }

    protected function tearDown(): void
    {
        if ($this->originalTerminalStatus === false) {
            Platform::clearEnv('COMPOSER_TERMINAL_STATUS');
        } else {
            Platform::putEnv('COMPOSER_TERMINAL_STATUS', $this->originalTerminalStatus);
        }
        parent::tearDown();
    }

    /** @dataProvider terminalStatusProvider */
    public function testSelectedStatusProtocols(string $setting, string $expected): void
    {
        Platform::putEnv('COMPOSER_TERMINAL_STATUS', $setting);
        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true);
        $io = new ConsoleIO(new ArrayInput([]), $output, new HelperSet());
        // Selection stays fixed for the lifetime of this IO instance.
        Platform::putEnv('COMPOSER_TERMINAL_STATUS', 'off');
        $io->writeProgramStatus(ProgramStatus::working()->withProgress(42));

        self::assertSame($expected, $output->fetch());
    }

    public static function terminalStatusProvider(): array
    {
        $rich = "\033]7501;state=working:app=composer:progress=42\033\\";
        $progress = "\033]9;4;1;42\033\\";

        return [
            ['off', ''],
            ['7501', $rich],
            ['9;4', $progress],
            ['7501,9;4', $progress.$rich],
        ];
    }

    /** @dataProvider suppressedStatusProvider */
    public function testExplicitProtocolsRespectOutputSettings(int $verbosity, bool $decorated): void
    {
        Platform::putEnv('COMPOSER_TERMINAL_STATUS', '7501,9;4');
        $output = new BufferedOutput($verbosity, $decorated);
        $io = new ConsoleIO(new ArrayInput([]), $output, new HelperSet());
        $io->writeProgramStatus(ProgramStatus::working());

        self::assertSame('', $output->fetch());
    }

    public static function suppressedStatusProvider(): array
    {
        return [[OutputInterface::VERBOSITY_QUIET, true], [OutputInterface::VERBOSITY_NORMAL, false]];
    }

    public function testExplicitProtocolsRespectRedirectedStderr(): void
    {
        Platform::putEnv('COMPOSER_TERMINAL_STATUS', '7501,9;4');
        $stream = fopen('php://memory', 'w+');
        self::assertIsResource($stream);
        try {
            $stderr = new StreamOutput($stream, OutputInterface::VERBOSITY_NORMAL, true);
            $output = $this->getMockBuilder('Symfony\Component\Console\Output\ConsoleOutputInterface')->getMock();
            $output->method('getErrorOutput')->willReturn($stderr);
            $output->expects($this->never())->method('write');
            $io = new ConsoleIO(new ArrayInput([]), $output, new HelperSet());
            $io->writeProgramStatus(ProgramStatus::working());
            rewind($stream);
            self::assertSame('', stream_get_contents($stream));
        } finally {
            fclose($stream);
        }
    }

    public function testOscHelperIsRegisteredAndReusedAcrossOutputs(): void
    {
        $helperSet = new HelperSet();
        $firstOutput = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true);
        $firstIO = new ConsoleIO(new ArrayInput([]), $firstOutput, $helperSet);
        $helper = $helperSet->get('osc');
        self::assertInstanceOf(OscHelper::class, $helper);
        self::assertSame($helperSet, $helper->getHelperSet());

        $secondOutput = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true);
        $secondIO = new ConsoleIO(new ArrayInput([]), $secondOutput, $helperSet);
        self::assertSame($helper, $helperSet->get('osc'));
        $firstIO->writeOsc('2', 'First');
        $secondIO->writeOsc('2', 'Second');
        $firstIO->writeOsc('2', 'First again');

        self::assertSame("\033]2;First\033\\\033]2;First again\033\\", $firstOutput->fetch());
        self::assertSame("\033]2;Second\033\\", $secondOutput->fetch());
    }

    public function testOscUsesStderrWithoutProfilingPrefixes(): void
    {
        $stderr = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true);
        $output = $this->getMockBuilder('Symfony\Component\Console\Output\ConsoleOutputInterface')->getMock();
        $output->method('getErrorOutput')->willReturn($stderr);
        $output->expects($this->never())->method('write');
        $io = new ConsoleIO(new ArrayInput([]), $output, new HelperSet());
        $io->enableDebugging(microtime(true));
        $io->enableTimestamps();
        $io->writeOsc('7501', 'state=working');

        self::assertSame("\033]7501;state=working\033\\", $stderr->fetch());
    }

    /**
     * @dataProvider statusQuestionProvider
     */
    public function testQuestionReportsBlockedAndRestoresProgress(string $method, string $kind): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true);
        $helper = $this->getMockBuilder(QuestionHelper::class)->getMock();
        $helper->method('getName')->willReturn('question');
        $helper->expects($this->once())->method('ask')->willReturnCallback(static function () use ($output, $kind) {
            self::assertSame("\033]7501;state=blocked:app=composer:kind=".$kind.':msg='.base64_encode('Continue?')."\033\\", $output->fetch());

            return true;
        });
        $io = new ConsoleIO(new ArrayInput([]), $output, new HelperSet([$helper]));
        $status = ProgramStatus::working('Installing')->withProgress(42);
        $io->writeProgramStatus($status);
        $output->fetch();
        if ($method === 'askConfirmation') {
            $io->askConfirmation('<info>Continue?</info>');
        } elseif ($method === 'askAndHideAnswer') {
            $io->askAndHideAnswer('<info>Continue?</info>');
        } else {
            $io->ask('<info>Continue?</info>');
        }

        self::assertSame("\033]7501;".(new Osc7501ProgramStatusEncoder())->encode($status)."\033\\", $output->fetch());
    }

    public static function statusQuestionProvider(): array
    {
        return [
            ['ask', Osc7501ProgramStatusEncoder::KIND_QUESTION],
            ['askConfirmation', Osc7501ProgramStatusEncoder::KIND_PERMISSION],
            ['askAndHideAnswer', Osc7501ProgramStatusEncoder::KIND_AUTH],
        ];
    }

    public function testNonInteractiveQuestionDoesNotReportBlocked(): void
    {
        $input = new ArrayInput([]);
        $input->setInteractive(false);
        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true);
        $io = new ConsoleIO($input, $output, new HelperSet([new QuestionHelper()]));

        self::assertSame('default', $io->ask('Question?', 'default'));
        self::assertSame('', $output->fetch());
    }

    public function testFailedQuestionRestoresWorkingStatus(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true);
        $helper = $this->getMockBuilder(QuestionHelper::class)->getMock();
        $helper->method('getName')->willReturn('question');
        $helper->method('ask')->willThrowException(new \RuntimeException('No input'));
        $io = new ConsoleIO(new ArrayInput([]), $output, new HelperSet([$helper]));
        try {
            $io->ask('Question?');
            self::fail('Expected question failure');
        } catch (\RuntimeException $e) {
            self::assertSame('No input', $e->getMessage());
        }
        self::assertStringEndsWith("\033]7501;state=working:app=composer\033\\", $output->fetch());
    }

    public function testIsInteractive(): void
    {
        $inputMock = $this->getMockBuilder('Symfony\Component\Console\Input\InputInterface')->getMock();
        $inputMock->expects($this->exactly(2))
            ->method('isInteractive')
            ->willReturnOnConsecutiveCalls(
                true,
                false
            );

        $outputMock = $this->getMockBuilder('Symfony\Component\Console\Output\OutputInterface')->getMock();
        $helperMock = $this->getMockBuilder('Symfony\Component\Console\Helper\HelperSet')->getMock();

        $consoleIO = new ConsoleIO($inputMock, $outputMock, $helperMock);

        self::assertTrue($consoleIO->isInteractive());
        self::assertFalse($consoleIO->isInteractive());
    }

    public function testWrite(): void
    {
        $inputMock = $this->getMockBuilder('Symfony\Component\Console\Input\InputInterface')->getMock();
        $outputMock = $this->getMockBuilder('Symfony\Component\Console\Output\OutputInterface')->getMock();
        $outputMock->expects($this->once())
            ->method('getVerbosity')
            ->willReturn(OutputInterface::VERBOSITY_NORMAL);
        $outputMock->expects($this->once())
            ->method('write')
            ->with($this->equalTo('some information about something'), $this->equalTo(false));
        $helperMock = $this->getMockBuilder('Symfony\Component\Console\Helper\HelperSet')->getMock();

        $consoleIO = new ConsoleIO($inputMock, $outputMock, $helperMock);
        $consoleIO->write('some information about something', false);
    }

    public function testWriteError(): void
    {
        $inputMock = $this->getMockBuilder('Symfony\Component\Console\Input\InputInterface')->getMock();
        $outputMock = $this->getMockBuilder('Symfony\Component\Console\Output\ConsoleOutputInterface')->getMock();
        $outputMock->expects($this->once())
            ->method('getVerbosity')
            ->willReturn(OutputInterface::VERBOSITY_NORMAL);
        $outputMock->expects($this->once())
            ->method('getErrorOutput')
            ->willReturn($outputMock);
        $outputMock->expects($this->once())
            ->method('write')
            ->with($this->equalTo('some information about something'), $this->equalTo(false));
        $helperMock = $this->getMockBuilder('Symfony\Component\Console\Helper\HelperSet')->getMock();

        $consoleIO = new ConsoleIO($inputMock, $outputMock, $helperMock);
        $consoleIO->writeError('some information about something', false);
    }

    public function testWriteWithMultipleLineStringWhenDebugging(): void
    {
        $inputMock = $this->getMockBuilder('Symfony\Component\Console\Input\InputInterface')->getMock();
        $outputMock = $this->getMockBuilder('Symfony\Component\Console\Output\OutputInterface')->getMock();
        $outputMock->expects($this->once())
            ->method('getVerbosity')
            ->willReturn(OutputInterface::VERBOSITY_NORMAL);
        $outputMock->expects($this->once())
            ->method('write')
            ->with(
                $this->callback(static function ($messages): bool {
                    $result = Preg::isMatch("[(.*)/(.*) First line]", $messages[0]);
                    $result = $result && Preg::isMatch("[(.*)/(.*) Second line]", $messages[1]);

                    return $result;
                }),
                $this->equalTo(false)
            );
        $helperMock = $this->getMockBuilder('Symfony\Component\Console\Helper\HelperSet')->getMock();

        $consoleIO = new ConsoleIO($inputMock, $outputMock, $helperMock);
        $startTime = microtime(true);
        $consoleIO->enableDebugging($startTime);

        $example = explode('\n', 'First line\nSecond lines');
        $consoleIO->write($example, false);
    }

    public function testOverwrite(): void
    {
        $inputMock = $this->getMockBuilder('Symfony\Component\Console\Input\InputInterface')->getMock();
        $outputMock = $this->getMockBuilder('Symfony\Component\Console\Output\OutputInterface')->getMock();

        $outputMock->expects($this->any())
            ->method('getVerbosity')
            ->willReturn(OutputInterface::VERBOSITY_NORMAL);
        $outputMock->method('isDecorated')->willReturn(true);
        $outputMock->expects($this->atLeast(7))
            ->method('write')
            ->willReturnCallback(static function (...$args) {
                static $series = null;

                if ($series === null) {
                    $series = [
                        ['something (<question>strlen = 23</question>)', true],
                        [str_repeat("\x08", 23), false],
                        ['shorter (<comment>12</comment>)', false],
                        [str_repeat(' ', 11), false],
                        [str_repeat("\x08", 11), false],
                        [str_repeat("\x08", 12), false],
                        ['something longer than initial (<info>34</info>)', false],
                    ];
                }

                if (count($series) > 0) {
                    self::assertSame(array_shift($series), [$args[0], $args[1]]);
                }
            });

        $helperMock = $this->getMockBuilder('Symfony\Component\Console\Helper\HelperSet')->getMock();

        $consoleIO = new ConsoleIO($inputMock, $outputMock, $helperMock);
        $consoleIO->write('something (<question>strlen = 23</question>)');
        $consoleIO->overwrite('shorter (<comment>12</comment>)', false);
        $consoleIO->overwrite('something longer than initial (<info>34</info>)');
    }

    public function testOverwriteErrorWithoutDecorationWritesNoBackspaces(): void
    {
        $inputMock = $this->getMockBuilder('Symfony\Component\Console\Input\InputInterface')->getMock();
        $outputMock = $this->getMockBuilder('Symfony\Component\Console\Output\ConsoleOutputInterface')->getMock();
        $outputMock->method('getVerbosity')->willReturn(OutputInterface::VERBOSITY_NORMAL);
        $outputMock->method('getErrorOutput')->willReturn($outputMock);
        $outputMock->method('isDecorated')->willReturn(false);

        $written = '';
        $outputMock->method('write')->willReturnCallback(static function ($messages) use (&$written): void {
            $written .= implode('', (array) $messages);
        });

        $helperMock = $this->getMockBuilder('Symfony\Component\Console\Helper\HelperSet')->getMock();

        $consoleIO = new ConsoleIO($inputMock, $outputMock, $helperMock);
        $consoleIO->writeError('Loading composer repositories with package information');
        $consoleIO->overwriteError('', false);

        self::assertStringNotContainsString("\x08", $written);
        self::assertStringContainsString('Loading composer repositories with package information', $written);
    }

    public function testOverwriteErrorChecksTheErrorOutputDecoration(): void
    {
        // stdout is a decorated terminal but the error output is redirected (not decorated),
        // so overwriteError must write a plain line and no backspaces to the error output
        $inputMock = $this->getMockBuilder('Symfony\Component\Console\Input\InputInterface')->getMock();

        $written = '';
        $errorOutput = $this->getMockBuilder('Symfony\Component\Console\Output\OutputInterface')->getMock();
        $errorOutput->method('isDecorated')->willReturn(false);
        $errorOutput->method('write')->willReturnCallback(static function ($messages) use (&$written): void {
            $written .= implode('', (array) $messages);
        });

        $outputMock = $this->getMockBuilder('Symfony\Component\Console\Output\ConsoleOutputInterface')->getMock();
        $outputMock->method('getVerbosity')->willReturn(OutputInterface::VERBOSITY_NORMAL);
        $outputMock->method('isDecorated')->willReturn(true);
        $outputMock->method('getErrorOutput')->willReturn($errorOutput);

        $helperMock = $this->getMockBuilder('Symfony\Component\Console\Helper\HelperSet')->getMock();

        $consoleIO = new ConsoleIO($inputMock, $outputMock, $helperMock);
        $consoleIO->writeError('Loading composer repositories with package information');
        $consoleIO->overwriteError('Reading composer.json of acme/foo (1.0.0)', false);

        self::assertStringNotContainsString("\x08", $written);
        self::assertStringContainsString('Reading composer.json of acme/foo (1.0.0)', $written);
    }

    public function testAsk(): void
    {
        $inputMock = $this->getMockBuilder('Symfony\Component\Console\Input\InputInterface')->getMock();
        $outputMock = $this->getMockBuilder('Symfony\Component\Console\Output\OutputInterface')->getMock();
        $helperMock = $this->getMockBuilder('Symfony\Component\Console\Helper\QuestionHelper')->getMock();
        $setMock = $this->getMockBuilder('Symfony\Component\Console\Helper\HelperSet')->getMock();

        $helperMock
            ->expects($this->once())
            ->method('ask')
            ->with(
                $this->isInstanceOf('Symfony\Component\Console\Input\InputInterface'),
                $this->isInstanceOf('Symfony\Component\Console\Output\OutputInterface'),
                $this->isInstanceOf('Symfony\Component\Console\Question\Question')
            )
        ;

        $setMock
            ->expects($this->once())
            ->method('get')
            ->with($this->equalTo('question'))
            ->will($this->returnValue($helperMock))
        ;

        $consoleIO = new ConsoleIO($inputMock, $outputMock, $setMock);
        $consoleIO->ask('Why?', 'default');
    }

    public function testAskConfirmation(): void
    {
        $inputMock = $this->getMockBuilder('Symfony\Component\Console\Input\InputInterface')->getMock();
        $outputMock = $this->getMockBuilder('Symfony\Component\Console\Output\OutputInterface')->getMock();
        $helperMock = $this->getMockBuilder('Symfony\Component\Console\Helper\QuestionHelper')->getMock();
        $setMock = $this->getMockBuilder('Symfony\Component\Console\Helper\HelperSet')->getMock();

        $helperMock
            ->expects($this->once())
            ->method('ask')
            ->with(
                $this->isInstanceOf('Symfony\Component\Console\Input\InputInterface'),
                $this->isInstanceOf('Symfony\Component\Console\Output\OutputInterface'),
                $this->isInstanceOf('Composer\Question\StrictConfirmationQuestion')
            )
        ;

        $setMock
            ->expects($this->once())
            ->method('get')
            ->with($this->equalTo('question'))
            ->will($this->returnValue($helperMock))
        ;

        $consoleIO = new ConsoleIO($inputMock, $outputMock, $setMock);
        $consoleIO->askConfirmation('Why?', false);
    }

    public function testAskAndValidate(): void
    {
        $inputMock = $this->getMockBuilder('Symfony\Component\Console\Input\InputInterface')->getMock();
        $outputMock = $this->getMockBuilder('Symfony\Component\Console\Output\OutputInterface')->getMock();
        $helperMock = $this->getMockBuilder('Symfony\Component\Console\Helper\QuestionHelper')->getMock();
        $setMock = $this->getMockBuilder('Symfony\Component\Console\Helper\HelperSet')->getMock();

        $helperMock
            ->expects($this->once())
            ->method('ask')
            ->with(
                $this->isInstanceOf('Symfony\Component\Console\Input\InputInterface'),
                $this->isInstanceOf('Symfony\Component\Console\Output\OutputInterface'),
                $this->isInstanceOf('Symfony\Component\Console\Question\Question')
            )
        ;

        $setMock
            ->expects($this->once())
            ->method('get')
            ->with($this->equalTo('question'))
            ->will($this->returnValue($helperMock))
        ;

        $validator = static function ($value): bool {
            return true;
        };
        $consoleIO = new ConsoleIO($inputMock, $outputMock, $setMock);
        $consoleIO->askAndValidate('Why?', $validator, 10, 'default');
    }

    public function testSelect(): void
    {
        $inputMock = $this->getMockBuilder('Symfony\Component\Console\Input\InputInterface')->getMock();
        $outputMock = $this->getMockBuilder('Symfony\Component\Console\Output\OutputInterface')->getMock();
        $helperMock = $this->getMockBuilder('Symfony\Component\Console\Helper\QuestionHelper')->getMock();
        $setMock = $this->getMockBuilder('Symfony\Component\Console\Helper\HelperSet')->getMock();

        $helperMock
            ->expects($this->once())
            ->method('ask')
            ->with(
                $this->isInstanceOf('Symfony\Component\Console\Input\InputInterface'),
                $this->isInstanceOf('Symfony\Component\Console\Output\OutputInterface'),
                $this->isInstanceOf('Symfony\Component\Console\Question\Question')
            )
            ->will($this->returnValue(['item2']));

        $setMock
            ->expects($this->once())
            ->method('get')
            ->with($this->equalTo('question'))
            ->will($this->returnValue($helperMock))
        ;

        $consoleIO = new ConsoleIO($inputMock, $outputMock, $setMock);
        $result = $consoleIO->select('Select item', ["item1", "item2"], 'item1', false, "Error message", true);
        self::assertEquals(['1'], $result);
    }

    public function testSetAndGetAuthentication(): void
    {
        $inputMock = $this->getMockBuilder('Symfony\Component\Console\Input\InputInterface')->getMock();
        $outputMock = $this->getMockBuilder('Symfony\Component\Console\Output\OutputInterface')->getMock();
        $helperMock = $this->getMockBuilder('Symfony\Component\Console\Helper\HelperSet')->getMock();

        $consoleIO = new ConsoleIO($inputMock, $outputMock, $helperMock);
        $consoleIO->setAuthentication('repoName', 'l3l0', 'passwd');

        self::assertEquals(
            ['username' => 'l3l0', 'password' => 'passwd'],
            $consoleIO->getAuthentication('repoName')
        );
    }

    public function testGetAuthenticationWhenDidNotSet(): void
    {
        $inputMock = $this->getMockBuilder('Symfony\Component\Console\Input\InputInterface')->getMock();
        $outputMock = $this->getMockBuilder('Symfony\Component\Console\Output\OutputInterface')->getMock();
        $helperMock = $this->getMockBuilder('Symfony\Component\Console\Helper\HelperSet')->getMock();

        $consoleIO = new ConsoleIO($inputMock, $outputMock, $helperMock);

        self::assertEquals(
            ['username' => null, 'password' => null],
            $consoleIO->getAuthentication('repoName')
        );
    }

    public function testHasAuthentication(): void
    {
        $inputMock = $this->getMockBuilder('Symfony\Component\Console\Input\InputInterface')->getMock();
        $outputMock = $this->getMockBuilder('Symfony\Component\Console\Output\OutputInterface')->getMock();
        $helperMock = $this->getMockBuilder('Symfony\Component\Console\Helper\HelperSet')->getMock();

        $consoleIO = new ConsoleIO($inputMock, $outputMock, $helperMock);
        $consoleIO->setAuthentication('repoName', 'l3l0', 'passwd');

        self::assertTrue($consoleIO->hasAuthentication('repoName'));
        self::assertFalse($consoleIO->hasAuthentication('repoName2'));
    }

    /**
     * @dataProvider sanitizeProvider
     * @param string|string[] $input
     * @param string|string[] $expected
     */
    public function testSanitize($input, bool $allowNewlines, $expected): void
    {
        self::assertSame($expected, ConsoleIO::sanitize($input, $allowNewlines));
    }

    /**
     * @return array<string, array{input: string|string[], allowNewlines: bool, expected: string|string[]}>
     */
    public static function sanitizeProvider(): array
    {
        return [
            // String input with allowNewlines=true
            'string with \n allowed' => [
                'input' => "Hello\nWorld",
                'allowNewlines' => true,
                'expected' => "Hello\nWorld",
            ],
            'string with \r\n allowed' => [
                'input' => "Hello\r\nWorld",
                'allowNewlines' => true,
                'expected' => "Hello\r\nWorld",
            ],
            'string with standalone \r removed' => [
                'input' => "Hello\rWorld",
                'allowNewlines' => true,
                'expected' => "HelloWorld",
            ],
            'string with escape sequence removed' => [
                'input' => "Hello\x1B[31mWorld",
                'allowNewlines' => true,
                'expected' => "HelloWorld",
            ],
            'string with control chars removed' => [
                'input' => "Hello\x01\x08\x09World",
                'allowNewlines' => true,
                'expected' => "HelloWorld",
            ],
            'string with mixed control chars and newlines' => [
                'input' => "Line1\n\x1B[32mLine2\x08\rLine3",
                'allowNewlines' => true,
                'expected' => "Line1\nLine2Line3",
            ],
            'string with null bytes are allowed' => [
                'input' => "Hello\x00World",
                'allowNewlines' => true,
                'expected' => "Hello\x00World",
            ],

            // String input with allowNewlines=false
            'string with \n removed' => [
                'input' => "Hello\nWorld",
                'allowNewlines' => false,
                'expected' => "HelloWorld",
            ],
            'string with \r\n removed' => [
                'input' => "Hello\r\nWorld",
                'allowNewlines' => false,
                'expected' => "HelloWorld",
            ],
            'string with escape sequence removed (no newlines)' => [
                'input' => "Hello\x1B[31mWorld",
                'allowNewlines' => false,
                'expected' => "HelloWorld",
            ],
            'string with all control chars removed' => [
                'input' => "Hello\x01\x08\x09\x0A\x0DWorld",
                'allowNewlines' => false,
                'expected' => "HelloWorld",
            ],

            // Array input with allowNewlines=true
            'array with newlines allowed' => [
                'input' => ["Hello\nWorld", "Foo\r\nBar"],
                'allowNewlines' => true,
                'expected' => ["Hello\nWorld", "Foo\r\nBar"],
            ],
            'array with control chars removed' => [
                'input' => ["Hello\x1B[31mWorld", "Foo\x08Bar\r"],
                'allowNewlines' => true,
                'expected' => ["HelloWorld", "FooBar"],
            ],

            // Array input with allowNewlines=false
            'array with newlines removed' => [
                'input' => ["Hello\nWorld", "Foo\r\nBar"],
                'allowNewlines' => false,
                'expected' => ["HelloWorld", "FooBar"],
            ],
            'array with all control chars removed' => [
                'input' => ["Test\x01\x0A", "Data\x1B[m\x0D"],
                'allowNewlines' => false,
                'expected' => ["Test", "Data"],
            ],

            // Edge cases
            'empty string' => [
                'input' => '',
                'allowNewlines' => true,
                'expected' => '',
            ],
            'empty array' => [
                'input' => [],
                'allowNewlines' => true,
                'expected' => [],
            ],
            'string with no control chars' => [
                'input' => 'Hello World',
                'allowNewlines' => true,
                'expected' => 'Hello World',
            ],
            'string with unicode' => [
                'input' => "Hello 世界\nTest",
                'allowNewlines' => true,
                'expected' => "Hello 世界\nTest",
            ],

            // Various ANSI escape sequences
            'CSI with multiple parameters' => [
                'input' => "Text\x1B[1;31;40mColored\x1B[0mNormal",
                'allowNewlines' => true,
                'expected' => "TextColoredNormal",
            ],
            'CSI SGR reset' => [
                'input' => "Before\x1B[mAfter",
                'allowNewlines' => true,
                'expected' => "BeforeAfter",
            ],
            'CSI cursor positioning' => [
                'input' => "Line\x1B[2J\x1B[H\x1B[10;5HText",
                'allowNewlines' => true,
                'expected' => "LineText",
            ],
            'OSC with BEL terminator' => [
                'input' => "Text\x1B]0;Window Title\x07More",
                'allowNewlines' => true,
                'expected' => "TextMore",
            ],
            'OSC with ST terminator' => [
                'input' => "Text\x1B]2;Title\x1B\\More",
                'allowNewlines' => true,
                'expected' => "TextMore",
            ],
            'Simple ESC sequences' => [
                'input' => "Text\x1B7Saved\x1B8Restored\x1BcReset",
                'allowNewlines' => true,
                'expected' => "TextSavedRestoredReset",
            ],
            'ESC D (Index)' => [
                'input' => "Line1\x1BDLine2",
                'allowNewlines' => true,
                'expected' => "Line1Line2",
            ],
            'ESC E (Next Line)' => [
                'input' => "Line1\x1BELine2",
                'allowNewlines' => true,
                'expected' => "Line1Line2",
            ],
            'ESC M (Reverse Index)' => [
                'input' => "Text\x1BMMore",
                'allowNewlines' => true,
                'expected' => "TextMore",
            ],
            'ESC N (SS2) and ESC O (SS3)' => [
                'input' => "Text\x1BNchar\x1BOanother",
                'allowNewlines' => true,
                'expected' => "Textcharanother",
            ],
            'Multiple escape sequences in sequence' => [
                'input' => "\x1B[1m\x1B[31m\x1B[44mBold Red on Blue\x1B[0m",
                'allowNewlines' => true,
                'expected' => "Bold Red on Blue",
            ],
            'CSI with question mark (private mode)' => [
                'input' => "Text\x1B[?25lHidden\x1B[?25hVisible",
                'allowNewlines' => true,
                'expected' => "TextHiddenVisible",
            ],
            'CSI erase sequences' => [
                'input' => "Clear\x1B[2J\x1B[K\x1B[1KScreen",
                'allowNewlines' => true,
                'expected' => "ClearScreen",
            ],
            'Hyperlink OSC 8' => [
                'input' => "Click \x1B]8;;https://example.com\x1B\\here\x1B]8;;\x1B\\ for link",
                'allowNewlines' => true,
                'expected' => "Click here for link",
            ],
            'Mixed content with complex sequences' => [
                'input' => "\x1B[1;33mWarning:\x1B[0m File\x1B[31m not\x1B[0m found\n\x1B[2KRetrying...",
                'allowNewlines' => true,
                'expected' => "Warning: File not found\nRetrying...",
            ],
            // Malformed UTF-8 handling
            'malformed UTF-8 single byte' => [
                'input' => "Hello\xFFWorld",
                'allowNewlines' => true,
                'expected' => "Hello?World",
            ],
            'malformed UTF-8 multiple bytes' => [
                'input' => "Test\xC3\x28Data",
                'allowNewlines' => true,
                'expected' => "Test?(Data",
            ],
            'malformed UTF-8 with ANSI escape' => [
                'input' => "Line\xFF\x1B[31mColor\xFE",
                'allowNewlines' => true,
                'expected' => "Line?Color?",
            ],
            'malformed UTF-8 in array' => [
                'input' => ["Item\xFF", "Data\xC3\x28"],
                'allowNewlines' => true,
                'expected' => ["Item?", "Data?("],
            ],
            'valid UTF-8 unchanged' => [
                'input' => "Hello 世界 Test",
                'allowNewlines' => true,
                'expected' => "Hello 世界 Test",
            ],
            'mixed valid and invalid UTF-8' => [
                'input' => "Hello\xFF世界\xFETest",
                'allowNewlines' => true,
                'expected' => "Hello?世界?Test",
            ],
        ];
    }
}
