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

namespace Composer\Test\EventDispatcher;

use Composer\EventDispatcher\Event;
use Composer\Installer\InstallerEvents;
use Composer\Config;
use Composer\Composer;
use Composer\TestCase;
use Composer\IO\BufferIO;
use Composer\Script\ScriptEvents;
use Composer\Script\CommandEvent;
use Composer\Util\ProcessExecutor;
use Symfony\Component\Console\Output\OutputInterface;

class EventDispatcherTest extends TestCase
{
    /**
     * @expectedException RuntimeException
     */
    public function testListenerExceptionsAreCaught()
    {
        $io = $this->getMock('Composer\IO\IOInterface');
        $dispatcher = $this->getDispatcherStubForListenersTest(array(
            'Composer\Test\EventDispatcher\EventDispatcherTest::call',
        ), $io);

        $io->expects($this->at(0))
            ->method('isVerbose')
            ->willReturn(0);

        $io->expects($this->at(1))
            ->method('writeError')
            ->with('> Composer\Test\EventDispatcher\EventDispatcherTest::call');

        $io->expects($this->at(2))
            ->method('writeError')
            ->with('<error>Script Composer\Test\EventDispatcher\EventDispatcherTest::call handling the post-install-cmd event terminated with an exception</error>');

        $dispatcher->dispatchScript(ScriptEvents::POST_INSTALL_CMD, false);
    }

    public function testDispatcherCanConvertScriptEventToCommandEventForListener()
    {
        $io = $this->getMock('Composer\IO\IOInterface');
        $dispatcher = $this->getDispatcherStubForListenersTest(array(
            'Composer\Test\EventDispatcher\EventDispatcherTest::expectsCommandEvent',
        ), $io);

        $this->assertEquals(1, $dispatcher->dispatchScript(ScriptEvents::POST_INSTALL_CMD, false));
    }

    public function testDispatcherDoesNotAttemptConversionForListenerWithoutTypehint()
    {
        $io = $this->getMock('Composer\IO\IOInterface');
        $dispatcher = $this->getDispatcherStubForListenersTest(array(
            'Composer\Test\EventDispatcher\EventDispatcherTest::expectsVariableEvent',
        ), $io);

        $this->assertEquals(1, $dispatcher->dispatchScript(ScriptEvents::POST_INSTALL_CMD, false));
    }

    /**
     * @dataProvider getValidCommands
     * @param string $command
     */
    public function testDispatcherCanExecuteSingleCommandLineScript($command)
    {
        $process = $this->getMock('Composer\Util\ProcessExecutor');
        $dispatcher = $this->getMockBuilder('Composer\EventDispatcher\EventDispatcher')
            ->setConstructorArgs(array(
                $this->createComposerInstance(),
                $this->getMock('Composer\IO\IOInterface'),
                $process,
            ))
            ->setMethods(array('getListeners'))
            ->getMock();

        $listener = array($command);
        $dispatcher->expects($this->atLeastOnce())
            ->method('getListeners')
            ->will($this->returnValue($listener));

        $process->expects($this->once())
            ->method('execute')
            ->with($command)
            ->will($this->returnValue(0));

        $dispatcher->dispatchScript(ScriptEvents::POST_INSTALL_CMD, false);
    }

    public function testDispatcherCanExecuteCliAndPhpInSameEventScriptStack()
    {
        $process = $this->getMock('Composer\Util\ProcessExecutor');
        $dispatcher = $this->getMockBuilder('Composer\EventDispatcher\EventDispatcher')
            ->setConstructorArgs(array(
                $this->createComposerInstance(),
                $io = new BufferIO('', OutputInterface::VERBOSITY_VERBOSE),
                $process,
            ))
            ->setMethods(array(
                'getListeners',
            ))
            ->getMock();

        $process->expects($this->exactly(2))
            ->method('execute')
            ->will($this->returnValue(0));

        $listeners = array(
            'echo -n foo',
            'Composer\\Test\\EventDispatcher\\EventDispatcherTest::someMethod',
            'echo -n bar',
        );

        $dispatcher->expects($this->atLeastOnce())
            ->method('getListeners')
            ->will($this->returnValue($listeners));

        $dispatcher->dispatchScript(ScriptEvents::POST_INSTALL_CMD, false);

        $expected = '> post-install-cmd: echo -n foo'.PHP_EOL.
            '> post-install-cmd: Composer\Test\EventDispatcher\EventDispatcherTest::someMethod'.PHP_EOL.
            '> post-install-cmd: echo -n bar'.PHP_EOL;
        $this->assertEquals($expected, $io->getOutput());
    }

    public function testDispatcherCanExecuteComposerScriptGroups()
    {
        $process = $this->getMock('Composer\Util\ProcessExecutor');
        $dispatcher = $this->getMockBuilder('Composer\EventDispatcher\EventDispatcher')
            ->setConstructorArgs(array(
                $composer = $this->createComposerInstance(),
                $io = new BufferIO('', OutputInterface::VERBOSITY_VERBOSE),
                $process,
            ))
            ->setMethods(array(
                'getListeners',
            ))
            ->getMock();

        $process->expects($this->exactly(3))
            ->method('execute')
            ->will($this->returnValue(0));

        $dispatcher->expects($this->atLeastOnce())
            ->method('getListeners')
            ->will($this->returnCallback(function (Event $event) {
                if ($event->getName() === 'root') {
                    return array('@group');
                } elseif ($event->getName() === 'group') {
                    return array('echo -n foo', '@subgroup', 'echo -n bar');
                } elseif ($event->getName() === 'subgroup') {
                    return array('echo -n baz');
                }

                return array();
            }));

        $dispatcher->dispatch('root', new CommandEvent('root', $composer, $io));
        $expected = '> root: @group'.PHP_EOL.
            '> group: echo -n foo'.PHP_EOL.
            '> group: @subgroup'.PHP_EOL.
            '> subgroup: echo -n baz'.PHP_EOL.
            '> group: echo -n bar'.PHP_EOL;
        $this->assertEquals($expected, $io->getOutput());
    }

