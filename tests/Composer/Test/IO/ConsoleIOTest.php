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

namespace Composer\Test\IO;

use Composer\IO\ConsoleIO;
use Composer\TestCase;

class ConsoleIOTest extends TestCase
{
    public function testIsInteractive()
    {
        $inputMock = $this->getMock('Symfony\Component\Console\Input\InputInterface');
        $inputMock->expects($this->at(0))
            ->method('isInteractive')
            ->will($this->returnValue(true));
        $inputMock->expects($this->at(1))
            ->method('isInteractive')
            ->will($this->returnValue(false));

        $outputMock = $this->getMock('Symfony\Component\Console\Output\OutputInterface');
        $helperMock = $this->getMock('Symfony\Component\Console\Helper\HelperSet');

        $consoleIO = new ConsoleIO($inputMock, $outputMock, $helperMock);

        $this->assertTrue($consoleIO->isInteractive());
        $this->assertFalse($consoleIO->isInteractive());
    }

    public function testWrite()
    {
        $inputMock = $this->getMock('Symfony\Component\Console\Input\InputInterface');
        $outputMock = $this->getMock('Symfony\Component\Console\Output\OutputInterface');
        $outputMock->expects($this->once())
            ->method('write')
            ->with($this->equalTo('some information about something'), $this->equalTo(false));
        $helperMock = $this->getMock('Symfony\Component\Console\Helper\HelperSet');

        $consoleIO = new ConsoleIO($inputMock, $outputMock, $helperMock);
        $consoleIO->write('some information about something', false);
    }

    public function testWriteError()
    {
        $inputMock = $this->getMock('Symfony\Component\Console\Input\InputInterface');
        $outputMock = $this->getMock('Symfony\Component\Console\Output\ConsoleOutputInterface');
        $outputMock->expects($this->once())
            ->method('getErrorOutput')
            ->willReturn($outputMock);
        $outputMock->expects($this->once())
            ->method('write')
            ->with($this->equalTo('some information about something'), $this->equalTo(false));
        $helperMock = $this->getMock('Symfony\Component\Console\Helper\HelperSet');

        $consoleIO = new ConsoleIO($inputMock, $outputMock, $helperMock);
        $consoleIO->writeError('some information about something', false);
    }

    public function testWriteWithMultipleLineStringWhenDebugging()
    {
        $inputMock = $this->getMock('Symfony\Component\Console\Input\InputInterface');
        $outputMock = $this->getMock('Symfony\Component\Console\Output\OutputInterface');
        $outputMock->expects($this->once())
            ->method('write')
            ->with(
                $this->callback(function ($messages) {
                    $result = preg_match("[(.*)/(.*) First line]", $messages[0]) > 0;
                    $result &= preg_match("[(.*)/(.*) Second line]", $messages[1]) > 0;

                    return $result;
                }),
                $this->equalTo(false)
            );
        $helperMock = $this->getMock('Symfony\Component\Console\Helper\HelperSet');

        $consoleIO = new ConsoleIO($inputMock, $outputMock, $helperMock);
        $startTime = microtime(true);
        $consoleIO->enableDebugging($startTime);

        $example = explode('\n', 'First line\nSecond lines');
        $consoleIO->write($example, false);
    }

    public function testOverwrite()
    {
        $inputMock = $this->getMock('Symfony\Component\Console\Input\InputInterface');
        $outputMock = $this->getMock('Symfony\Component\Console\Output\OutputInterface');

        $outputMock->expects($this->at(0))
            ->method('write')
            ->with($this->equalTo('something (<question>strlen = 23</question>)'));
        $outputMock->expects($this->at(1))
            ->method('isDecorated')
            ->willReturn(true);
        $outputMock->expects($this->at(2))
            ->method('write')
            ->with($this->equalTo(str_repeat("\x08", 23)), $this->equalTo(false));
        $outputMock->expects($this->at(3))
            ->method('write')
            ->with($this->equalTo('shorter (<comment>12</comment>)'), $this->equalTo(false));
        $outputMock->expects($this->at(4))
            ->method('write')
            ->with($this->equalTo(str_repeat(' ', 11)), $this->equalTo(false));
        $outputMock->expects($this->at(5))
            ->method('write')
            ->with($this->equalTo(str_repeat("\x08", 11)), $this->equalTo(false));
        $outputMock->expects($this->at(6))
            ->method('isDecorated')
            ->willReturn(true);
        $outputMock->expects($this->at(7))
            ->method('write')
            ->with($this->equalTo(str_repeat("\x08", 12)), $this->equalTo(false));
        $outputMock->expects($this->at(8))
            ->method('write')
            ->with($this->equalTo('something longer than initial (<info>34</info>)'));

        $helperMock = $this->getMock('Symfony\Component\Console\Helper\HelperSet');

        $consoleIO = new ConsoleIO($inputMock, $outputMock, $helperMock);
        $consoleIO->write('something (<question>strlen = 23</question>)');
        $consoleIO->overwrite('shorter (<comment>12</comment>)', false);
        $consoleIO->overwrite('something longer than initial (<info>34</info>)');
    }

