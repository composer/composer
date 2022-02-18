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
use Symfony\Component\Console\Output\BufferedOutput;

class ApplicationTest extends TestCase
{
    protected function tearDown(): void
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

        $inputMock->expects($this->any())
            ->method('hasParameterOption')
            ->willReturnCallback(function ($opt): bool {
                switch ($opt) {
                    case '--no-plugins':
                        return true;
                    case '--no-scripts':
                        return false;
                    case '--no-cache':
                        return false;
                }

                return false;
            });

        $inputMock->expects($this->once())
            ->method('setInteractive')
            ->with($this->equalTo(false));

        $inputMock->expects($this->once())
            ->method('getParameterOption')
            ->with($this->equalTo(array('--working-dir', '-d')))
            ->will($this->returnValue(false));

        $inputMock->expects($this->any())
            ->method('getFirstArgument')
            ->will($this->returnValue('about'));

        $output = new BufferedOutput();
        $expectedOutput = '';

        if (XdebugHandler::isXdebugActive()) {
            $expectedOutput .= '<warning>Composer is operating slower than normal because you have Xdebug enabled. See https://getcomposer.org/xdebug</warning>'.PHP_EOL;
        }

        $expectedOutput .= sprintf('<warning>Warning: This development build of Composer is over 60 days old. It is recommended to update it by running "%s self-update" to get the latest version.</warning>', $_SERVER['PHP_SELF']).PHP_EOL;

        if (!defined('COMPOSER_DEV_WARNING_TIME')) {
            define('COMPOSER_DEV_WARNING_TIME', time() - 1);
        }

        $application->doRun($inputMock, $output);

        $this->assertStringContainsString($expectedOutput, $output->fetch());
    }

    /**
     * @param  string $command
     * @return void
     */
    public function ensureNoDevWarning($command)
    {
        $application = new Application;

        $application->add(new \Composer\Command\SelfUpdateCommand);

        $inputMock = $this->getMockBuilder('Symfony\Component\Console\Input\InputInterface')->getMock();
        $outputMock = $this->getMockBuilder('Symfony\Component\Console\Output\OutputInterface')->getMock();

        putenv('COMPOSER_NO_INTERACTION=1');

        $inputMock->expects($this->any())
            ->method('hasParameterOption')
            ->willReturnCallback(function ($opt): bool {
                switch ($opt) {
                    case '--no-plugins':
                        return true;
                    case '--no-scripts':
                        return false;
                    case '--no-cache':
                        return false;
                }

                return false;
            });

        $inputMock->expects($this->once())
            ->method('setInteractive')
            ->with($this->equalTo(false));

        $inputMock->expects($this->once())
            ->method('getParameterOption')
            ->with($this->equalTo(array('--working-dir', '-d')))
            ->will($this->returnValue(false));

        $inputMock->expects($this->any())
            ->method('getFirstArgument')
            ->will($this->returnValue('about'));

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
