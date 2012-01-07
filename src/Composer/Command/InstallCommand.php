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
use Composer\Package\PackageInterface;
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
                new InputOption('dry-run', null, InputOption::VALUE_NONE, 'Outputs the operations but will not execute anything (implicitly enables --verbose).'),
                new InputOption('no-install-recommends', null, InputOption::VALUE_NONE, 'Do not install recommended packages (ignored when installing from an existing lock file).'),
                new InputOption('install-suggests', null, InputOption::VALUE_NONE, 'Also install suggested packages (ignored when installing from an existing lock file).'),
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
        return $this->install($input, $output);
    }

    public function install(InputInterface $input, OutputInterface $output, $update = false)
    {
        $preferSource = (Boolean) $input->getOption('dev');
        $dryRun = (Boolean) $input->getOption('dry-run');
        $verbose = $dryRun || $input->getOption('verbose');
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
            $output->writeln('<info>Updating dependencies</info>');
            $installedPackages = $installedRepo->getPackages();
            $links = $this->collectLinks($input, $composer->getPackage());

            foreach ($links as $link) {
                foreach ($installedPackages as $package) {
                    if ($package->getName() === $link->getTarget()) {
                        $request->update($package->getName(), new VersionConstraint('=', $package->getVersion()));
                        break;
                    }
                }

                $request->install($link->getTarget(), $link->getConstraint());
            }
        } elseif ($composer->getLocker()->isLocked()) {
            $output->writeln('<info>Installing from lock file</info>');

            if (!$composer->getLocker()->isFresh()) {
                $output->writeln('<warning>Your lock file is out of sync with your composer.json, run "composer.phar update" to update dependencies</warning>');
            }

            foreach ($composer->getLocker()->getLockedPackages() as $package) {
                $constraint = new VersionConstraint('=', $package->getVersion());
                $request->install($package->getName(), $constraint);
            }
        } else {
            $output->writeln('<info>Installing dependencies.</info>');

            $links = $this->collectLinks($input, $composer->getPackage());

            foreach ($links as $link) {
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
                    if (in_array($job['packageName'], $package->getNames())) {
                        continue 2;
                    }
                }
                foreach ($operations as $operation) {
                    if ('install' === $operation->getJobType() && in_array($job['packageName'], $operation->getPackage()->getNames())) {
                        continue 2;
                    }
                    if ('update' === $operation->getJobType() && in_array($job['packageName'], $operation->getTargetPackage()->getNames())) {
                        continue 2;
                    }
                }

                if ($pool->whatProvides($job['packageName'])) {
                    throw new \UnexpectedValueException('Package '.$job['packageName'].' can not be installed, either because its version constraint is incorrect, or because one of its dependencies was not found.');
                }
                throw new \UnexpectedValueException('Package '.$job['packageName'].' was not found in the package pool, check the name for typos.');
            }
        }

        // execute operations
        if (!$operations) {
            $output->writeln('<info>Nothing to install/update</info>');
        }
        foreach ($operations as $operation) {
            if ($verbose) {
                $output->writeln((string) $operation);
            }
            if (!$dryRun) {
                $installationManager->execute($operation);
            }
        }

        if (!$dryRun) {
            if ($update || !$composer->getLocker()->isLocked()) {
                $composer->getLocker()->lockPackages($localRepo->getPackages());
                $output->writeln('<info>Writing lock file</info>');
            }

            $localRepo->write();

            $output->writeln('<info>Generating autoload files</info>');
            $generator = new AutoloadGenerator;
            $generator->dump($localRepo, $composer->getPackage(), $installationManager, $installationManager->getVendorPath().'/.composer');
        }
    }

    private function collectLinks(InputInterface $input, PackageInterface $package)
    {
        $links = $package->getRequires();

        if (!$input->getOption('no-install-recommends')) {
            $links = array_merge($links, $package->getRecommends());
        }

        if ($input->getOption('install-suggests')) {
            $links = array_merge($links, $package->getSuggests());
        }

        return $links;
    }
}
