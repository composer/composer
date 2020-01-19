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
use Composer\DependencyResolver\LocalRepoTransaction;
use Composer\DependencyResolver\LockTransaction;
use Composer\DependencyResolver\Operation\UpdateOperation;
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\DependencyResolver\Operation\UninstallOperation;
use Composer\DependencyResolver\Operation\MarkAliasUninstalledOperation;
use Composer\DependencyResolver\Operation\OperationInterface;
use Composer\DependencyResolver\PolicyInterface;
use Composer\DependencyResolver\Pool;
use Composer\DependencyResolver\Request;
use Composer\DependencyResolver\Rule;
use Composer\DependencyResolver\Solver;
use Composer\DependencyResolver\SolverProblemsException;
use Composer\Downloader\DownloadManager;
use Composer\EventDispatcher\EventDispatcher;
use Composer\Installer\InstallationManager;
use Composer\Installer\InstallerEvents;
use Composer\Installer\NoopInstaller;
use Composer\Installer\SuggestedPackagesReporter;
use Composer\IO\IOInterface;
use Composer\Package\AliasPackage;
use Composer\Package\RootAliasPackage;
use Composer\Package\BasePackage;
use Composer\Package\CompletePackage;
use Composer\Package\Link;
use Composer\Package\LinkConstraint\VersionConstraint;
use Composer\Package\Loader\ArrayLoader;
use Composer\Package\Dumper\ArrayDumper;
use Composer\Package\Package;
use Composer\Repository\ArrayRepository;
use Composer\Repository\RepositorySet;
use Composer\Semver\Constraint\Constraint;
use Composer\Package\Locker;
use Composer\Package\PackageInterface;
use Composer\Package\RootPackageInterface;
use Composer\Repository\CompositeRepository;
use Composer\Repository\InstalledArrayRepository;
use Composer\Repository\RootPackageRepository;
use Composer\Repository\PlatformRepository;
use Composer\Repository\RepositoryInterface;
use Composer\Repository\RepositoryManager;
use Composer\Repository\WritableRepositoryInterface;
use Composer\Script\ScriptEvents;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 * @author Beau Simensen <beau@dflydev.com>
 * @author Konstantin Kudryashov <ever.zet@gmail.com>
 * @author Nils Adermann <naderman@naderman.de>
 */
class Installer
{
    /**
     * @var IOInterface
     */
    protected $io;

    /**
     * @var Config
     */
    protected $config;

    /**
     * @var RootPackageInterface
     */
    protected $package;

    // TODO can we get rid of the below and just use the package itself?
    /**
     * @var RootPackageInterface
     */
    protected $fixedRootPackage;

    /**
     * @var DownloadManager
     */
    protected $downloadManager;

    /**
     * @var RepositoryManager
     */
    protected $repositoryManager;

    /**
     * @var Locker
     */
    protected $locker;

    /**
     * @var InstallationManager
     */
    protected $installationManager;

    /**
     * @var EventDispatcher
     */
    protected $eventDispatcher;

    /**
     * @var AutoloadGenerator
     */
    protected $autoloadGenerator;

    protected $preferSource = false;
    protected $preferDist = false;
    protected $optimizeAutoloader = false;
    protected $classMapAuthoritative = false;
    protected $apcuAutoloader = false;
    protected $devMode = false;
    protected $dryRun = false;
    protected $verbose = false;
    protected $update = false;
    protected $dumpAutoloader = true;
    protected $runScripts = true;
    protected $ignorePlatformReqs = false;
    protected $preferStable = false;
    protected $preferLowest = false;
    protected $skipSuggest = false;
    protected $writeLock;
    protected $executeOperations = true;

    /**
     * Array of package names/globs flagged for update
     *
     * @var array|null
     */
    protected $updateMirrors = false;
    protected $updateWhitelist = null;
    protected $whitelistTransitiveDependencies = false;
    protected $whitelistAllDependencies = false;

    /**
     * @var SuggestedPackagesReporter
     */
    protected $suggestedPackagesReporter;

    /**
     * @var RepositoryInterface
     */
    protected $additionalFixedRepository;

    /**
     * Constructor
     *
     * @param IOInterface          $io
     * @param Config               $config
     * @param RootPackageInterface $package
     * @param DownloadManager      $downloadManager
     * @param RepositoryManager    $repositoryManager
     * @param Locker               $locker
     * @param InstallationManager  $installationManager
     * @param EventDispatcher      $eventDispatcher
     * @param AutoloadGenerator    $autoloadGenerator
     */
    public function __construct(IOInterface $io, Config $config, RootPackageInterface $package, DownloadManager $downloadManager, RepositoryManager $repositoryManager, Locker $locker, InstallationManager $installationManager, EventDispatcher $eventDispatcher, AutoloadGenerator $autoloadGenerator)
    {
        $this->io = $io;
        $this->config = $config;
        $this->package = $package;
        $this->downloadManager = $downloadManager;
        $this->repositoryManager = $repositoryManager;
        $this->locker = $locker;
        $this->installationManager = $installationManager;
        $this->eventDispatcher = $eventDispatcher;
        $this->autoloadGenerator = $autoloadGenerator;

        $this->writeLock = $config->get('lock');
    }

