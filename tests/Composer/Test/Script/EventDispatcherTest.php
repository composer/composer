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

        $dispatcher->dispatchCommandEvent("post-install-cmd");
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

    public static function call()
    {
        throw new \RuntimeException();
    }
}