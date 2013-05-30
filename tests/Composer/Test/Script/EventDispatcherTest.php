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

namespace Composer\Test\Script;

use Composer\Test\TestCase;
use Composer\Script\Event;
use Composer\Script\EventDispatcher;
use Composer\Util\ProcessExecutor;

class EventDispatcherTest extends TestCase
{
    /**
     * @expectedException RuntimeException
     */
    public function testListenerExceptionsAreCaught()
    {
        $io = $this->getMock('Composer\IO\IOInterface');
        $dispatcher = $this->getDispatcherStubForListenersTest(array(
            "Composer\Test\Script\EventDispatcherTest::call"
        ), $io);

        $io->expects($this->once())
            ->method('write')
            ->with('<error>Script Composer\Test\Script\EventDispatcherTest::call handling the post-install-cmd event terminated with an exception</error>');

        $dispatcher->dispatchCommandEvent("post-install-cmd", false);
    }

    /**
     * @expectedException RuntimeException
     */
    public function testCallableExceptionsAreCaught()
    {
        // First dispatch non-foo event. Should not be called
        $root = $this->getMock('Composer\Package\RootPackageInterface');
        $composer = $this->getMock('Composer\Composer');
        $composer->expects($this->once())->method('getPackage')->will($this->returnValue($root));
        $io = $this->getMock('Composer\IO\IOInterface');
        $io->expects($this->once())
            ->method('write')
            ->with('<error>Callable handling the EventName event terminated with an exception</error>');

        $event = new Event('EventName', $composer, $io);
        $callable = function(Event $passed) use($event) {
            $this->assertEquals($event, $passed);
            throw new \RuntimeException('Noes, stuff went wrong');
        };
        $dispatcher = new EventDispatcher($composer, $io);
        $dispatcher->bind('EventName', $callable);
        $dispatcher->dispatch('EventName', $event);
    }

    public function testBind()
    {
        $composer = $this->getMock('Composer\Composer');
        $io = $this->getMock('Composer\IO\IOInterface');
        $dispatcher = new EventDispatcher($composer, $io);

        // Static method is allowed
        $dispatcher->bind('EventName', 'EventDispatcherTest::call');

        // Anonymous function is allowed
        $func = function() {};
        $dispatcher->bind('EventName', $func);
    }

    /**
     * @dataProvider getValidCommands
     * @param string $command
     */
    public function testDispatcherCanExecuteSingleCommandLineScript($command)
    {
        $process = $this->getMock('Composer\Util\ProcessExecutor');
        $dispatcher = $this->getMockBuilder('Composer\Script\EventDispatcher')
            ->setConstructorArgs(array(
                $this->getMock('Composer\Composer'),
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

        $dispatcher->dispatchCommandEvent("post-install-cmd", false);
    }

    public function testDispatcherCanExecuteCliAndPhpInSameEventScriptStack()
    {
        $process = $this->getMock('Composer\Util\ProcessExecutor');
        $dispatcher = $this->getMockBuilder('Composer\Script\EventDispatcher')
            ->setConstructorArgs(array(
                $this->getMock('Composer\Composer'),
                $this->getMock('Composer\IO\IOInterface'),
                $process,
            ))
            ->setMethods(array(
                'getListeners',
                'executeEventPhpScript',
            ))
            ->getMock();

        $process->expects($this->exactly(2))
            ->method('execute')
            ->will($this->returnValue(0));

        $listeners = array(
            'echo -n foo',
            'Composer\\Test\\Script\\EventDispatcherTest::someMethod',
            'echo -n bar',
        );
        $dispatcher->expects($this->atLeastOnce())
            ->method('getListeners')
            ->will($this->returnValue($listeners));

        $dispatcher->expects($this->once())
            ->method('executeEventPhpScript')
            ->with('Composer\Test\Script\EventDispatcherTest', 'someMethod')
            ->will($this->returnValue(true));

        $dispatcher->dispatchCommandEvent("post-install-cmd", false);
    }

    private function getDispatcherStubForListenersTest($listeners, $io)
    {
        $dispatcher = $this->getMockBuilder('Composer\Script\EventDispatcher')
            ->setConstructorArgs(array(
                $this->getMock('Composer\Composer'),
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

    public function testDispatcherOutputsCommands()
    {
        $dispatcher = $this->getMockBuilder('Composer\Script\EventDispatcher')
            ->setConstructorArgs(array(
                $this->getMock('Composer\Composer'),
                $this->getMock('Composer\IO\IOInterface'),
                new ProcessExecutor,
            ))
            ->setMethods(array('getListeners'))
            ->getMock();

        $listener = array('echo foo');
        $dispatcher->expects($this->atLeastOnce())
            ->method('getListeners')
            ->will($this->returnValue($listener));

        ob_start();
        $dispatcher->dispatchCommandEvent("post-install-cmd", false);
        $this->assertEquals('foo', trim(ob_get_clean()));
    }

    public function testDispatcherOutputsErrorOnFailedCommand()
    {
        $dispatcher = $this->getMockBuilder('Composer\Script\EventDispatcher')
            ->setConstructorArgs(array(
                $this->getMock('Composer\Composer'),
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

        $io->expects($this->once())
            ->method('write')
            ->with($this->equalTo('<error>Script '.$code.' handling the post-install-cmd event returned with an error</error>'));

        $this->setExpectedException('RuntimeException');
        $dispatcher->dispatchCommandEvent("post-install-cmd", false);
    }

    public function testDispatcherCallsBindFunction()
    {
        $root = $this->getMock('Composer\Package\RootPackageInterface');
        $composer = $this->getMock('Composer\Composer');
        $composer->expects($this->exactly(2))->method('getPackage')->will($this->returnValue($root));
        $io = $this->getMock('Composer\IO\IOInterface');

        $called = false;
        $foo_event = new Event('Foo', $composer, $io);
        $callable = function(Event $passed) use(&$called, $foo_event) {
            $this->assertEquals($foo_event, $passed);
            $called = true;
        };
        $dispatcher = new EventDispatcher($composer, $io);
        $dispatcher->bind('Foo', $callable);

        // First dispatch non-foo event. Should not be called
        $bar_event = new Event('Bar', $composer, $io);
        $dispatcher->dispatch('Bar', $bar_event);
        $this->assertFalse($called);

        // Now dispatch foo event.
        $dispatcher->dispatch('Foo', $foo_event);
        $this->assertTrue($called);
    }

    public static function call()
    {
        throw new \RuntimeException();
    }

    public static function someMethod()
    {
        return true;
    }
}
