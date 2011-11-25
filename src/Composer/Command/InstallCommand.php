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
                new InputOption('required-only', null, InputOption::VALUE_NONE, 'Installs required packages only (ignored when installing from an existing lock file).'),
                new InputOption('include-suggested', null, InputOption::VALUE_NONE, 'Includes suggested packages (ignored when installing from an existing lock file).'),
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
            $output->writeln('> Updating dependencies.');
            $listedPackages = array();
            $installedPackages = $installedRepo->getPackages();
            $links = $this->collectLinks($input, $composer->getPackage());

            foreach ($links as $link) {
                $listedPackages[] = $link->getTarget();

                foreach ($installedPackages as $package) {
                    if ($package->getName() === $link->getTarget()) {
                        $constraint = new VersionConstraint('=', $package->getVersion());
                        if ($link->getConstraint()->matches($constraint)) {
                            continue 2;
                        }
                        // TODO this should just update to the exact version (once constraints are available on update, see #125)
                        $request->remove($package->getName(), $constraint);
                        break;
                    }
                }

                $request->install($link->getTarget(), $link->getConstraint());
            }
        } elseif ($composer->getLocker()->isLocked()) {
            $output->writeln('> Found lockfile. Reading.');

            foreach ($composer->getLocker()->getLockedPackages() as $package) {
                $constraint = new VersionConstraint('=', $package->getVersion());
                $request->install($package->getName(), $constraint);
            }
        } else {
            $output->writeln('> Installing dependencies.');

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
                    throw new \UnexpectedValueException('Your version constraint for package '.$job['packageName'].' does not match any existing version, if it only has -dev versions make sure you include -dev in your version constraint.');
                }
                throw new \UnexpectedValueException('Package '.$job['packageName'].' was not found in the package pool, check the name for typos.');
            }
        }

        // execute operations
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
                $output->writeln('> Locked');
            }

            $localRepo->write();

            $output->writeln('> Generating autoload files');
            $generator = new AutoloadGenerator;
            $generator->dump($localRepo, $composer->getPackage(), $installationManager, $installationManager->getVendorPath().'/.composer');
        }

        $output->writeln('> Done');
    }

    private function collectLinks(InputInterface $input, PackageInterface $package)
    {
        $requiredOnly     = (Boolean) $input->getOption('required-only');
        $includeSuggested = (Boolean) $input->getOption('include-suggested');

        $links = $package->getRequires();

        if (!$requiredOnly) {
            $links = array_merge($links, $package->getRecommends());

            if ($includeSuggested) {
                $links = array_merge($links, $package->getSuggests());
            }
        }

        return $links;
    }
}