    /**
     * Run installation (or update)
     *
     * @throws \Exception
     * @return int        0 on success or a positive error code on failure
     */
    public function run()
    {
        // Disable GC to save CPU cycles, as the dependency solver can create hundreds of thousands
        // of PHP objects, the GC can spend quite some time walking the tree of references looking
        // for stuff to collect while there is nothing to collect. This slows things down dramatically
        // and turning it off results in much better performance. Do not try this at home however.
        gc_collect_cycles();
        gc_disable();

        if ($this->updateWhitelist && $this->updateMirrors) {
            throw new \RuntimeException("The installer options updateMirrors and updateWhitelist are mutually exclusive.");
        }

        // Force update if there is no lock file present
        if (!$this->update && !$this->locker->isLocked()) {
            $this->io->writeError('<warning>No lock file found. Updating dependencies instead of installing from lock file. Use composer update over composer install if you do not have a lock file.</warning>');
            $this->update = true;
        }

        if ($this->dryRun) {
            $this->verbose = true;
            $this->runScripts = false;
            $this->executeOperations = false;
            $this->writeLock = false;
            $this->dumpAutoloader = false;
            $this->mockLocalRepositories($this->repositoryManager);
        }

        if ($this->runScripts) {
            $devMode = (int) $this->devMode;
            putenv("COMPOSER_DEV_MODE=$devMode");

            // dispatch pre event
            // should we treat this more strictly as running an update and then running an install, triggering events multiple times?
            $eventName = $this->update ? ScriptEvents::PRE_UPDATE_CMD : ScriptEvents::PRE_INSTALL_CMD;
            $this->eventDispatcher->dispatchScript($eventName, $this->devMode);
        }

        $this->downloadManager->setPreferSource($this->preferSource);
        $this->downloadManager->setPreferDist($this->preferDist);

        $localRepo = $this->repositoryManager->getLocalRepository();

        if (!$this->suggestedPackagesReporter) {
            $this->suggestedPackagesReporter = new SuggestedPackagesReporter($this->io);
        }

        try {
            if ($this->update) {
                // TODO introduce option to set doInstall to false (update lock file without vendor install)
                $res = $this->doUpdate($localRepo, true);
            } else {
                $res = $this->doInstall($localRepo);
            }
            if ($res !== 0) {
                return $res;
            }
        } catch (\Exception $e) {
            if ($this->executeOperations) {
                $this->installationManager->notifyInstalls($this->io);
            }

            throw $e;
        }
        if ($this->executeOperations) {
            $this->installationManager->notifyInstalls($this->io);
        }

        // output suggestions if we're in dev mode
        if ($this->update && $this->devMode && !$this->skipSuggest) {
            $this->suggestedPackagesReporter->output($this->locker->getLockedRepository($this->devMode));
        }

        // TODO probably makes more sense to do this on the lock file only?
        # Find abandoned packages and warn user
        foreach ($localRepo->getPackages() as $package) {
            if (!$package instanceof CompletePackage || !$package->isAbandoned()) {
                continue;
            }

            $replacement = is_string($package->getReplacementPackage())
                ? 'Use ' . $package->getReplacementPackage() . ' instead'
                : 'No replacement was suggested';

            $this->io->writeError(
                sprintf(
                    "<warning>Package %s is abandoned, you should avoid using it. %s.</warning>",
                    $package->getPrettyName(),
                    $replacement
                )
            );
        }

        if ($this->dumpAutoloader) {
            // write autoloader
            if ($this->optimizeAutoloader) {
                $this->io->writeError('<info>Generating optimized autoload files</info>');
            } else {
                $this->io->writeError('<info>Generating autoload files</info>');
            }

            $this->autoloadGenerator->setDevMode($this->devMode);
            $this->autoloadGenerator->setClassMapAuthoritative($this->classMapAuthoritative);
            $this->autoloadGenerator->setApcu($this->apcuAutoloader);
            $this->autoloadGenerator->setRunScripts($this->runScripts);
            $this->autoloadGenerator->dump($this->config, $localRepo, $this->package, $this->installationManager, 'composer', $this->optimizeAutoloader);
        }

        if ($this->executeOperations) {
            // force binaries re-generation in case they are missing
            foreach ($localRepo->getPackages() as $package) {
                $this->installationManager->ensureBinariesPresence($package);
            }

            $vendorDir = $this->config->get('vendor-dir');
            if (is_dir($vendorDir)) {
                // suppress errors as this fails sometimes on OSX for no apparent reason
                // see https://github.com/composer/composer/issues/4070#issuecomment-129792748
                @touch($vendorDir);
            }
        }

        if ($this->runScripts) {
            // dispatch post event
            $eventName = $this->update ? ScriptEvents::POST_UPDATE_CMD : ScriptEvents::POST_INSTALL_CMD;
            $this->eventDispatcher->dispatchScript($eventName, $this->devMode);
        }

        // re-enable GC except on HHVM which triggers a warning here
        if (!defined('HHVM_VERSION')) {
            gc_enable();
        }

        return 0;
    }

