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
            ->setDefinition(array(
                new InputOption('dev', null, InputOption::VALUE_NONE, 'Forces installation from package sources when possible, including VCS information.'),
            ))
            ->setHelp(<<<EOT
The <info>install</info> command reads the composer.json file from the
current directory, processes it, and downloads and installs all the
libraries and dependencies outlined in that file.

<info>php composer.phar install</info>

EOT
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        return $this->install($output, $input->getOption('dev'));
    }

    public function install(OutputInterface $output, $preferSource, $update = false)
    {
        $composer = $this->getComposer();

        if ($preferSource) {
            $composer->getDownloadManager()->setPreferSource(true);
        }

        // create local repo, this contains all packages that are installed in the local project
        $localRepo           = $composer->getRepositoryManager()->getLocalRepository();
        // create installed repo, this contains all local packages + platform packages (php & extensions)
        $installedRepo       = new PlatformRepository($localRepo);

        // creating repository pool
        $pool = new Pool;
        $pool->addRepository($installedRepo);
        foreach ($composer->getRepositoryManager()->getRepositories() as $repository) {
            $pool->addRepository($repository);
        }

        // creating requirements request
        $request = new Request($pool);
        if ($update) {
            $output->writeln('> Updating dependencies.');
            $listedPackages = array();
            $installedPackages = $installedRepo->getPackages();

            foreach ($composer->getPackage()->getRequires() as $link) {
                $listedPackages[] = $link->getTarget();

                foreach ($installedPackages as $package) {
                    if ($package->getName() === $link->getTarget()) {
                        $request->update($link->getTarget(), $link->getConstraint());
                        continue 2;
                    }
                }

                $request->install($link->getTarget(), $link->getConstraint());
            }

            foreach ($localRepo->getPackages() as $package) {
                if (!in_array($package->getName(), $listedPackages)) {
                    $request->remove($package->getName());
                }
            }
        } elseif ($composer->getLocker()->isLocked()) {
            $output->writeln('> Found lockfile. Reading.');

            foreach ($composer->getLocker()->getLockedPackages() as $package) {
                $constraint = new VersionConstraint('=', $package->getVersion());
                $request->install($package->getName(), $constraint);
            }
        } else {
            $output->writeln('> Installing dependencies.');
            foreach ($composer->getPackage()->getRequires() as $link) {
                $request->install($link->getTarget(), $link->getConstraint());
            }
        }

        // prepare solver
        $installationManager = $composer->getInstallationManager();
        $policy              = new DependencyResolver\DefaultPolicy();
        $solver              = new DependencyResolver\Solver($policy, $pool, $installedRepo);

        // solve dependencies
        $operations = $solver->solve($request);

        // check for missing deps
        // TODO this belongs in the solver, but this will do for now to report top-level deps missing at least
        foreach ($request->getJobs() as $job) {
            if ('install' === $job['cmd']) {
                foreach ($installedRepo->getPackages() as $package) {
                    if ($job['packageName'] === $package->getName()) {
                        continue 2;
                    }
                }
                foreach ($operations as $operation) {
                    if ('install' === $operation->getJobType() && $job['packageName'] === $operation->getPackage()->getName()) {
                        continue 2;
                    }
                }
                throw new \UnexpectedValueException('Package '.$job['packageName'].' could not be resolved to an installable package.');
            }
        }

        // execute operations
        foreach ($operations as $operation) {
            $installationManager->execute($operation);
        }

        if ($update || !$composer->getLocker()->isLocked()) {
            $composer->getLocker()->lockPackages($localRepo->getPackages());
            $output->writeln('> Locked');
        }

        $localRepo->write();

        $output->writeln('> Generating autoload files');
        $generator = new AutoloadGenerator;
        $generator->dump($localRepo, $composer->getPackage(), $installationManager, $installationManager->getVendorPath().'/.composer');

        $output->writeln('> Done');
    }
}
