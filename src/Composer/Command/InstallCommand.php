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

use Composer\Script\ScriptEvents;
use Composer\Script\EventDispatcher;
use Composer\Autoload\AutoloadGenerator;
use Composer\Composer;
use Composer\DependencyResolver;
use Composer\DependencyResolver\Pool;
use Composer\DependencyResolver\Request;
use Composer\DependencyResolver\Operation;
use Composer\Package\MemoryPackage;
use Composer\Package\LinkConstraint\VersionConstraint;
use Composer\Package\PackageInterface;
use Composer\Repository\CompositeRepository;
use Composer\Repository\PlatformRepository;
use Composer\Repository\RepositoryInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\DependencyResolver\Solver;
use Composer\IO\IOInterface;

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
                new InputOption('prefer-source', null, InputOption::VALUE_NONE, 'Forces installation from package sources when possible, including VCS information.'),
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
        $composer = $this->getComposer();
        $io = $this->getApplication()->getIO();
        $eventDispatcher = new EventDispatcher($composer, $io);

        return $this->install(
            $io,
            $composer,
            $eventDispatcher,
            (Boolean)$input->getOption('prefer-source'),
            (Boolean)$input->getOption('dry-run'),
            (Boolean)$input->getOption('verbose'),
            (Boolean)$input->getOption('no-install-recommends'),
            (Boolean)$input->getOption('install-suggests')
        );
    }

    public function install(IOInterface $io, Composer $composer, EventDispatcher $eventDispatcher, $preferSource = false, $dryRun = false, $verbose = false, $noInstallRecommends = false, $installSuggests = false, $update = false, RepositoryInterface $additionalInstalledRepository = null)
    {
        if ($dryRun) {
            $verbose = true;
        }

        if ($preferSource) {
            $composer->getDownloadManager()->setPreferSource(true);
        }

        // create local repo, this contains all packages that are installed in the local project
        $localRepo = $composer->getRepositoryManager()->getLocalRepository();
        // create installed repo, this contains all local packages + platform packages (php & extensions)
        $installedRepo = new CompositeRepository(array($localRepo, new PlatformRepository()));
        if ($additionalInstalledRepository) {
            $installedRepo->addRepository($additionalInstalledRepository);
        }

        // creating repository pool
        $pool = new Pool;
        $pool->addRepository($installedRepo);
        foreach ($composer->getRepositoryManager()->getRepositories() as $repository) {
            $pool->addRepository($repository);
        }

        // dispatch pre event
        if (!$dryRun) {
            $eventName = $update ? ScriptEvents::PRE_UPDATE_CMD : ScriptEvents::PRE_INSTALL_CMD;
            $eventDispatcher->dispatchCommandEvent($eventName);
        }

        // creating requirements request
        $request = new Request($pool);
        if ($update) {
            $io->write('<info>Updating dependencies</info>');
            $installedPackages = $installedRepo->getPackages();
            $links = $this->collectLinks($composer->getPackage(), $noInstallRecommends, $installSuggests);

            $request->updateAll();

            foreach ($links as $link) {
                $request->install($link->getTarget(), $link->getConstraint());
            }
        } elseif ($composer->getLocker()->isLocked()) {
            $io->write('<info>Installing from lock file</info>');

            if (!$composer->getLocker()->isFresh()) {
                $io->write('<warning>Your lock file is out of sync with your composer.json, run "composer.phar update" to update dependencies</warning>');
            }

            foreach ($composer->getLocker()->getLockedPackages() as $package) {
                $constraint = new VersionConstraint('=', $package->getVersion());
                $request->install($package->getName(), $constraint);
            }
        } else {
            $io->write('<info>Installing dependencies</info>');

            $links = $this->collectLinks($composer->getPackage(), $noInstallRecommends, $installSuggests);

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
                foreach ($installedRepo->getPackages() as $package ) {
                    if ($installedRepo->hasPackage($package) && !$package->isPlatform() && !$installationManager->isPackageInstalled($package)) {
                        $operations[$job['packageName']] = new InstallOperation($package, Solver::RULE_PACKAGE_NOT_EXIST);
                    }
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
            $io->write('<info>Nothing to install/update</info>');
        }
        foreach ($operations as $operation) {
            if ($verbose) {
                $io->write((string) $operation);
            }
            if (!$dryRun) {
                $eventDispatcher->dispatchPackageEvent(constant('Composer\Script\ScriptEvents::PRE_PACKAGE_'.strtoupper($operation->getJobType())), $operation);
                $installationManager->execute($operation);
                $eventDispatcher->dispatchPackageEvent(constant('Composer\Script\ScriptEvents::POST_PACKAGE_'.strtoupper($operation->getJobType())), $operation);
            }
        }

        if (!$dryRun) {
            if ($update || !$composer->getLocker()->isLocked()) {
                $composer->getLocker()->lockPackages($localRepo->getPackages());
                $io->write('<info>Writing lock file</info>');
            }

            $localRepo->write();

            $io->write('<info>Generating autoload files</info>');
            $generator = new AutoloadGenerator;
            $generator->dump($localRepo, $composer->getPackage(), $installationManager, $installationManager->getVendorPath().'/.composer');

            // dispatch post event
            $eventName = $update ? ScriptEvents::POST_UPDATE_CMD : ScriptEvents::POST_INSTALL_CMD;
            $eventDispatcher->dispatchCommandEvent($eventName);
        }
    }

    private function collectLinks(PackageInterface $package, $noInstallRecommends, $installSuggests)
    {
        $links = $package->getRequires();

        if (!$noInstallRecommends) {
            $links = array_merge($links, $package->getRecommends());
        }

        if ($installSuggests) {
            $links = array_merge($links, $package->getSuggests());
        }

        return $links;
    }
}
