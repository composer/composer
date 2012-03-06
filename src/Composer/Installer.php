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

namespace Composer;

use Composer\Autoload\AutoloadGenerator;
use Composer\DependencyResolver\DefaultPolicy;
use Composer\DependencyResolver\Operation\UpdateOperation;
use Composer\DependencyResolver\Pool;
use Composer\DependencyResolver\Request;
use Composer\DependencyResolver\Solver;
use Composer\Downloader\DownloadManager;
use Composer\Installer\InstallationManager;
use Composer\IO\IOInterface;
use Composer\Package\AliasPackage;
use Composer\Package\Link;
use Composer\Package\LinkConstraint\VersionConstraint;
use Composer\Package\Locker;
use Composer\Package\PackageInterface;
use Composer\Repository\CompositeRepository;
use Composer\Repository\PlatformRepository;
use Composer\Repository\RepositoryInterface;
use Composer\Repository\RepositoryManager;
use Composer\Script\EventDispatcher;
use Composer\Script\ScriptEvents;

class Installer
{
    /**
     * 
     * @var IOInterface
     */
    protected $io;

    /**
     *
     * @var PackageInterface
     */
    protected $package;

    /**
     * 
     * @var DownloadManager
     */
    protected $downloadManager;

    /**
     * 
     * @var RepositoryManager
     */
    protected $repositoryManager;

    /**
     * 
     * @var Locker
     */
    protected $locker;

    /**
     * 
     * @var InstallationManager
     */
    protected $installationManager;

    /**
     * 
     * @var EventDispatcher
     */
    protected $eventDispatcher;

    /**
     * Constructor
     * 
     * @param IOInterface $io
     * @param PackageInterface $package
     * @param DownloadManager $downloadManager
     * @param RepositoryManager $repositoryManager
     * @param Locker $locker
     * @param InstallationManager $installationManager
     * @param EventDispatcher $eventDispatcher
     */
    public function __construct(IOInterface $io, PackageInterface $package, DownloadManager $downloadManager, RepositoryManager $repositoryManager, Locker $locker, InstallationManager $installationManager, EventDispatcher $eventDispatcher)
    {
        $this->io = $io;
        $this->package = $package;
        $this->downloadManager = $downloadManager;
        $this->repositoryManager = $repositoryManager;
        $this->locker = $locker;
        $this->installationManager = $installationManager;
        $this->eventDispatcher = $eventDispatcher;
    }

