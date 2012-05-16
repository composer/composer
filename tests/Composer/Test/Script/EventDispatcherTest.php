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

/**
 * Event Dispatcher Test Case
 *
 * @group event-dispatcher
 * @ticket #693
 * @author Andrea Turso <turso@officinesoftware.co.uk>
 */
class EventDispatcherTest extends TestCase
{
    /**
     * Test the doDispatch method properly catches any exception
     * thrown from the listener invocation, i.e., <code>$className::$methodName($event)</code>,
     * and replaces it with a \RuntimeException.
     *
     * @expectedException \RuntimeException
     */
    public function testListenerExceptionsAreSuppressed()
    {
        $dispatcher = $this->getDispatcherStubForListenersTest(array(
                "Composer\Test\Script\EventDispatcherTest::call"
            ));
        $dispatcher->dispatchCommandEvent("post-install-cmd");
    }

    private function getDispatcherStubForListenersTest($listeners)
    {
        $dispatcher = $this->getMockBuilder('Composer\Script\EventDispatcher')
                           ->setConstructorArgs(array(
                               $this->getMock('Composer\Composer'),
                               $this->getMock('Composer\IO\IOInterface')))
                           ->setMethods(array('getListeners'))
                           ->getMock();

        $dispatcher->expects($this->atLeastOnce())
                   ->method('getListeners')
                   ->will($this->returnValue($listeners));

        return $dispatcher;
    }

    public static function call()
    {
        throw new \Exception();
    }
}