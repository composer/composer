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

namespace Composer\Test\Installer;

use Composer\Installer\InstallerEvent;
use Composer\Test\TestCase;

class InstallerEventTest extends TestCase
{
    public function testGetter()
    {
        $composer = $this->getMockBuilder('Composer\Composer')->getMock();
        $io = $this->getMockBuilder('Composer\IO\IOInterface')->getMock();
        $transaction = $this->getMockBuilder('Composer\DependencyResolver\LockTransaction')->disableOriginalConstructor()->getMock();
        $event = new InstallerEvent('EVENT_NAME', $composer, $io, true, true, $transaction);

        $this->assertSame('EVENT_NAME', $event->getName());
        $this->assertInstanceOf('Composer\Composer', $event->getComposer());
        $this->assertInstanceOf('Composer\IO\IOInterface', $event->getIO());
        $this->assertTrue($event->isDevMode());
        $this->assertTrue($event->isExecutingOperations());
        $this->assertInstanceOf('Composer\DependencyResolver\Transaction', $event->getTransaction());
    }
}
