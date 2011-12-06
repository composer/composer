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

use Composer\Autoload\AutoloadGenerator;
use Composer\DependencyResolver;
use Composer\DependencyResolver\Pool;
use Composer\DependencyResolver\Request;
use Composer\DependencyResolver\Operation;
use Composer\Package\LinkConstraint\VersionConstraint;
use Composer\Repository\PlatformRepository;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class DebugPackagesCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('debug:packages')
            ->setDescription('Lists all existing packages and their version')
            ->setDefinition(array(
                new InputOption('local', null, InputOption::VALUE_NONE, 'list locally installed packages only'),
                new InputOption('platform', null, InputOption::VALUE_NONE, 'list platform packages only'),
            ))
            ->setHelp(<<<EOT
<info>php composer.phar debug:packages</info>

EOT
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $composer = $this->getComposer();

        // create local repo, this contains all packages that are installed in the local project
        $localRepo = $composer->getRepositoryManager()->getLocalRepository();
        // create installed repo, this contains all local packages + platform packages (php & extensions)
        $installedRepo = new PlatformRepository($localRepo);

        if ($input->getOption('local')) {
            foreach ($localRepo->getPackages() as $package) {
                $output->writeln('<info>local:</info> ' . $package->getPrettyName() . ' ' . $package->getPrettyVersion() . '<comment> (' . $package->getVersion() . ')</comment>');
            }

            return;
        }

        if ($input->getOption('platform')) {
            $repos = array_diff($installedRepo->getPackages(), $localRepo->getPackages());
            foreach ($repos as $package) {
                $output->writeln('<info>plattform:</info> ' . $package->getPrettyName() . ' ' . $package->getPrettyVersion() . '<comment> (' . $package->getVersion() . ')</comment>');
            }

            return;
        }

        foreach ($installedRepo->getPackages() as $package) {
            if ($localRepo->hasPackage($package)) {
                $output->writeln('<info>installed:</info> ' . $package->getPrettyName() . ' ' . $package->getPrettyVersion() . '<comment> (' . $package->getVersion() . ')</comment>');
            } else {
                $output->writeln('<info>platform:</info> ' . $package->getPrettyName() . ' ' . $package->getPrettyVersion() . '<comment> (' . $package->getName() . ' ' . $package->getVersion() . ')</comment>');
            }
        }

        foreach ($composer->getRepositoryManager()->getRepositories() as $repository) {
            foreach ($repository->getPackages() as $package) {
                $output->writeln('<comment>available:</comment> ' . $package->getPrettyName() . ' ' . $package->getPrettyVersion() . '<comment> (' . $package->getName() . ' ' . $package->getVersion() . ')</comment>');
            }
        }
    }

}