    protected function doUpdate(RepositoryInterface $localRepo, $doInstall)
    {
        $platformRepo = $this->createPlatformRepo(true);
        $aliases = $this->getRootAliases(true);

        $lockedRepository = null;

        if ($this->locker->isLocked()) {
            $lockedRepository = $this->locker->getLockedRepository(true);
        }

        if ($this->updateWhitelist) {
            if (!$lockedRepository) {
                $this->io->writeError('<error>Cannot update only a partial set of packages without a lock file present.</error>', true, IOInterface::QUIET);
                return 1;
            }
            $this->whitelistUpdateDependencies(
                $lockedRepository,
                $this->package->getRequires(),
                $this->package->getDevRequires()
            );
        }

        $this->io->writeError('<info>Loading composer repositories with package information</info>');

        // creating repository set
        $policy = $this->createPolicy(true);
        $repositorySet = $this->createRepositorySet($platformRepo, $aliases);
        $repositories = $this->repositoryManager->getRepositories();
        foreach ($repositories as $repository) {
            $repositorySet->addRepository($repository);
        }
        if ($lockedRepository) {
            $repositorySet->addRepository($lockedRepository);
        }
        // TODO can we drop any locked packages that we have matching remote versions for?

        $request = $this->createRequest($this->fixedRootPackage, $platformRepo, $lockedRepository);

        $this->io->writeError('<info>Updating dependencies</info>');

        $links = array_merge($this->package->getRequires(), $this->package->getDevRequires());

        // if we're updating mirrors we want to keep exactly the same versions installed which are in the lock file, but we want current remote metadata
        if ($this->updateMirrors) {
            foreach ($lockedRepository->getPackages() as $lockedPackage) {
                $request->require($lockedPackage->getName(), new Constraint('==', $lockedPackage->getVersion()));
            }
        } else {
            foreach ($links as $link) {
                $request->require($link->getTarget(), $link->getConstraint());
            }
        }

        // if the updateWhitelist is enabled, packages not in it are also fixed
        // to the version specified in the lock
        if ($this->updateWhitelist && $lockedRepository) {
            foreach ($lockedRepository->getPackages() as $lockedPackage) {
                if (!$this->isUpdateable($lockedPackage)) {
                    // TODO add reason for fix?
                    $request->fixPackage($lockedPackage);
                }
            }
        }

        // TODO reenable events
        //$this->eventDispatcher->dispatchInstallerEvent(InstallerEvents::PRE_DEPENDENCIES_SOLVING, $this->devMode, $policy, $repositorySet, $installedRepo, $request);

        $pool = $repositorySet->createPool($request);

        // TODO ensure that the solver always picks most recent reference for dev packages, so they get updated even when just a new commit is pushed but version is unchanged
        // should already be solved by using the remote package in all cases in the pool

        // solve dependencies
        $solver = new Solver($policy, $pool, $this->io);
        try {
            $lockTransaction = $solver->solve($request, $this->ignorePlatformReqs);
            $ruleSetSize = $solver->getRuleSetSize();
            $solver = null;
        } catch (SolverProblemsException $e) {
            $this->io->writeError('<error>Your requirements could not be resolved to an installable set of packages.</error>', true, IOInterface::QUIET);
            $this->io->writeError($e->getMessage());
            if (!$this->devMode) {
                $this->io->writeError('<warning>Running update with --no-dev does not mean require-dev is ignored, it just means the packages will not be installed. If dev requirements are blocking the update you have to resolve those problems.</warning>', true, IOInterface::QUIET);
            }

            return max(1, $e->getCode());
        }

        // TODO should we warn people / error if plugins in vendor folder do not match contents of lock file before update?
        //$this->eventDispatcher->dispatchInstallerEvent(InstallerEvents::POST_DEPENDENCIES_SOLVING, $this->devMode, $policy, $repositorySet, $lockedRepository, $request, $lockTransaction);

        $this->io->writeError("Analyzed ".count($pool)." packages to resolve dependencies", true, IOInterface::VERBOSE);
        $this->io->writeError("Analyzed ".$ruleSetSize." rules to resolve dependencies", true, IOInterface::VERBOSE);

        if (!$lockTransaction->getOperations()) {
            $this->io->writeError('Nothing to modify in lock file');
        }

        $this->extractDevPackages($lockTransaction, $platformRepo, $aliases, $policy);

        // write lock
        $platformReqs = $this->extractPlatformRequirements($this->package->getRequires());
        $platformDevReqs = $this->extractPlatformRequirements($this->package->getDevRequires());

        if ($lockTransaction->getOperations()) {
            $installs = $updates = $uninstalls = array();
            foreach ($lockTransaction->getOperations() as $operation) {
                if ($operation instanceof InstallOperation) {
                    $installs[] = $operation->getPackage()->getPrettyName().':'.$operation->getPackage()->getFullPrettyVersion();
                } elseif ($operation instanceof UpdateOperation) {
                    $updates[] = $operation->getTargetPackage()->getPrettyName().':'.$operation->getTargetPackage()->getFullPrettyVersion();
                } elseif ($operation instanceof UninstallOperation) {
                    $uninstalls[] = $operation->getPackage()->getPrettyName();
                }
            }

            $this->io->writeError(sprintf(
                "<info>Lock file operations: %d install%s, %d update%s, %d removal%s</info>",
                count($installs),
                1 === count($installs) ? '' : 's',
                count($updates),
                1 === count($updates) ? '' : 's',
                count($uninstalls),
                1 === count($uninstalls) ? '' : 's'
            ));
            if ($installs) {
                $this->io->writeError("Installs: ".implode(', ', $installs), true, IOInterface::VERBOSE);
            }
            if ($updates) {
                $this->io->writeError("Updates: ".implode(', ', $updates), true, IOInterface::VERBOSE);
            }
            if ($uninstalls) {
                $this->io->writeError("Removals: ".implode(', ', $uninstalls), true, IOInterface::VERBOSE);
            }
        }

        foreach ($lockTransaction->getOperations() as $operation) {
            // collect suggestions
            if ($operation instanceof InstallOperation) {
                $this->suggestedPackagesReporter->addSuggestionsFromPackage($operation->getPackage());
            }

            // output op, but alias op only in debug verbosity
            if (false === strpos($operation->getOperationType(), 'Alias') || $this->io->isDebug()) {
                $this->io->writeError('  - ' . $operation->show(true));
            }
        }

        $updatedLock = $this->locker->setLockData(
            $lockTransaction->getNewLockPackages(false, $this->updateMirrors),
            $lockTransaction->getNewLockPackages(true, $this->updateMirrors),
            $platformReqs,
            $platformDevReqs,
            $aliases,
            $this->package->getMinimumStability(),
            $this->package->getStabilityFlags(),
            $this->preferStable || $this->package->getPreferStable(),
            $this->preferLowest,
            $this->config->get('platform') ?: array(),
            $this->writeLock && $this->executeOperations
        );
        if ($updatedLock && $this->writeLock && $this->executeOperations) {
            $this->io->writeError('<info>Writing lock file</info>');
        }

        if ($doInstall) {
            // TODO ensure lock is used from locker as-is, since it may not have been written to disk in case of executeOperations == false
            return $this->doInstall($localRepo, true);
        }

        return 0;
    }