    /**
     * @expectedException RuntimeException
     */
    public function testDispatcherDetectInfiniteRecursion()
    {
        $process = $this->getMock('Composer\Util\ProcessExecutor');
        $dispatcher = $this->getMockBuilder('Composer\EventDispatcher\EventDispatcher')
        ->setConstructorArgs(array(
            $composer = $this->createComposerInstance(),
            $io = $this->getMock('Composer\IO\IOInterface'),
            $process,
        ))
        ->setMethods(array(
            'getListeners',
        ))
        ->getMock();

        $dispatcher->expects($this->atLeastOnce())
            ->method('getListeners')
            ->will($this->returnCallback(function (Event $event) {
                if ($event->getName() === 'root') {
                    return array('@recurse');
                } elseif ($event->getName() === 'recurse') {
                    return array('@root');
                }

                return array();
            }));

        $dispatcher->dispatch('root', new CommandEvent('root', $composer, $io));
    }

    private function getDispatcherStubForListenersTest($listeners, $io)
    {
        $dispatcher = $this->getMockBuilder('Composer\EventDispatcher\EventDispatcher')
            ->setConstructorArgs(array(
                $this->createComposerInstance(),
                $io,
            ))
            ->setMethods(array('getListeners'))
            ->getMock();

        $dispatcher->expects($this->atLeastOnce())
            ->method('getListeners')
            ->will($this->returnValue($listeners));

        return $dispatcher;
    }

    public function getValidCommands()
    {
        return array(
            array('phpunit'),
            array('echo foo'),
            array('echo -n foo'),
        );
    }

    public function testDispatcherOutputsCommand()
    {
        $dispatcher = $this->getMockBuilder('Composer\EventDispatcher\EventDispatcher')
            ->setConstructorArgs(array(
                $this->createComposerInstance(),
                $io = $this->getMock('Composer\IO\IOInterface'),
                new ProcessExecutor,
            ))
            ->setMethods(array('getListeners'))
            ->getMock();

        $listener = array('echo foo');
        $dispatcher->expects($this->atLeastOnce())
            ->method('getListeners')
            ->will($this->returnValue($listener));

        $io->expects($this->once())
            ->method('writeError')
            ->with($this->equalTo('> echo foo'));

        ob_start();
        $dispatcher->dispatchScript(ScriptEvents::POST_INSTALL_CMD, false);
        $this->assertEquals('foo', trim(ob_get_clean()));
    }

    public function testDispatcherOutputsErrorOnFailedCommand()
    {
        $dispatcher = $this->getMockBuilder('Composer\EventDispatcher\EventDispatcher')
            ->setConstructorArgs(array(
                $this->createComposerInstance(),
                $io = $this->getMock('Composer\IO\IOInterface'),
                new ProcessExecutor,
            ))
            ->setMethods(array('getListeners'))
            ->getMock();

        $code = 'exit 1';
        $listener = array($code);
        $dispatcher->expects($this->atLeastOnce())
            ->method('getListeners')
            ->will($this->returnValue($listener));

        $io->expects($this->at(0))
            ->method('isVerbose')
            ->willReturn(0);

        $io->expects($this->at(1))
            ->method('writeError')
            ->willReturn('> exit 1');

        $io->expects($this->at(2))
            ->method('writeError')
            ->with($this->equalTo('<error>Script '.$code.' handling the post-install-cmd event returned with an error</error>'));

        $this->setExpectedException('RuntimeException');
        $dispatcher->dispatchScript(ScriptEvents::POST_INSTALL_CMD, false);
    }

    public function testDispatcherInstallerEvents()
    {
        $process = $this->getMock('Composer\Util\ProcessExecutor');
        $dispatcher = $this->getMockBuilder('Composer\EventDispatcher\EventDispatcher')
            ->setConstructorArgs(array(
                    $this->createComposerInstance(),
                    $this->getMock('Composer\IO\IOInterface'),
                    $process,
                ))
            ->setMethods(array('getListeners'))
            ->getMock();

        $dispatcher->expects($this->atLeastOnce())
            ->method('getListeners')
            ->will($this->returnValue(array()));

        $policy = $this->getMock('Composer\DependencyResolver\PolicyInterface');
        $pool = $this->getMockBuilder('Composer\DependencyResolver\Pool')->disableOriginalConstructor()->getMock();
        $installedRepo = $this->getMockBuilder('Composer\Repository\CompositeRepository')->disableOriginalConstructor()->getMock();
        $request = $this->getMockBuilder('Composer\DependencyResolver\Request')->disableOriginalConstructor()->getMock();

        $dispatcher->dispatchInstallerEvent(InstallerEvents::PRE_DEPENDENCIES_SOLVING, true, $policy, $pool, $installedRepo, $request);
        $dispatcher->dispatchInstallerEvent(InstallerEvents::POST_DEPENDENCIES_SOLVING, true, $policy, $pool, $installedRepo, $request, array());
    }

    public static function call()
    {
        throw new \RuntimeException();
    }

    public static function expectsCommandEvent(CommandEvent $event)
    {
        return false;
    }

    public static function expectsVariableEvent($event)
    {
        return false;
    }

    public static function someMethod()
    {
        return true;
    }

    private function createComposerInstance()
    {
        $composer = new Composer;
        $config = new Config;
        $composer->setConfig($config);

        return $composer;
    }
}
