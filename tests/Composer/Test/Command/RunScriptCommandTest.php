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

namespace Composer\Test\Command;

use Composer\Composer;
use Composer\Config;
use Composer\Script\Event as ScriptEvent;
use Composer\TestCase;

class RunScriptCommandTest extends TestCase
{

    /**
     * @dataProvider getDevOptions
     * @param bool $dev
     * @param bool $noDev
     */
    public function testDetectAndPassDevModeToEventAndToDispatching($dev, $noDev)
    {
        $scriptName = 'testScript';

        $input = $this->getMock('Symfony\Component\Console\Input\InputInterface');
        $input
            ->method('getOption')
            ->will($this->returnValueMap(array(
                array('list', false),
                array('dev', $dev),
                array('no-dev', $noDev),
            )));

        $input
            ->method('getArgument')
            ->will($this->returnValueMap(array(
                array('script', $scriptName),
                array('args', array()),
            )));
        $input
            ->method('hasArgument')
            ->with('command')
            ->willReturn(false);

        $output = $this->getMock('Symfony\Component\Console\Output\OutputInterface');

        $expectedDevMode = $dev || !$noDev;

        $ed = $this->getMockBuilder('Composer\EventDispatcher\EventDispatcher')
            ->disableOriginalConstructor()
            ->getMock();

        $ed->expects($this->once())
            ->method('hasEventListeners')
            ->with($this->callback(function (ScriptEvent $event) use ($scriptName, $expectedDevMode) {
                return $event->getName() === $scriptName
                && $event->isDevMode() === $expectedDevMode;
            }))
            ->willReturn(true);

        $ed->expects($this->once())
            ->method('dispatchScript')
            ->with($scriptName, $expectedDevMode, array());

        $composer = $this->createComposerInstance();
        $composer->setEventDispatcher($ed);

        $command = $this->getMockBuilder('Composer\Command\RunScriptCommand')
            ->setMethods(array(
                'mergeApplicationDefinition',
                'bind',
                'getSynopsis',
                'initialize',
                'isInteractive',
                'getComposer'
            ))
            ->getMock();
        $command->expects($this->any())->method('getComposer')->willReturn($composer);
        $command->method('isInteractive')->willReturn(false);

        $command->run($input, $output);
    }

    public function getDevOptions()
    {
        return array(
            array(true, true),
            array(true, false),
            array(false, true),
            array(false, false),
        );
    }

    private function createComposerInstance()
    {
        $composer = new Composer;
        $config   = new Config;
        $composer->setConfig($config);

        return $composer;
    }
}