    /**
     * Run the solver a second time on top of the existing update result with only the current result set in the pool
     * and see what packages would get removed if we only had the non-dev packages in the solver request
     */
    protected function extractDevPackages(LockTransaction $lockTransaction, $platformRepo, $aliases, $policy)
    {
        if (!$this->package->getDevRequires()) {
            return array();
        }

        $resultRepo = new ArrayRepository(array());
        $loader = new ArrayLoader(null, true);
        $dumper = new ArrayDumper();
        foreach ($lockTransaction->getNewLockPackages(false) as $pkg) {
            $resultRepo->addPackage($loader->load($dumper->dump($pkg)));
        }

        $repositorySet = $this->createRepositorySet($platformRepo, $aliases, null);
        $repositorySet->addRepository($resultRepo);

        $request = $this->createRequest($this->fixedRootPackage, $platformRepo, null);

        $links = $this->package->getRequires();
        foreach ($links as $link) {
            $request->require($link->getTarget(), $link->getConstraint());
        }

        $pool = $repositorySet->createPool($request);

        //$this->eventDispatcher->dispatchInstallerEvent(InstallerEvents::PRE_DEPENDENCIES_SOLVING, false, $policy, $pool, $installedRepo, $request);
        $solver = new Solver($policy, $pool, $this->io);
        try {
            $nonDevLockTransaction = $solver->solve($request, $this->ignorePlatformReqs);
            //$this->eventDispatcher->dispatchInstallerEvent(InstallerEvents::POST_DEPENDENCIES_SOLVING, false, $policy, $pool, $installedRepo, $request, $ops);
            $solver = null;
        } catch (SolverProblemsException $e) {
            $this->io->writeError('<error>Unable to find a compatible set of packages based on your non-dev requirements alone.</error>', true, IOInterface::QUIET);
            $this->io->writeError($e->getMessage());

            return max(1, $e->getCode());
        }

        $lockTransaction->setNonDevPackages($nonDevLockTransaction);
    }