    public function testAsk()
    {
        $inputMock = $this->getMock('Symfony\Component\Console\Input\InputInterface');
        $outputMock = $this->getMock('Symfony\Component\Console\Output\OutputInterface');
        $dialogMock = $this->getMock('Symfony\Component\Console\Helper\DialogHelper');
        $helperMock = $this->getMock('Symfony\Component\Console\Helper\HelperSet');

        $dialogMock->expects($this->once())
            ->method('ask')
            ->with($this->isInstanceOf('Symfony\Component\Console\Output\OutputInterface'),
                $this->equalTo('Why?'),
                $this->equalTo('default'));
        $helperMock->expects($this->once())
            ->method('get')
            ->with($this->equalTo('dialog'))
            ->will($this->returnValue($dialogMock));

        $consoleIO = new ConsoleIO($inputMock, $outputMock, $helperMock);
        $consoleIO->ask('Why?', 'default');
    }

    public function testAskConfirmation()
    {
        $inputMock = $this->getMock('Symfony\Component\Console\Input\InputInterface');
        $outputMock = $this->getMock('Symfony\Component\Console\Output\OutputInterface');
        $dialogMock = $this->getMock('Symfony\Component\Console\Helper\DialogHelper');
        $helperMock = $this->getMock('Symfony\Component\Console\Helper\HelperSet');

        $dialogMock->expects($this->once())
            ->method('askConfirmation')
            ->with($this->isInstanceOf('Symfony\Component\Console\Output\OutputInterface'),
                $this->equalTo('Why?'),
                $this->equalTo('default'));
        $helperMock->expects($this->once())
            ->method('get')
            ->with($this->equalTo('dialog'))
            ->will($this->returnValue($dialogMock));

        $consoleIO = new ConsoleIO($inputMock, $outputMock, $helperMock);
        $consoleIO->askConfirmation('Why?', 'default');
    }

    public function testAskAndValidate()
    {
        $inputMock = $this->getMock('Symfony\Component\Console\Input\InputInterface');
        $outputMock = $this->getMock('Symfony\Component\Console\Output\OutputInterface');
        $dialogMock = $this->getMock('Symfony\Component\Console\Helper\DialogHelper');
        $helperMock = $this->getMock('Symfony\Component\Console\Helper\HelperSet');

        $dialogMock->expects($this->once())
            ->method('askAndValidate')
            ->with($this->isInstanceOf('Symfony\Component\Console\Output\OutputInterface'),
                $this->equalTo('Why?'),
                $this->equalTo('validator'),
                $this->equalTo(10),
                $this->equalTo('default'));
        $helperMock->expects($this->once())
            ->method('get')
            ->with($this->equalTo('dialog'))
            ->will($this->returnValue($dialogMock));

        $consoleIO = new ConsoleIO($inputMock, $outputMock, $helperMock);
        $consoleIO->askAndValidate('Why?', 'validator', 10, 'default');
    }

    public function testSetAndgetAuthentication()
    {
        $inputMock = $this->getMock('Symfony\Component\Console\Input\InputInterface');
        $outputMock = $this->getMock('Symfony\Component\Console\Output\OutputInterface');
        $helperMock = $this->getMock('Symfony\Component\Console\Helper\HelperSet');

        $consoleIO = new ConsoleIO($inputMock, $outputMock, $helperMock);
        $consoleIO->setAuthentication('repoName', 'l3l0', 'passwd');

        $this->assertEquals(
            array('username' => 'l3l0', 'password' => 'passwd'),
            $consoleIO->getAuthentication('repoName')
        );
    }

    public function testgetAuthenticationWhenDidNotSet()
    {
        $inputMock = $this->getMock('Symfony\Component\Console\Input\InputInterface');
        $outputMock = $this->getMock('Symfony\Component\Console\Output\OutputInterface');
        $helperMock = $this->getMock('Symfony\Component\Console\Helper\HelperSet');

        $consoleIO = new ConsoleIO($inputMock, $outputMock, $helperMock);

        $this->assertEquals(
            array('username' => null, 'password' => null),
            $consoleIO->getAuthentication('repoName')
        );
    }

    public function testhasAuthentication()
    {
        $inputMock = $this->getMock('Symfony\Component\Console\Input\InputInterface');
        $outputMock = $this->getMock('Symfony\Component\Console\Output\OutputInterface');
        $helperMock = $this->getMock('Symfony\Component\Console\Helper\HelperSet');

        $consoleIO = new ConsoleIO($inputMock, $outputMock, $helperMock);
        $consoleIO->setAuthentication('repoName', 'l3l0', 'passwd');

        $this->assertTrue($consoleIO->hasAuthentication('repoName'));
        $this->assertFalse($consoleIO->hasAuthentication('repoName2'));
    }
}
