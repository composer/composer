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

namespace Composer\Test\Command;

use Composer\Composer;
use Composer\Config;
use Composer\Factory;
use Composer\Test\TestCase;
use Composer\Util\Platform;
use Symfony\Component\Console\Input\ArrayInput;

class ArchiveCommandTest extends TestCase
{
    public function testUsesConfigFromComposerObject(): void
    {
        $input = new ArrayInput([]);

        $output = $this->getMockBuilder('Symfony\Component\Console\Output\OutputInterface')
            ->getMock();

        $ed = $this->getMockBuilder('Composer\EventDispatcher\EventDispatcher')
            ->disableOriginalConstructor()->getMock();

        $composer = new Composer;
        $config = new Config;
        $config->merge(['config' => ['archive-format' => 'zip']]);
        $composer->setConfig($config);

        $manager = $this->getMockBuilder('Composer\Package\Archiver\ArchiveManager')
            ->disableOriginalConstructor()->getMock();

        $package = $this->getMockBuilder('Composer\Package\RootPackageInterface')
            ->getMock();

        $manager->expects($this->once())->method('archive')
            ->with($package, 'zip', '.', null, false)->willReturn(Platform::getCwd());

        $composer->setArchiveManager($manager);
        $composer->setEventDispatcher($ed);
        $composer->setPackage($package);

        $command = $this->getMockBuilder('Composer\Command\ArchiveCommand')
            ->onlyMethods([
                'mergeApplicationDefinition',
                'getSynopsis',
                'initialize',
                'tryComposer',
                'requireComposer',
            ])->getMock();
        $command->expects($this->atLeastOnce())->method('tryComposer')
            ->willReturn($composer);
        $command->expects($this->atLeastOnce())->method('requireComposer')
            ->willReturn($composer);

        $command->run($input, $output);
    }

    public function testUsesConfigFromFactoryWhenComposerIsNotDefined(): void
    {
        $input = new ArrayInput([]);

        $output = $this->getMockBuilder('Symfony\Component\Console\Output\OutputInterface')
            ->getMock();
        $config = Factory::createConfig();

        $command = $this->getMockBuilder('Composer\Command\ArchiveCommand')
            ->onlyMethods([
                'mergeApplicationDefinition',
                'getSynopsis',
                'initialize',
                'tryComposer',
                'archive',
            ])->getMock();
        $command->expects($this->once())->method('tryComposer')
            ->willReturn(null);
        $command->expects($this->once())->method('archive')
            ->with(
                $this->isInstanceOf('Composer\IO\IOInterface'),
                $config,
                null,
                null,
                'tar',
                '.',
                null,
                false,
                null
            )->willReturn(0);

        $this->assertEquals(0, $command->run($input, $output));
    }
}
