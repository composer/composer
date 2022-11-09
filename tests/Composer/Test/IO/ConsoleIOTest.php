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

        $this->assertTrue($consoleIO->isInteractive());
        $this->assertFalse($consoleIO->isInteractive());
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
            ->withConsecutive(
                [$this->equalTo('something (<question>strlen = 23</question>)')],
                [$this->equalTo(str_repeat("\x08", 23)), $this->equalTo(false)],
                [$this->equalTo('shorter (<comment>12</comment>)'), $this->equalTo(false)],
                [$this->equalTo(str_repeat(' ', 11)), $this->equalTo(false)],
                [$this->equalTo(str_repeat("\x08", 11)), $this->equalTo(false)],
                [$this->equalTo(str_repeat("\x08", 12)), $this->equalTo(false)],
                [$this->equalTo('something longer than initial (<info>34</info>)')]
            );

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
        $this->assertEquals(['1'], $result);
    }

    public function testSetAndgetAuthentication(): void
    {
        $inputMock = $this->getMockBuilder('Symfony\Component\Console\Input\InputInterface')->getMock();
        $outputMock = $this->getMockBuilder('Symfony\Component\Console\Output\OutputInterface')->getMock();
        $helperMock = $this->getMockBuilder('Symfony\Component\Console\Helper\HelperSet')->getMock();

        $consoleIO = new ConsoleIO($inputMock, $outputMock, $helperMock);
        $consoleIO->setAuthentication('repoName', 'l3l0', 'passwd');

        $this->assertEquals(
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

        $this->assertEquals(
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

        $this->assertTrue($consoleIO->hasAuthentication('repoName'));
        $this->assertFalse($consoleIO->hasAuthentication('repoName2'));
    }
}
