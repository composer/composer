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

use Composer\IO\ConsoleIO;
use Composer\Pcre\Preg;
use Composer\Test\TestCase;
use Symfony\Component\Console\Output\OutputInterface;

class ConsoleIOTest extends TestCase
{
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
        ];
    }
}
