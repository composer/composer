<?php declare(strict_types=1);

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
    public function testGetter(): void
    {
        $composer = $this->getMockBuilder('Composer\Composer')->getMock();
        $io = $this->getMockBuilder('Composer\IO\IOInterface')->getMock();
        $transaction = $this->getMockBuilder('Composer\DependencyResolver\LockTransaction')->disableOriginalConstructor()->getMock();
        $event = new InstallerEvent('EVENT_NAME', $composer, $io, true, true, $transaction);

        self::assertSame('EVENT_NAME', $event->getName());
        self::assertTrue($event->isDevMode());
        self::assertTrue($event->isExecutingOperations());
        self::assertInstanceOf('Composer\DependencyResolver\Transaction', $event->getTransaction());
    }
}
