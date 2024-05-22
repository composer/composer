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

namespace Composer\Test\Script;

use Composer\Composer;
use Composer\Config;
use Composer\Script\Event;
use Composer\Test\TestCase;

class EventTest extends TestCase
{
    public function testEventSetsOriginatingEvent(): void
    {
        $io = $this->getMockBuilder('Composer\IO\IOInterface')->getMock();
        $composer = $this->createComposerInstance();

        $originatingEvent = new \Composer\EventDispatcher\Event('originatingEvent');

        $scriptEvent = new Event('test', $composer, $io, true);

        $this->assertNull(
            $scriptEvent->getOriginatingEvent(),
            'originatingEvent is initialized as null'
        );

        $scriptEvent->setOriginatingEvent($originatingEvent);

        // @phpstan-ignore staticMethod.dynamicCall
        $this->assertSame(
            $originatingEvent,
            $scriptEvent->getOriginatingEvent(),
            'getOriginatingEvent() SHOULD return test event'
        );
    }

    public function testEventCalculatesNestedOriginatingEvent(): void
    {
        $io = $this->getMockBuilder('Composer\IO\IOInterface')->getMock();
        $composer = $this->createComposerInstance();

        $originatingEvent = new \Composer\EventDispatcher\Event('upperOriginatingEvent');
        $intermediateEvent = new Event('intermediate', $composer, $io, true);
        $intermediateEvent->setOriginatingEvent($originatingEvent);

        $scriptEvent = new Event('test', $composer, $io, true);
        $scriptEvent->setOriginatingEvent($intermediateEvent);

        $this->assertNotSame(
            $intermediateEvent,
            $scriptEvent->getOriginatingEvent(),
            'getOriginatingEvent() SHOULD NOT return intermediate events'
        );

        $this->assertSame(
            $originatingEvent,
            $scriptEvent->getOriginatingEvent(),
            'getOriginatingEvent() SHOULD return upper-most event'
        );
    }

    private function createComposerInstance(): Composer
    {
        $composer = new Composer;
        $config = new Config;
        $composer->setConfig($config);
        $package = $this->getMockBuilder('Composer\Package\RootPackageInterface')->getMock();
        $composer->setPackage($package);

        return $composer;
    }
}
