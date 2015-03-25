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
use Composer\TestCase;

class ApplicationTest extends TestCase
{
    public function testDevWarning()
    {
        $application = new Application;

        $inputMock = $this->getMock('Symfony\Component\Console\Input\InputInterface');
        $outputMock = $this->getMock('Symfony\Component\Console\Output\OutputInterface');

        $inputMock->expects($this->once())
            ->method('getFirstArgument')
            ->will($this->returnValue('list'));

        $outputMock->expects($this->once())
            ->method("write")
            ->with($this->equalTo(sprintf('<warning>Warning: This development build of composer is over 30 days old. It is recommended to update it by running "%s self-update" to get the latest version.</warning>', $_SERVER['PHP_SELF'])));

        if (!defined('COMPOSER_DEV_WARNING_TIME')) {
            define('COMPOSER_DEV_WARNING_TIME', time() - 1);
        }

        $this->setExpectedException('RuntimeException');
        $application->doRun($inputMock, $outputMock);
    }

    public function ensureNoDevWarning($command)
    {
        $application = new Application;

        $application->add(new \Composer\Command\SelfUpdateCommand);

        $inputMock = $this->getMock('Symfony\Component\Console\Input\InputInterface');
        $outputMock = $this->getMock('Symfony\Component\Console\Output\OutputInterface');

        $inputMock->expects($this->once())
            ->method('getFirstArgument')
            ->will($this->returnValue($command));

        $outputMock->expects($this->never())
            ->method("writeln");

        if (!defined('COMPOSER_DEV_WARNING_TIME')) {
            define('COMPOSER_DEV_WARNING_TIME', time() - 1);
        }

        $this->setExpectedException('RuntimeException');
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