    /**
     * @param RepositoryInterface $localRepo
     * @param bool $alreadySolved Whether the function is called as part of an update command or independently
     * @return int exit code
     */
    protected function doInstall(RepositoryInterface $localRepo, $alreadySolved = false)
    {
        $platformRepo = $this->createPlatformRepo(false);
        $aliases = $this->getRootAliases(false);

        $lockedRepository = $this->locker->getLockedRepository($this->devMode);

        // creating repository set
        $policy = $this->createPolicy(false);
        // use aliases from lock file only, so empty root aliases here
        $repositorySet = $this->createRepositorySet($platformRepo, array(), $lockedRepository);
        $repositorySet->addRepository($lockedRepository);

        $this->io->writeError('<info>Installing dependencies from lock file'.($this->devMode ? ' (including require-dev)' : '').'</info>');

        // verify that the lock file works with the current platform repository
        // we can skip this part if we're doing this as the second step after an update
        if (!$alreadySolved) {
            $this->io->writeError('<info>Verifying lock file contents can be installed on current platform.</info>');

            // creating requirements request
            $request = $this->createRequest($this->fixedRootPackage, $platformRepo, $lockedRepository);

            if (!$this->locker->isFresh()) {
                $this->io->writeError('<warning>Warning: The lock file is not up to date with the latest changes in composer.json. You may be getting outdated dependencies. It is recommended that you run `composer update` or `composer update <package name>`.</warning>', true, IOInterface::QUIET);
            }

            foreach ($lockedRepository->getPackages() as $package) {
                $request->fixPackage($package);
            }

            foreach ($this->locker->getPlatformRequirements($this->devMode) as $link) {
                $request->require($link->getTarget(), $link->getConstraint());
            }

            //$this->eventDispatcher->dispatchInstallerEvent(InstallerEvents::PRE_DEPENDENCIES_SOLVING, $this->devMode, $policy, $repositorySet, $installedRepo, $request);

            $pool = $repositorySet->createPool($request);

            // solve dependencies
            $solver = new Solver($policy, $pool, $this->io);
            try {
                $lockTransaction = $solver->solve($request, $this->ignorePlatformReqs);
                $solver = null;

                // installing the locked packages on this platform resulted in lock modifying operations, there wasn't a conflict, but the lock file as-is seems to not work on this system
                if (0 !== count($lockTransaction->getOperations())) {
                    $this->io->writeError('<error>Your lock file cannot be installed on this system without changes. Please run composer update.</error>', true, IOInterface::QUIET);
                    // TODO actually display operations to explain what happened?
                    return 1;
                }
            } catch (SolverProblemsException $e) {
                $this->io->writeError('<error>Your lock file does not contain a compatible set of packages. Please run composer update.</error>', true, IOInterface::QUIET);
                $this->io->writeError($e->getMessage());

                return max(1, $e->getCode());
            }

            // TODO should we warn people / error if plugins in vendor folder do not match contents of lock file before update?
            //$this->eventDispatcher->dispatchInstallerEvent(InstallerEvents::POST_DEPENDENCIES_SOLVING, $this->devMode, $policy, $repositorySet, $installedRepo, $request, $lockTransaction);
        }

        // TODO in how far do we need to do anything here to ensure dev packages being updated to latest in lock without version change are treated correctly?
        $localRepoTransaction = new LocalRepoTransaction($lockedRepository, $localRepo);

        if (!$localRepoTransaction->getOperations()) {
            $this->io->writeError('Nothing to install, update or remove');
        }

        if ($localRepoTransaction->getOperations()) {
            $installs = $updates = $uninstalls = array();
            foreach ($localRepoTransaction->getOperations() as $operation) {
                if ($operation instanceof InstallOperation) {
                    $installs[] = $operation->getPackage()->getPrettyName().':'.$operation->getPackage()->getFullPrettyVersion();
                } elseif ($operation instanceof UpdateOperation) {
                    $updates[] = $operation->getTargetPackage()->getPrettyName().':'.$operation->getTargetPackage()->getFullPrettyVersion();
                } elseif ($operation instanceof UninstallOperation) {
                    $uninstalls[] = $operation->getPackage()->getPrettyName();
                }
            }

            $this->io->writeError(sprintf(
                "<info>Package operations: %d install%s, %d update%s, %d removal%s</info>",
                count($installs),
                1 === count($installs) ? '' : 's',
                count($updates),
                1 === count($updates) ? '' : 's',
                count($uninstalls),
                1 === count($uninstalls) ? '' : 's'
            ));
            if ($installs) {
                $this->io->writeError("Installs: ".implode(', ', $installs), true, IOInterface::VERBOSE);
            }
            if ($updates) {
                $this->io->writeError("Updates: ".implode(', ', $updates), true, IOInterface::VERBOSE);
            }
            if ($uninstalls) {
                $this->io->writeError("Removals: ".implode(', ', $uninstalls), true, IOInterface::VERBOSE);
            }
        }

        if ($this->executeOperations) {
            $this->installationManager->execute($localRepo, $localRepoTransaction->getOperations(), $this->devMode);
        } else {
            foreach ($localRepoTransaction->getOperations() as $operation) {
                // output op, but alias op only in debug verbosity
                if (false === strpos($operation->getOperationType(), 'Alias') || $this->io->isDebug()) {
                    $this->io->writeError('  - ' . $operation->show(false));
                }
            }
        }

        return 0;
    }

    private function createPlatformRepo($forUpdate)
    {
        if ($forUpdate) {
            $platformOverrides = $this->config->get('platform') ?: array();
        } else {
            $platformOverrides = $this->locker->getPlatformOverrides();
        }

        return new PlatformRepository(array(), $platformOverrides);
    }

    /**
     * @param array $rootAliases
     * @param RepositoryInterface|null $lockedRepository
     * @return RepositorySet
     */
    private function createRepositorySet(PlatformRepository $platformRepo, array $rootAliases = array(), $lockedRepository = null)
    {
        // TODO what's the point of rootConstraints at all, we generate the package pool taking them into account anyway?
        // TODO maybe we can drop the lockedRepository here
        // TODO if this gets called in doInstall, this->update is still true?!
        if ($this->update) {
            $minimumStability = $this->package->getMinimumStability();
            $stabilityFlags = $this->package->getStabilityFlags();

            $requires = array_merge($this->package->getRequires(), $this->package->getDevRequires());
        } else {
            $minimumStability = $this->locker->getMinimumStability();
            $stabilityFlags = $this->locker->getStabilityFlags();

            $requires = array();
            foreach ($lockedRepository->getPackages() as $package) {
                $constraint = new Constraint('=', $package->getVersion());
                $constraint->setPrettyString($package->getPrettyVersion());
                $requires[$package->getName()] = $constraint;
            }
        }

        $rootRequires = array();
        foreach ($requires as $req => $constraint) {
            // skip platform requirements from the root package to avoid filtering out existing platform packages
            if ($this->ignorePlatformReqs && preg_match(PlatformRepository::PLATFORM_PACKAGE_REGEX, $req)) {
                continue;
            }
            if ($constraint instanceof Link) {
                $rootRequires[$req] = $constraint->getConstraint();
            } else {
                $rootRequires[$req] = $constraint;
            }
        }

        $this->fixedRootPackage = clone $this->package;
        $this->fixedRootPackage->setRequires(array());
        $this->fixedRootPackage->setDevRequires(array());

        $repositorySet = new RepositorySet($rootAliases, $this->package->getReferences(), $minimumStability, $stabilityFlags, $rootRequires);
        $repositorySet->addRepository(new RootPackageRepository(array($this->fixedRootPackage)));
        $repositorySet->addRepository($platformRepo);
        if ($this->additionalFixedRepository) {
            $repositorySet->addRepository($this->additionalFixedRepository);
        }

        return $repositorySet;
    }

