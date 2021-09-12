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

namespace Composer\Test;

use Composer\Console\Application;
use Composer\XdebugHandler\XdebugHandler;
use Symfony\Component\Console\Output\OutputInterface;

class ApplicationTest extends TestCase
{
    public function tearDown()
    {
        parent::tearDown();

        putenv('COMPOSER_NO_INTERACTION');
    }

    public function testDevWarning()
    {
        $application = new Application;

        $inputMock = $this->getMockBuilder('Symfony\Component\Console\Input\InputInterface')->getMock();
        $outputMock = $this->getMockBuilder('Symfony\Component\Console\Output\OutputInterface')->getMock();

        putenv('COMPOSER_NO_INTERACTION=1');

        $index = 0;
        $inputMock->expects($this->at($index++))
            ->method('hasParameterOption')
            ->with($this->equalTo('--no-plugins'))
            ->will($this->returnValue(true));

        $inputMock->expects($this->at($index++))
            ->method('setInteractive')
            ->with($this->equalTo(false));

        $inputMock->expects($this->at($index++))
            ->method('hasParameterOption')
            ->with($this->equalTo('--no-cache'))
            ->will($this->returnValue(false));

        $inputMock->expects($this->at($index++))
            ->method('getParameterOption')
            ->with($this->equalTo(array('--working-dir', '-d')))
            ->will($this->returnValue(false));

        $inputMock->expects($this->any())
            ->method('getFirstArgument')
            ->will($this->returnValue('show'));

        $index = 0;
        $outputMock->expects($this->at($index++))
            ->method("write");

        if (XdebugHandler::isXdebugActive()) {
            $outputMock->expects($this->at($index++))
                ->method("getVerbosity")
                ->willReturn(OutputInterface::VERBOSITY_NORMAL);

            $outputMock->expects($this->at($index++))
                ->method("write")
                ->with($this->equalTo('<warning>Composer is operating slower than normal because you have Xdebug enabled. See https://getcomposer.org/xdebug</warning>'));
        }

        $outputMock->expects($this->at($index++))
            ->method("getVerbosity")
            ->willReturn(OutputInterface::VERBOSITY_NORMAL);

        $outputMock->expects($this->at($index++))
            ->method("write")
            ->with($this->equalTo(sprintf('<warning>Warning: This development build of Composer is over 60 days old. It is recommended to update it by running "%s self-update" to get the latest version.</warning>', $_SERVER['PHP_SELF'])));

        if (!defined('COMPOSER_DEV_WARNING_TIME')) {
            define('COMPOSER_DEV_WARNING_TIME', time() - 1);
        }

        $application->doRun($inputMock, $outputMock);
    }

    public function ensureNoDevWarning($command)
    {
        $application = new Application;

        $application->add(new \Composer\Command\SelfUpdateCommand);

        $inputMock = $this->getMockBuilder('Symfony\Component\Console\Input\InputInterface')->getMock();
        $outputMock = $this->getMockBuilder('Symfony\Component\Console\Output\OutputInterface')->getMock();

        putenv('COMPOSER_NO_INTERACTION=1');

        $index = 0;
        $inputMock->expects($this->at($index++))
            ->method('hasParameterOption')
            ->with($this->equalTo('--no-plugins'))
            ->will($this->returnValue(true));

        $inputMock->expects($this->at($index++))
            ->method('setInteractive')
            ->with($this->equalTo(false));

        $inputMock->expects($this->at($index++))
            ->method('hasParameterOption')
            ->with($this->equalTo('--no-cache'))
            ->will($this->returnValue(false));

        $inputMock->expects($this->at($index++))
            ->method('getParameterOption')
            ->with($this->equalTo(array('--working-dir', '-d')))
            ->will($this->returnValue(false));

        $inputMock->expects($this->any())
            ->method('getFirstArgument')
            ->will($this->returnValue('show'));

        $outputMock->expects($this->never())
            ->method("writeln");

        if (!defined('COMPOSER_DEV_WARNING_TIME')) {
            define('COMPOSER_DEV_WARNING_TIME', time() - 1);
        }

        $application->doRun($inputMock, $outputMock);
    }

    public function testDevWarningPrevented()
    {
        $this->ensureNoDevWarning('self-update');
    }

    public function testDevWarningPreventedAlias()
    {
        $this->ensureNoDevWarning('self-up');
    }
}
