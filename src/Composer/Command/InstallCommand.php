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

use Composer\DependencyResolver;
use Composer\DependencyResolver\Pool;
use Composer\DependencyResolver\Request;
use Composer\DependencyResolver\Operation;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 * @author Ryan Weaver <ryan@knplabs.com>
 * @author Konstantin Kudryashov <ever.zet@gmail.com>
 */
class InstallCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('install')
            ->setDescription('Parses the composer.json file and downloads the needed dependencies.')
            ->setHelp(<<<EOT
The <info>install</info> command reads the composer.json file from the
current directory, processes it, and downloads and installs all the
libraries and dependencies outlined in that file.

<info>php composer install</info>

EOT
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $composer = $this->getComposer();

        if ($composer->getPackageLock()->isLocked()) {
            $output->writeln('<info>Found lockfile. Reading</info>');

            $installationManager = $composer->getInstallationManager();
            foreach ($composer->getPackageLock()->getLockedPackages() as $package) {
                if (!$installationManager->isPackageInstalled($package)) {
                    $operation = new Operation\InstallOperation($package, 'lock resolving');
                    $installationManager->execute($operation);
                }
            }

            return 0;
        }

        // creating repository pool
        $pool = new Pool;
        $pool->addRepository($composer->getRepositoryManager()->getLocalRepository());
        foreach ($composer->getRepositoryManager()->getRepositories() as $repository) {
            $pool->addRepository($repository);
        }

        // creating requirements request
        $request = new Request($pool);
        foreach ($composer->getPackage()->getRequires() as $link) {
            $request->install($link->getTarget(), $link->getConstraint());
        }

        // prepare solver
        $installationManager = $composer->getInstallationManager();
        $localRepo           = $composer->getRepositoryManager()->getLocalRepository();
        $policy              = new DependencyResolver\DefaultPolicy();
        $solver              = new DependencyResolver\Solver($policy, $pool, $localRepo);

        // solve dependencies and execute operations
        foreach ($solver->solve($request) as $operation) {
            $installationManager->execute($operation);
        }

        // TODO implement lock
        if (false) {
            $composer->getPackageLock()->lock($localRepo->getPackages());
            $output->writeln('> Locked');
        }

        $localRepo->write();

        $output->writeln('> Done');
    }
}
