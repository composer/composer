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
        $policy = $this->getMockBuilder('Composer\DependencyResolver\PolicyInterface')->getMock();
        $repositorySet = $this->getMockBuilder('Composer\Repository\RepositorySet')->disableOriginalConstructor()->getMock();
        $pool = $this->getMockBuilder('Composer\DependencyResolver\Pool')->disableOriginalConstructor()->getMock();
        $transaction = $this->getMockBuilder('Composer\DependencyResolver\LockTransaction')->disableOriginalConstructor()->getMock();
        $request = $this->getMockBuilder('Composer\DependencyResolver\Request')->disableOriginalConstructor()->getMock();
        $event = new InstallerEvent('EVENT_NAME', $composer, $io, true, $repositorySet, $pool, $request, $policy, $transaction);

        $this->assertSame('EVENT_NAME', $event->getName());
        $this->assertInstanceOf('Composer\Composer', $event->getComposer());
        $this->assertInstanceOf('Composer\IO\IOInterface', $event->getIO());
        $this->assertTrue($event->isDevMode());
        $this->assertInstanceOf('Composer\DependencyResolver\PolicyInterface', $event->getPolicy());
        $this->assertInstanceOf('Composer\Repository\RepositorySet', $event->getRepositorySet());
        $this->assertInstanceOf('Composer\DependencyResolver\Pool', $event->getPool());
        $this->assertInstanceOf('Composer\DependencyResolver\Request', $event->getRequest());
        $this->assertInstanceOf('Composer\DependencyResolver\Transaction', $event->getTransaction());
    }
}
