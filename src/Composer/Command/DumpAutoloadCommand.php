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

namespace Composer\Command;

use Composer\Package\PackageMap;
use Composer\Plugin\CommandEvent;
use Composer\Plugin\PluginEvents;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class DumpAutoloadCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('dump-autoload')
            ->setAliases(array('dumpautoload'))
            ->setDescription('Dumps the autoloader')
            ->setDefinition(array(
                new InputOption('optimize', 'o', InputOption::VALUE_NONE, 'Optimizes PSR0 packages to be loaded with classmaps too, good for production.'),
            ))
            ->setHelp(<<<EOT
<info>php composer.phar dump-autoload</info>
EOT
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $composer = $this->getComposer();

        $commandEvent = new CommandEvent(PluginEvents::COMMAND, 'dump-autoload', $input, $output);
        $composer->getEventDispatcher()->dispatch($commandEvent->getName(), $commandEvent);

        $installationManager = $composer->getInstallationManager();
        $localRepo = $composer->getRepositoryManager()->getLocalRepository();
        $package = $composer->getPackage();
        $config = $composer->getConfig();

        $optimize = $input->getOption('optimize') || $config->get('optimize-autoloader');

        if ($optimize) {
            $output->writeln('<info>Generating optimized autoload files</info>');
        } else {
            $output->writeln('<info>Generating autoload files</info>');
        }

        $packages = $localRepo->getCanonicalPackages();
        $packageMap = new PackageMap($installationManager, $packages, $package);

        $composer->getAutoloadGenerator()->dumpPackageMap($config, $packageMap, 'composer', $optimize);
    }
}
