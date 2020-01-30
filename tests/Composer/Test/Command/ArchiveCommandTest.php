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
use Composer\Factory;
use Composer\Test\TestCase;
use Symfony\Component\Console\Input\ArrayInput;

class ArchiveCommandTest extends TestCase
{
    public function testUsesConfigFromComposerObject()
    {
        $input = new ArrayInput(array());

        $output = $this->getMockBuilder('Symfony\Component\Console\Output\OutputInterface')
            ->getMock();

        $ed = $this->getMockBuilder('Composer\EventDispatcher\EventDispatcher')
            ->disableOriginalConstructor()->getMock();

        $composer = new Composer;
        $config = new Config;
        $config->merge(array('config' => array('archive-format' => 'zip')));
        $composer->setConfig($config);

        $manager = $this->getMockBuilder('Composer\Package\Archiver\ArchiveManager')
            ->disableOriginalConstructor()->getMock();

        $package = $this->getMockBuilder('Composer\Package\RootPackageInterface')
            ->getMock();

        $manager->expects($this->once())->method('archive')
            ->with($package, 'zip', '.', null, false)->willReturn(getcwd());

        $composer->setArchiveManager($manager);
        $composer->setEventDispatcher($ed);
        $composer->setPackage($package);

        $command = $this->getMockBuilder('Composer\Command\ArchiveCommand')
            ->setMethods(array(
                'mergeApplicationDefinition',
                'bind',
                'getSynopsis',
                'initialize',
                'isInteractive',
                'getComposer',
            ))->getMock();
        $command->expects($this->any())->method('getComposer')
            ->willReturn($composer);
        $command->method('isInteractive')->willReturn(false);

        $command->run($input, $output);
    }

    public function testUsesConfigFromFactoryWhenComposerIsNotDefined()
    {
        $input = new ArrayInput(array());

        $output = $this->getMockBuilder('Symfony\Component\Console\Output\OutputInterface')
            ->getMock();

        $ed = $this->getMockBuilder('Composer\EventDispatcher\EventDispatcher')
            ->disableOriginalConstructor()->getMock();

        $composer = new Composer;
        $config = Factory::createConfig();

        $manager = $this->getMockBuilder('Composer\Package\Archiver\ArchiveManager')
            ->disableOriginalConstructor()->getMock();

        $factory = $this->getMockBuilder('Composer\Factory')->getMock();
        $factory->method('createArchiveManager')->willReturn($manager);

        $package = $this->getMockBuilder('Composer\Package\RootPackageInterface')
            ->getMock();

        $composer->setArchiveManager($manager);
        $composer->setEventDispatcher($ed);
        $composer->setPackage($package);

        $command = $this->getMockBuilder('Composer\Command\ArchiveCommand')
            ->setMethods(array(
                'mergeApplicationDefinition',
                'bind',
                'getSynopsis',
                'initialize',
                'isInteractive',
                'getComposer',
                'archive',
            ))->getMock();
        $command->expects($this->any())->method('getComposer')
            ->willReturnOnConsecutiveCalls(null, $composer);
        $command->expects($this->any())->method('archive')
            ->with(
                $this->isType('object'),
                $config,
                null,
                null,
                'tar',
                '.',
                null,
                false,
                null
            );
        $command->method('isInteractive')->willReturn(false);

        $command->run($input, $output);
    }
}