    /**
     * Run installation (or update)
     *
     * @param bool $preferSource
     * @param bool $dryRun
     * @param bool $verbose
     * @param bool $noInstallRecommends
     * @param bool $installSuggests
     * @param bool $update
     * @param RepositoryInterface $additionalInstalledRepository
     */
    public function run($preferSource = false, $dryRun = false, $verbose = false, $noInstallRecommends = false, $installSuggests = false, $update = false, RepositoryInterface $additionalInstalledRepository = null)
    {
        if ($dryRun) {
            $verbose = true;
        }

        if ($preferSource) {
            $this->downloadManager->setPreferSource(true);
        }

        $this->repositoryManager = $this->repositoryManager;

        // create local repo, this contains all packages that are installed in the local project
        $localRepo = $this->repositoryManager->getLocalRepository();
        // create installed repo, this contains all local packages + platform packages (php & extensions)
        $installedRepo = new CompositeRepository(array($localRepo, new PlatformRepository()));
        if ($additionalInstalledRepository) {
            $installedRepo->addRepository($additionalInstalledRepository);
        }

        // prepare aliased packages
        if (!$update && $this->locker->isLocked()) {
            $aliases = $this->locker->getAliases();
        } else {
            $aliases = $this->package->getAliases();
        }
        foreach ($aliases as $alias) {
            foreach ($this->repositoryManager->findPackages($alias['package'], $alias['version']) as $package) {
                $package->getRepository()->addPackage(new AliasPackage($package, $alias['alias_normalized'], $alias['alias']));
            }
            foreach ($this->repositoryManager->getLocalRepository()->findPackages($alias['package'], $alias['version']) as $package) {
                $this->repositoryManager->getLocalRepository()->addPackage(new AliasPackage($package, $alias['alias_normalized'], $alias['alias']));
                $this->repositoryManager->getLocalRepository()->removePackage($package);
            }
        }

        // creating repository pool
        $pool = new Pool;
        $pool->addRepository($installedRepo);
        foreach ($this->repositoryManager->getRepositories() as $repository) {
            $pool->addRepository($repository);
        }

        // dispatch pre event
        if (!$dryRun) {
            $eventName = $update ? ScriptEvents::PRE_UPDATE_CMD : ScriptEvents::PRE_INSTALL_CMD;
            $this->eventDispatcher->dispatchCommandEvent($eventName);
        }

        // creating requirements request
        $installFromLock = false;
        $request = new Request($pool);
        if ($update) {
            $this->io->write('<info>Updating dependencies</info>');

            $request->updateAll();

            $links = $this->collectLinks($noInstallRecommends, $installSuggests);

            foreach ($links as $link) {
                $request->install($link->getTarget(), $link->getConstraint());
            }
        } elseif ($this->locker->isLocked()) {
            $installFromLock = true;
            $this->io->write('<info>Installing from lock file</info>');

            if (!$this->locker->isFresh()) {
                $this->io->write('<warning>Your lock file is out of sync with your composer.json, run "composer.phar update" to update dependencies</warning>');
            }

            foreach ($this->locker->getLockedPackages() as $package) {
                $version = $package->getVersion();
                foreach ($aliases as $alias) {
                    if ($alias['package'] === $package->getName() && $alias['version'] === $package->getVersion()) {
                        $version = $alias['alias'];
                        break;
                    }
                }
                $constraint = new VersionConstraint('=', $version);
                $request->install($package->getName(), $constraint);
            }
        } else {
            $this->io->write('<info>Installing dependencies</info>');

            $links = $this->collectLinks($noInstallRecommends, $installSuggests);

            foreach ($links as $link) {
                $request->install($link->getTarget(), $link->getConstraint());
            }
        }

        // prepare solver
        $policy              = new DefaultPolicy();
        $solver              = new Solver($policy, $pool, $installedRepo);

        // solve dependencies
        $operations = $solver->solve($request);

        // force dev packages to be updated to latest reference on update
        if ($update) {
            foreach ($localRepo->getPackages() as $package) {
                if ($package instanceof AliasPackage) {
                    $package = $package->getAliasOf();
                }

                // skip non-dev packages
                if (!$package->isDev()) {
                    continue;
                }

                // skip packages that will be updated/uninstalled
                foreach ($operations as $operation) {
                    if (('update' === $operation->getJobType() && $package === $operation->getInitialPackage())
                        || ('uninstall' === $operation->getJobType() && $package === $operation->getPackage())
                    ) {
                        continue 2;
                    }
                }

                // force update
                $newPackage = $this->repositoryManager->findPackage($package->getName(), $package->getVersion());
                if ($newPackage && $newPackage->getSourceReference() !== $package->getSourceReference()) {
                    $operations[] = new UpdateOperation($package, $newPackage);
                }
            }
        }

        // anti-alias local repository to allow updates to work fine
        foreach ($this->repositoryManager->getLocalRepository()->getPackages() as $package) {
            if ($package instanceof AliasPackage) {
                $this->repositoryManager->getLocalRepository()->addPackage(clone $package->getAliasOf());
                $this->repositoryManager->getLocalRepository()->removePackage($package);
            }
        }

        // execute operations
        if (!$operations) {
            $this->io->write('<info>Nothing to install/update</info>');
        }

        foreach ($operations as $operation) {
            if ($verbose) {
                $this->io->write((string) $operation);
            }
            if (!$dryRun) {
                $this->eventDispatcher->dispatchPackageEvent(constant('Composer\Script\ScriptEvents::PRE_PACKAGE_'.strtoupper($operation->getJobType())), $operation);

                // if installing from lock, restore dev packages' references to their locked state
                if ($installFromLock) {
                    $package = null;
                    if ('update' === $operation->getJobType()) {
                        $package = $operation->getTargetPackage();
                    } elseif ('install' === $operation->getJobType()) {
                        $package = $operation->getPackage();
                    }
                    if ($package && $package->isDev()) {
                        $lockData = $this->locker->getLockData();
                        foreach ($lockData['packages'] as $lockedPackage) {
                            if (!empty($lockedPackage['source-reference']) && strtolower($lockedPackage['package']) === $package->getName()) {
                                $package->setSourceReference($lockedPackage['source-reference']);
                                break;
                            }
                        }
                    }
                }
                $this->installationManager->execute($operation);

                $this->eventDispatcher->dispatchPackageEvent(constant('Composer\Script\ScriptEvents::POST_PACKAGE_'.strtoupper($operation->getJobType())), $operation);
            }
        }

        if (!$dryRun) {
            if ($update || !$this->locker->isLocked()) {
                $this->locker->setLockData($localRepo->getPackages(), $aliases);
                $this->io->write('<info>Writing lock file</info>');
            }

            $localRepo->write();

            $this->io->write('<info>Generating autoload files</info>');
            $generator = new AutoloadGenerator;
            $generator->dump($localRepo, $this->package, $this->installationManager, $this->installationManager->getVendorPath().'/.composer');

            // dispatch post event
            $eventName = $update ? ScriptEvents::POST_UPDATE_CMD : ScriptEvents::POST_INSTALL_CMD;
            $this->eventDispatcher->dispatchCommandEvent($eventName);
        }
    }

    private function collectLinks($noInstallRecommends, $installSuggests)
    {
        $links = $this->package->getRequires();

        if (!$noInstallRecommends) {
            $links = array_merge($links, $this->package->getRecommends());
        }

        if ($installSuggests) {
            $links = array_merge($links, $this->package->getSuggests());
        }

        return $links;
    }

    /**
     * Create Installer
     * 
     * @param IOInterface $io
     * @param Composer $composer
     * @param EventDispatcher $eventDispatcher
     * @return Installer
     */
    static public function create(IOInterface $io, Composer $composer, EventDispatcher $eventDispatcher)
    {
        return new static(
            $io,
            $composer->getPackage(),
            $composer->getDownloadManager(),
            $composer->getRepositoryManager(),
            $composer->getLocker(),
            $composer->getInstallationManager(),
            $eventDispatcher
        );
    }
}