    /**
     * @return DefaultPolicy
     */
    private function createPolicy($forUpdate)
    {
        $preferStable = null;
        $preferLowest = null;
        if (!$forUpdate) {
            $preferStable = $this->locker->getPreferStable();
            $preferLowest = $this->locker->getPreferLowest();
        }
        // old lock file without prefer stable/lowest will return null
        // so in this case we use the composer.json info
        if (null === $preferStable) {
            $preferStable = $this->preferStable || $this->package->getPreferStable();
        }
        if (null === $preferLowest) {
            $preferLowest = $this->preferLowest;
        }

        return new DefaultPolicy($preferStable, $preferLowest);
    }

    /**
     * @param RootPackageInterface $rootPackage
     * @param PlatformRepository   $platformRepo
     * @param RepositoryInterface|null $lockedRepository
     * @return Request
     */
    private function createRequest(RootPackageInterface $rootPackage, PlatformRepository $platformRepo, $lockedRepository = null)
    {
        $request = new Request($lockedRepository);

        $request->fixPackage($rootPackage, false);
        if ($rootPackage instanceof RootAliasPackage) {
            $request->fixPackage($rootPackage->getAliasOf(), false);
        }

        $fixedPackages = $platformRepo->getPackages();
        if ($this->additionalFixedRepository) {
            $fixedPackages = array_merge($fixedPackages, $this->additionalFixedRepository->getPackages());
        }

        // fix the version of all platform packages + additionally installed packages
        // to prevent the solver trying to remove or update those
        // TODO why not replaces?
        $provided = $rootPackage->getProvides();
        foreach ($fixedPackages as $package) {
            // skip platform packages that are provided by the root package
            if ($package->getRepository() !== $platformRepo
                || !isset($provided[$package->getName()])
                || !$provided[$package->getName()]->getConstraint()->matches(new Constraint('=', $package->getVersion()))
            ) {
                $request->fixPackage($package, false);
            }
        }

        return $request;
    }

    /**
     * @param bool $forUpdate
     * @return array
     */
    private function getRootAliases($forUpdate)
    {
        if ($forUpdate) {
            $aliases = $this->package->getAliases();
        } else {
            $aliases = $this->locker->getAliases();
        }

        $normalizedAliases = array();

        foreach ($aliases as $alias) {
            $normalizedAliases[$alias['package']][$alias['version']] = array(
                'alias' => $alias['alias'],
                'alias_normalized' => $alias['alias_normalized'],
            );
        }

        return $normalizedAliases;
    }

