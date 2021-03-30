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
use Composer\Test\TestCase;
use Symfony\Component\Console\Input\ArrayInput;

class ShowCommandTest extends TestCase
{
    public function testThatNoDevFlagIsUsedInConjunctionWithLockedFlag()
    {
        $input = new ArrayInput(array('--locked' => true, '--no-dev' => true));
        $output = $this->getMockBuilder('Symfony\Component\Console\Output\OutputInterface')
            ->getMock();

        $ed = $this->getMockBuilder('Composer\EventDispatcher\EventDispatcher')
            ->disableOriginalConstructor()
            ->getMock();

        $repository = $this->getMockBuilder('Composer\Repository\LockArrayRepository')
            ->getMock();
        $repository->expects($this->once())
            ->method('getPackages')
            ->willReturn(array());

        $locker = $this->getMockBuilder('Composer\Package\Locker')
            ->disableOriginalConstructor()
            ->getMock();
        $locker->expects($this->once())
            ->method('isLocked')
            ->willReturn(true);
        $locker->expects($this->once())
            ->method('getLockedRepository')
            ->with(false)
            ->willReturn($repository);

        $composer = new Composer;
        $composer->setConfig(new Config);
        $composer->setLocker($locker);
        $composer->setEventDispatcher($ed);

        $command = $this->getMockBuilder('Composer\Command\ShowCommand')
            ->setMethods(array('getComposer', 'getApplication'))
            ->getMock();

        $command->expects($this->atLeastOnce())
            ->method('getComposer')
            ->willReturn($composer);

        $command->method('getApplication')
            ->willReturn($this->getMockBuilder('Symfony\Component\Console\Application')->getMock());

        $command->run($input, $output);
    }
}