    /**
     * @param  PackageInterface $package
     * @return bool
     */
    private function isUpdateable(PackageInterface $package)
    {
        if (!$this->updateWhitelist) {
            throw new \LogicException('isUpdateable should only be called when a whitelist is present');
        }

        foreach ($this->updateWhitelist as $whiteListedPattern => $void) {
            $patternRegexp = BasePackage::packageNameToRegexp($whiteListedPattern);
            if (preg_match($patternRegexp, $package->getName())) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array $links
     * @return array
     */
    private function extractPlatformRequirements($links)
    {
        $platformReqs = array();
        foreach ($links as $link) {
            if (preg_match(PlatformRepository::PLATFORM_PACKAGE_REGEX, $link->getTarget())) {
                $platformReqs[$link->getTarget()] = $link->getPrettyConstraint();
            }
        }

        return $platformReqs;
    }

    /**
     * Adds all dependencies of the update whitelist to the whitelist, too.
     *
     * Packages which are listed as requirements in the root package will be
     * skipped including their dependencies, unless they are listed in the
     * update whitelist themselves or $whitelistAllDependencies is true.
     *
     * @param RepositoryInterface $lockRepo        Use the locked repo
     *                                             As we want the most accurate package list to work with, and installed
     *                                             repo might be empty but locked repo will always be current.
     * @param array               $rootRequires    An array of links to packages in require of the root package
     * @param array               $rootDevRequires An array of links to packages in require-dev of the root package
     */
    private function whitelistUpdateDependencies($lockRepo, array $rootRequires, array $rootDevRequires)
    {
        $rootRequires = array_merge($rootRequires, $rootDevRequires);

        $skipPackages = array();
        if (!$this->whitelistAllDependencies) {
            foreach ($rootRequires as $require) {
                $skipPackages[$require->getTarget()] = true;
            }
        }

        $repositorySet = new RepositorySet(array(), array(), 'dev');
        $repositorySet->addRepository($lockRepo);

        $seen = array();

        $rootRequiredPackageNames = array_keys($rootRequires);

        foreach ($this->updateWhitelist as $packageName => $void) {
            $packageQueue = new \SplQueue;
            $nameMatchesRequiredPackage = false;

            $depPackages = $repositorySet->findPackages($packageName, null, false);
            $matchesByPattern = array();

            // check if the name is a glob pattern that did not match directly
            if (empty($depPackages)) {
                // add any installed package matching the whitelisted name/pattern
                $whitelistPatternSearchRegexp = BasePackage::packageNameToRegexp($packageName, '^%s$');
                foreach ($lockRepo->search($whitelistPatternSearchRegexp) as $installedPackage) {
                    $matchesByPattern[] = $repositorySet->findPackages($installedPackage['name'], null, false);
                }

                // add root requirements which match the whitelisted name/pattern
                $whitelistPatternRegexp = BasePackage::packageNameToRegexp($packageName);
                foreach ($rootRequiredPackageNames as $rootRequiredPackageName) {
                    if (preg_match($whitelistPatternRegexp, $rootRequiredPackageName)) {
                        $nameMatchesRequiredPackage = true;
                        break;
                    }
                }
            }

            if (!empty($matchesByPattern)) {
                $depPackages = array_merge($depPackages, call_user_func_array('array_merge', $matchesByPattern));
            }

            if (count($depPackages) == 0 && !$nameMatchesRequiredPackage) {
                $this->io->writeError('<warning>Package "' . $packageName . '" listed for update is not installed. Ignoring.</warning>');
            }

            foreach ($depPackages as $depPackage) {
                $packageQueue->enqueue($depPackage);
            }

            while (!$packageQueue->isEmpty()) {
                $package = $packageQueue->dequeue();
                if (isset($seen[spl_object_hash($package)])) {
                    continue;
                }

                $seen[spl_object_hash($package)] = true;
                $this->updateWhitelist[$package->getName()] = true;

                if (!$this->whitelistTransitiveDependencies && !$this->whitelistAllDependencies) {
                    continue;
                }

                $requires = $package->getRequires();

                foreach ($requires as $require) {
                    $requirePackages = $repositorySet->findPackages($require->getTarget(), null, false);

                    foreach ($requirePackages as $requirePackage) {
                        if (isset($this->updateWhitelist[$requirePackage->getName()])) {
                            continue;
                        }

                        if (isset($skipPackages[$requirePackage->getName()]) && !preg_match(BasePackage::packageNameToRegexp($packageName), $requirePackage->getName())) {
                            $this->io->writeError('<warning>Dependency "' . $requirePackage->getName() . '" is also a root requirement, but is not explicitly whitelisted. Ignoring.</warning>');
                            continue;
                        }

                        $packageQueue->enqueue($requirePackage);
                    }
                }
            }
        }
    }

    /**
     * Replace local repositories with InstalledArrayRepository instances
     *
     * This is to prevent any accidental modification of the existing repos on disk
     *
     * @param RepositoryManager $rm
     */
    private function mockLocalRepositories(RepositoryManager $rm)
    {
        $packages = array();
        foreach ($rm->getLocalRepository()->getPackages() as $package) {
            $packages[(string) $package] = clone $package;
        }
        foreach ($packages as $key => $package) {
            if ($package instanceof AliasPackage) {
                $alias = (string) $package->getAliasOf();
                $packages[$key] = new AliasPackage($packages[$alias], $package->getVersion(), $package->getPrettyVersion());
            }
        }
        $rm->setLocalRepository(
            new InstalledArrayRepository($packages)
        );
    }

    /**
     * Create Installer
     *
     * @param  IOInterface $io
     * @param  Composer    $composer
     * @return Installer
     */
    public static function create(IOInterface $io, Composer $composer)
    {
        return new static(
            $io,
            $composer->getConfig(),
            $composer->getPackage(),
            $composer->getDownloadManager(),
            $composer->getRepositoryManager(),
            $composer->getLocker(),
            $composer->getInstallationManager(),
            $composer->getEventDispatcher(),
            $composer->getAutoloadGenerator()
        );
    }

    /**
     * @param  RepositoryInterface $additionalFixedRepository
     * @return $this
     */
    public function setAdditionalFixedRepository(RepositoryInterface $additionalFixedRepository)
    {
        $this->additionalFixedRepository = $additionalFixedRepository;

        return $this;
    }

    /**
     * Whether to run in drymode or not
     *
     * @param  bool      $dryRun
     * @return Installer
     */
    public function setDryRun($dryRun = true)
    {
        $this->dryRun = (bool) $dryRun;

        return $this;
    }

    /**
     * Checks, if this is a dry run (simulation mode).
     *
     * @return bool
     */
    public function isDryRun()
    {
        return $this->dryRun;
    }

    /**
     * prefer source installation
     *
     * @param  bool      $preferSource
     * @return Installer
     */
    public function setPreferSource($preferSource = true)
    {
        $this->preferSource = (bool) $preferSource;

        return $this;
    }

    /**
     * prefer dist installation
     *
     * @param  bool      $preferDist
     * @return Installer
     */
    public function setPreferDist($preferDist = true)
    {
        $this->preferDist = (bool) $preferDist;

        return $this;
    }

    /**
     * Whether or not generated autoloader are optimized
     *
     * @param  bool      $optimizeAutoloader
     * @return Installer
     */
    public function setOptimizeAutoloader($optimizeAutoloader = false)
    {
        $this->optimizeAutoloader = (bool) $optimizeAutoloader;
        if (!$this->optimizeAutoloader) {
            // Force classMapAuthoritative off when not optimizing the
            // autoloader
            $this->setClassMapAuthoritative(false);
        }

        return $this;
    }

    /**
     * Whether or not generated autoloader considers the class map
     * authoritative.
     *
     * @param  bool      $classMapAuthoritative
     * @return Installer
     */
    public function setClassMapAuthoritative($classMapAuthoritative = false)
    {
        $this->classMapAuthoritative = (bool) $classMapAuthoritative;
        if ($this->classMapAuthoritative) {
            // Force optimizeAutoloader when classmap is authoritative
            $this->setOptimizeAutoloader(true);
        }

        return $this;
    }

    /**
     * Whether or not generated autoloader considers APCu caching.
     *
     * @param  bool      $apcuAutoloader
     * @return Installer
     */
    public function setApcuAutoloader($apcuAutoloader = false)
    {
        $this->apcuAutoloader = (bool) $apcuAutoloader;

        return $this;
    }

    /**
     * update packages
     *
     * @param  bool      $update
     * @return Installer
     */
    public function setUpdate($update = true)
    {
        $this->update = (bool) $update;

        return $this;
    }

    /**
     * enables dev packages
     *
     * @param  bool      $devMode
     * @return Installer
     */
    public function setDevMode($devMode = true)
    {
        $this->devMode = (bool) $devMode;

        return $this;
    }

    /**
     * set whether to run autoloader or not
     *
     * This is disabled implicitly when enabling dryRun
     *
     * @param  bool      $dumpAutoloader
     * @return Installer
     */
    public function setDumpAutoloader($dumpAutoloader = true)
    {
        $this->dumpAutoloader = (bool) $dumpAutoloader;

        return $this;
    }

    /**
     * set whether to run scripts or not
     *
     * This is disabled implicitly when enabling dryRun
     *
     * @param  bool      $runScripts
     * @return Installer
     */
    public function setRunScripts($runScripts = true)
    {
        $this->runScripts = (bool) $runScripts;

        return $this;
    }

    /**
     * set the config instance
     *
     * @param  Config    $config
     * @return Installer
     */
    public function setConfig(Config $config)
    {
        $this->config = $config;

        return $this;
    }

    /**
     * run in verbose mode
     *
     * @param  bool      $verbose
     * @return Installer
     */
    public function setVerbose($verbose = true)
    {
        $this->verbose = (bool) $verbose;

        return $this;
    }

    /**
     * Checks, if running in verbose mode.
     *
     * @return bool
     */
    public function isVerbose()
    {
        return $this->verbose;
    }

    /**
     * set ignore Platform Package requirements
     *
     * @param  bool      $ignorePlatformReqs
     * @return Installer
     */
    public function setIgnorePlatformRequirements($ignorePlatformReqs = false)
    {
        $this->ignorePlatformReqs = (bool) $ignorePlatformReqs;

        return $this;
    }

    /**
     * Update the lock file to the exact same versions and references but use current remote metadata like URLs and mirror info
     *
     * @param  bool $updateMirrors
     * @return Installer
     */
    public function setUpdateMirrors($updateMirrors)
    {
        $this->updateMirrors = $updateMirrors;

        return $this;
    }

    /**
     * restrict the update operation to a few packages, all other packages
     * that are already installed will be kept at their current version
     *
     * @param  array     $packages
     * @return Installer
     */
    public function setUpdateWhitelist(array $packages)
    {
        $this->updateWhitelist = array_flip(array_map('strtolower', $packages));

        return $this;
    }

    /**
     * Should dependencies of whitelisted packages (but not direct dependencies) be updated?
     *
     * This will NOT whitelist any dependencies that are also directly defined
     * in the root package.
     *
     * @param  bool      $updateTransitiveDependencies
     * @return Installer
     */
    public function setWhitelistTransitiveDependencies($updateTransitiveDependencies = true)
    {
        $this->whitelistTransitiveDependencies = (bool) $updateTransitiveDependencies;

        return $this;
    }

    /**
     * Should all dependencies of whitelisted packages be updated recursively?
     *
     * This will whitelist any dependencies of the whitelisted packages, including
     * those defined in the root package.
     *
     * @param  bool      $updateAllDependencies
     * @return Installer
     */
    public function setWhitelistAllDependencies($updateAllDependencies = true)
    {
        $this->whitelistAllDependencies = (bool) $updateAllDependencies;

        return $this;
    }

    /**
     * Should packages be preferred in a stable version when updating?
     *
     * @param  bool      $preferStable
     * @return Installer
     */
    public function setPreferStable($preferStable = true)
    {
        $this->preferStable = (bool) $preferStable;

        return $this;
    }

    /**
     * Should packages be preferred in a lowest version when updating?
     *
     * @param  bool      $preferLowest
     * @return Installer
     */
    public function setPreferLowest($preferLowest = true)
    {
        $this->preferLowest = (bool) $preferLowest;

        return $this;
    }

    /**
     * Should the lock file be updated when updating?
     *
     * This is disabled implicitly when enabling dryRun
     *
     * @param  bool      $writeLock
     * @return Installer
     */
    public function setWriteLock($writeLock = true)
    {
        $this->writeLock = (bool) $writeLock;

        return $this;
    }

    /**
     * Should the operations (package install, update and removal) be executed on disk?
     *
     * This is disabled implicitly when enabling dryRun
     *
     * @param  bool      $executeOperations
     * @return Installer
     */
    public function setExecuteOperations($executeOperations = true)
    {
        $this->executeOperations = (bool) $executeOperations;

        return $this;
    }

    /**
     * Should suggestions be skipped?
     *
     * @param  bool      $skipSuggest
     * @return Installer
     */
    public function setSkipSuggest($skipSuggest = true)
    {
        $this->skipSuggest = (bool) $skipSuggest;

        return $this;
    }

    /**
     * Disables plugins.
     *
     * Call this if you want to ensure that third-party code never gets
     * executed. The default is to automatically install, and execute
     * custom third-party installers.
     *
     * @return Installer
     */
    public function disablePlugins()
    {
        $this->installationManager->disablePlugins();

        return $this;
    }

    /**
     * @param  SuggestedPackagesReporter $suggestedPackagesReporter
     * @return Installer
     */
    public function setSuggestedPackagesReporter(SuggestedPackagesReporter $suggestedPackagesReporter)
    {
        $this->suggestedPackagesReporter = $suggestedPackagesReporter;

        return $this;
    }
}
