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
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\DependencyResolver\Operation\UninstallOperation;
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
use Composer\IO\IOInterface;
use Composer\Json\JsonFile;
use Composer\Package\AliasPackage;
use Composer\Package\CompletePackage;
use Composer\Package\Link;
use Composer\Semver\Constraint\Constraint;
use Composer\Package\Locker;
use Composer\Package\PackageInterface;
use Composer\Package\RootPackageInterface;
use Composer\Repository\CompositeRepository;
use Composer\Repository\InstalledArrayRepository;
use Composer\Repository\InstalledFilesystemRepository;
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
    protected $devMode = false;
    protected $dryRun = false;
    protected $verbose = false;
    protected $update = false;
    protected $dumpAutoloader = true;
    protected $runScripts = true;
    protected $ignorePlatformReqs = false;
    protected $preferStable = false;
    protected $preferLowest = false;
    /**
     * Array of package names/globs flagged for update
     *
     * @var array|null
     */
    protected $updateWhitelist = null;
    protected $whitelistDependencies = false;

    /**
     * @var array
     */
    protected $suggestedPackages;

    /**
     * @var RepositoryInterface
     */
    protected $additionalInstalledRepository;

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
    }

    /**
     * Run installation (or update)
     *
     * @throws \Exception
     * @return int        0 on success or a positive error code on failure
     */
    public function run()
    {
        gc_collect_cycles();
        gc_disable();

        if ($this->dryRun) {
            $this->verbose = true;
            $this->runScripts = false;
            $this->installationManager->addInstaller(new NoopInstaller);
            $this->mockLocalRepositories($this->repositoryManager);
        }

        // TODO remove this BC feature at some point
        // purge old require-dev packages to avoid conflicts with the new way of handling dev requirements
        $devRepo = new InstalledFilesystemRepository(new JsonFile($this->config->get('vendor-dir').'/composer/installed_dev.json'));
        if ($devRepo->getPackages()) {
            $this->io->writeError('<warning>BC Notice: Removing old dev packages to migrate to the new require-dev handling.</warning>');
            foreach ($devRepo->getPackages() as $package) {
                if ($this->installationManager->isPackageInstalled($devRepo, $package)) {
                    $this->installationManager->uninstall($devRepo, new UninstallOperation($package));
                }
            }
            unlink($this->config->get('vendor-dir').'/composer/installed_dev.json');
        }
        unset($devRepo, $package);
        // end BC

        if ($this->runScripts) {
            // dispatch pre event
            $eventName = $this->update ? ScriptEvents::PRE_UPDATE_CMD : ScriptEvents::PRE_INSTALL_CMD;
            $this->eventDispatcher->dispatchScript($eventName, $this->devMode);
        }

        $this->downloadManager->setPreferSource($this->preferSource);
        $this->downloadManager->setPreferDist($this->preferDist);

        // clone root package to have one in the installed repo that does not require anything
        // we don't want it to be uninstallable, but its requirements should not conflict
        // with the lock file for example
        $installedRootPackage = clone $this->package;
        $installedRootPackage->setRequires(array());
        $installedRootPackage->setDevRequires(array());

        // create installed repo, this contains all local packages + platform packages (php & extensions)
        $localRepo = $this->repositoryManager->getLocalRepository();
        if (!$this->update && $this->locker->isLocked()) {
            $platformOverrides = $this->locker->getPlatformOverrides();
        } else {
            $platformOverrides = $this->config->get('platform') ?: array();
        }
        $platformRepo = new PlatformRepository(array(), $platformOverrides);
        $repos = array(
            $localRepo,
            new InstalledArrayRepository(array($installedRootPackage)),
            $platformRepo,
        );
        $installedRepo = new CompositeRepository($repos);
        if ($this->additionalInstalledRepository) {
            $installedRepo->addRepository($this->additionalInstalledRepository);
        }

        $aliases = $this->getRootAliases();
        $this->aliasPlatformPackages($platformRepo, $aliases);

        try {
            $this->suggestedPackages = array();
            $res = $this->doInstall($localRepo, $installedRepo, $platformRepo, $aliases, $this->devMode);
            if ($res !== 0) {
                return $res;
            }
        } catch (\Exception $e) {
            if (!$this->dryRun) {
                $this->installationManager->notifyInstalls($this->io);
            }

            throw $e;
        }
        if (!$this->dryRun) {
            $this->installationManager->notifyInstalls($this->io);
        }

        // output suggestions if we're in dev mode
        if ($this->devMode) {
            foreach ($this->suggestedPackages as $suggestion) {
                $target = $suggestion['target'];
                foreach ($installedRepo->getPackages() as $package) {
                    if (in_array($target, $package->getNames())) {
                        continue 2;
                    }
                }

                $this->io->writeError($suggestion['source'].' suggests installing '.$suggestion['target'].' ('.$suggestion['reason'].')');
            }
        }

        # Find abandoned packages and warn user
        foreach ($localRepo->getPackages() as $package) {
            if (!$package instanceof CompletePackage || !$package->isAbandoned()) {
                continue;
            }

            $replacement = (is_string($package->getReplacementPackage()))
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

        if (!$this->dryRun) {
            // write lock
            if ($this->update || !$this->locker->isLocked()) {
                $localRepo->reload();

                // if this is not run in dev mode and the root has dev requires, the lock must
                // contain null to prevent dev installs from a non-dev lock
                $devPackages = ($this->devMode || !$this->package->getDevRequires()) ? array() : null;

                // split dev and non-dev requirements by checking what would be removed if we update without the dev requirements
                if ($this->devMode && $this->package->getDevRequires()) {
                    $policy = $this->createPolicy();
                    $pool = $this->createPool(true);
                    $pool->addRepository($installedRepo, $aliases);

                    // creating requirements request
                    $request = $this->createRequest($this->package, $platformRepo);
                    $request->updateAll();
                    foreach ($this->package->getRequires() as $link) {
                        $request->install($link->getTarget(), $link->getConstraint());
                    }

                    $this->eventDispatcher->dispatchInstallerEvent(InstallerEvents::PRE_DEPENDENCIES_SOLVING, false, $policy, $pool, $installedRepo, $request);
                    $solver = new Solver($policy, $pool, $installedRepo);
                    $ops = $solver->solve($request, $this->ignorePlatformReqs);
                    $this->eventDispatcher->dispatchInstallerEvent(InstallerEvents::POST_DEPENDENCIES_SOLVING, false, $policy, $pool, $installedRepo, $request, $ops);
                    foreach ($ops as $op) {
                        if ($op->getJobType() === 'uninstall') {
                            $devPackages[] = $op->getPackage();
                        }
                    }
                }

                $platformReqs = $this->extractPlatformRequirements($this->package->getRequires());
                $platformDevReqs = $this->devMode ? $this->extractPlatformRequirements($this->package->getDevRequires()) : array();

                $updatedLock = $this->locker->setLockData(
                    array_diff($localRepo->getCanonicalPackages(), (array) $devPackages),
                    $devPackages,
                    $platformReqs,
                    $platformDevReqs,
                    $aliases,
                    $this->package->getMinimumStability(),
                    $this->package->getStabilityFlags(),
                    $this->preferStable || $this->package->getPreferStable(),
                    $this->preferLowest,
                    $this->config->get('platform') ?: array()
                );
                if ($updatedLock) {
                    $this->io->writeError('<info>Writing lock file</info>');
                }
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
                $this->autoloadGenerator->setRunScripts($this->runScripts);
                $this->autoloadGenerator->dump($this->config, $localRepo, $this->package, $this->installationManager, 'composer', $this->optimizeAutoloader);
            }

            if ($this->runScripts) {
                // dispatch post event
                $eventName = $this->update ? ScriptEvents::POST_UPDATE_CMD : ScriptEvents::POST_INSTALL_CMD;
                $this->eventDispatcher->dispatchScript($eventName, $this->devMode);
            }

            $vendorDir = $this->config->get('vendor-dir');
            if (is_dir($vendorDir)) {
                // suppress errors as this fails sometimes on OSX for no apparent reason
                // see https://github.com/composer/composer/issues/4070#issuecomment-129792748
                @touch($vendorDir);
            }
        }

        return 0;
    }

    /**
     * @param  RepositoryInterface $localRepo
     * @param  RepositoryInterface $installedRepo
     * @param  PlatformRepository  $platformRepo
     * @param  array               $aliases
     * @param  bool                $withDevReqs
     * @return int
     */
    protected function doInstall($localRepo, $installedRepo, $platformRepo, $aliases, $withDevReqs)
    {
        // init vars
        $lockedRepository = null;
        $repositories = null;

        // initialize locker to create aliased packages
        $installFromLock = !$this->update && $this->locker->isLocked();

        // initialize locked repo if we are installing from lock or in a partial update
        // and a lock file is present as we need to force install non-whitelisted lock file
        // packages in that case
        if ($installFromLock || (!empty($this->updateWhitelist) && $this->locker->isLocked())) {
            try {
                $lockedRepository = $this->locker->getLockedRepository($withDevReqs);
            } catch (\RuntimeException $e) {
                // if there are dev requires, then we really can not install
                if ($this->package->getDevRequires()) {
                    throw $e;
                }
                // no require-dev in composer.json and the lock file was created with no dev info, so skip them
                $lockedRepository = $this->locker->getLockedRepository();
            }
        }

        $this->whitelistUpdateDependencies(
            $localRepo,
            $withDevReqs,
            $this->package->getRequires(),
            $this->package->getDevRequires()
        );

        $this->io->writeError('<info>Loading composer repositories with package information</info>');

        // creating repository pool
        $policy = $this->createPolicy();
        $pool = $this->createPool($withDevReqs, $installFromLock ? $lockedRepository : null);
        $pool->addRepository($installedRepo, $aliases);
        if (!$installFromLock) {
            $repositories = $this->repositoryManager->getRepositories();
            foreach ($repositories as $repository) {
                $pool->addRepository($repository, $aliases);
            }
        }
        // Add the locked repository after the others in case we are doing a
        // partial update so missing packages can be found there still.
        // For installs from lock it's the only one added so it is first
        if ($lockedRepository) {
            $pool->addRepository($lockedRepository, $aliases);
        }

        // creating requirements request
        $request = $this->createRequest($this->package, $platformRepo);

        if (!$installFromLock) {
            // remove unstable packages from the localRepo if they don't match the current stability settings
            $removedUnstablePackages = array();
            foreach ($localRepo->getPackages() as $package) {
                if (
                    !$pool->isPackageAcceptable($package->getNames(), $package->getStability())
                    && $this->installationManager->isPackageInstalled($localRepo, $package)
                ) {
                    $removedUnstablePackages[$package->getName()] = true;
                    $request->remove($package->getName(), new Constraint('=', $package->getVersion()));
                }
            }
        }

        if ($this->update) {
            $this->io->writeError('<info>Updating dependencies'.($withDevReqs ? ' (including require-dev)' : '').'</info>');

            $request->updateAll();

            if ($withDevReqs) {
                $links = array_merge($this->package->getRequires(), $this->package->getDevRequires());
            } else {
                $links = $this->package->getRequires();
            }

            foreach ($links as $link) {
                $request->install($link->getTarget(), $link->getConstraint());
            }

            // if the updateWhitelist is enabled, packages not in it are also fixed
            // to the version specified in the lock, or their currently installed version
            if ($this->updateWhitelist) {
                $currentPackages = $this->getCurrentPackages($withDevReqs, $installedRepo);

                // collect packages to fixate from root requirements as well as installed packages
                $candidates = array();
                foreach ($links as $link) {
                    $candidates[$link->getTarget()] = true;
                }
                foreach ($localRepo->getPackages() as $package) {
                    $candidates[$package->getName()] = true;
                }

                // fix them to the version in lock (or currently installed) if they are not updateable
                foreach ($candidates as $candidate => $dummy) {
                    foreach ($currentPackages as $curPackage) {
                        if ($curPackage->getName() === $candidate) {
                            if (!$this->isUpdateable($curPackage) && !isset($removedUnstablePackages[$curPackage->getName()])) {
                                $constraint = new Constraint('=', $curPackage->getVersion());
                                $request->install($curPackage->getName(), $constraint);
                            }
                            break;
                        }
                    }
                }
            }
        } elseif ($installFromLock) {
            $this->io->writeError('<info>Installing dependencies'.($withDevReqs ? ' (including require-dev)' : '').' from lock file</info>');

            if (!$this->locker->isFresh()) {
                $this->io->writeError('<warning>Warning: The lock file is not up to date with the latest changes in composer.json. You may be getting outdated dependencies. Run update to update them.</warning>');
            }

            foreach ($lockedRepository->getPackages() as $package) {
                $version = $package->getVersion();
                if (isset($aliases[$package->getName()][$version])) {
                    $version = $aliases[$package->getName()][$version]['alias_normalized'];
                }
                $constraint = new Constraint('=', $version);
                $constraint->setPrettyString($package->getPrettyVersion());
                $request->install($package->getName(), $constraint);
            }

            foreach ($this->locker->getPlatformRequirements($withDevReqs) as $link) {
                $request->install($link->getTarget(), $link->getConstraint());
            }
        } else {
            $this->io->writeError('<info>Installing dependencies'.($withDevReqs ? ' (including require-dev)' : '').'</info>');

            if ($withDevReqs) {
                $links = array_merge($this->package->getRequires(), $this->package->getDevRequires());
            } else {
                $links = $this->package->getRequires();
            }

            foreach ($links as $link) {
                $request->install($link->getTarget(), $link->getConstraint());
            }
        }

        // force dev packages to have the latest links if we update or install from a (potentially new) lock
        $this->processDevPackages($localRepo, $pool, $policy, $repositories, $installedRepo, $lockedRepository, $installFromLock, $withDevReqs, 'force-links');

        // solve dependencies
        $this->eventDispatcher->dispatchInstallerEvent(InstallerEvents::PRE_DEPENDENCIES_SOLVING, $this->devMode, $policy, $pool, $installedRepo, $request);
        $solver = new Solver($policy, $pool, $installedRepo);
        try {
            $operations = $solver->solve($request, $this->ignorePlatformReqs);
            $this->eventDispatcher->dispatchInstallerEvent(InstallerEvents::POST_DEPENDENCIES_SOLVING, $this->devMode, $policy, $pool, $installedRepo, $request, $operations);
        } catch (SolverProblemsException $e) {
            $this->io->writeError('<error>Your requirements could not be resolved to an installable set of packages.</error>');
            $this->io->writeError($e->getMessage());

            return max(1, $e->getCode());
        }

        if ($this->io->isVerbose()) {
            $this->io->writeError("Analyzed ".count($pool)." packages to resolve dependencies");
            $this->io->writeError("Analyzed ".$solver->getRuleSetSize()." rules to resolve dependencies");
        }

        // force dev packages to be updated if we update or install from a (potentially new) lock
        $operations = $this->processDevPackages($localRepo, $pool, $policy, $repositories, $installedRepo, $lockedRepository, $installFromLock, $withDevReqs, 'force-updates', $operations);

        // execute operations
        if (!$operations) {
            $this->io->writeError('Nothing to install or update');
        }

        $operations = $this->movePluginsToFront($operations);
        $operations = $this->moveUninstallsToFront($operations);

        foreach ($operations as $operation) {
            // collect suggestions
            if ('install' === $operation->getJobType()) {
                foreach ($operation->getPackage()->getSuggests() as $target => $reason) {
                    $this->suggestedPackages[] = array(
                        'source' => $operation->getPackage()->getPrettyName(),
                        'target' => $target,
                        'reason' => $reason,
                    );
                }
            }

            // not installing from lock, force dev packages' references if they're in root package refs
            if (!$installFromLock) {
                $package = null;
                if ('update' === $operation->getJobType()) {
                    $package = $operation->getTargetPackage();
                } elseif ('install' === $operation->getJobType()) {
                    $package = $operation->getPackage();
                }
                if ($package && $package->isDev()) {
                    $references = $this->package->getReferences();
                    if (isset($references[$package->getName()])) {
                        $package->setSourceReference($references[$package->getName()]);
                        $package->setDistReference($references[$package->getName()]);
                    }
                }
                if ('update' === $operation->getJobType()
                    && $operation->getTargetPackage()->isDev()
                    && $operation->getTargetPackage()->getVersion() === $operation->getInitialPackage()->getVersion()
                    && (!$operation->getTargetPackage()->getSourceReference() || $operation->getTargetPackage()->getSourceReference() === $operation->getInitialPackage()->getSourceReference())
                    && (!$operation->getTargetPackage()->getDistReference() || $operation->getTargetPackage()->getDistReference() === $operation->getInitialPackage()->getDistReference())
                ) {
                    if ($this->io->isDebug()) {
                        $this->io->writeError('  - Skipping update of '. $operation->getTargetPackage()->getPrettyName().' to the same reference-locked version');
                        $this->io->writeError('');
                    }

                    continue;
                }
            }

            $event = 'Composer\Installer\PackageEvents::PRE_PACKAGE_'.strtoupper($operation->getJobType());
            if (defined($event) && $this->runScripts) {
                $this->eventDispatcher->dispatchPackageEvent(constant($event), $this->devMode, $policy, $pool, $installedRepo, $request, $operations, $operation);
            }

            // output non-alias ops in dry run, output alias ops in debug verbosity
            if ($this->dryRun && false === strpos($operation->getJobType(), 'Alias')) {
                $this->io->writeError('  - ' . $operation);
                $this->io->writeError('');
            } elseif ($this->io->isDebug() && false !== strpos($operation->getJobType(), 'Alias')) {
                $this->io->writeError('  - ' . $operation);
                $this->io->writeError('');
            }

            $this->installationManager->execute($localRepo, $operation);

            // output reasons why the operation was ran, only for install/update operations
            if ($this->verbose && $this->io->isVeryVerbose() && in_array($operation->getJobType(), array('install', 'update'))) {
                $reason = $operation->getReason();
                if ($reason instanceof Rule) {
                    switch ($reason->getReason()) {
                        case Rule::RULE_JOB_INSTALL:
                            $this->io->writeError('    REASON: Required by root: '.$reason->getPrettyString($pool));
                            $this->io->writeError('');
                            break;
                        case Rule::RULE_PACKAGE_REQUIRES:
                            $this->io->writeError('    REASON: '.$reason->getPrettyString($pool));
                            $this->io->writeError('');
                            break;
                    }
                }
            }

            $event = 'Composer\Installer\PackageEvents::POST_PACKAGE_'.strtoupper($operation->getJobType());
            if (defined($event) && $this->runScripts) {
                $this->eventDispatcher->dispatchPackageEvent(constant($event), $this->devMode, $policy, $pool, $installedRepo, $request, $operations, $operation);
            }

            if (!$this->dryRun) {
                $localRepo->write();
            }
        }

        if (!$this->dryRun) {
            // force source/dist urls to be updated for all packages
            $this->processPackageUrls($pool, $policy, $localRepo, $repositories);
            $localRepo->write();
        }

        return 0;
    }

    /**
     * Workaround: if your packages depend on plugins, we must be sure
     * that those are installed / updated first; else it would lead to packages
     * being installed multiple times in different folders, when running Composer
     * twice.
     *
     * While this does not fix the root-causes of https://github.com/composer/composer/issues/1147,
     * it at least fixes the symptoms and makes usage of composer possible (again)
     * in such scenarios.
     *
     * @param  OperationInterface[] $operations
     * @return OperationInterface[] reordered operation list
     */
    private function movePluginsToFront(array $operations)
    {
        $installerOps = array();
        foreach ($operations as $idx => $op) {
            if ($op instanceof InstallOperation) {
                $package = $op->getPackage();
            } elseif ($op instanceof UpdateOperation) {
                $package = $op->getTargetPackage();
            } else {
                continue;
            }

            if ($package->getType() === 'composer-plugin' || $package->getType() === 'composer-installer') {
                // ignore requirements to platform or composer-plugin-api
                $requires = array_keys($package->getRequires());
                foreach ($requires as $index => $req) {
                    if ($req === 'composer-plugin-api' || preg_match(PlatformRepository::PLATFORM_PACKAGE_REGEX, $req)) {
                        unset($requires[$index]);
                    }
                }
                // if there are no other requirements, move the plugin to the top of the op list
                if (!count($requires)) {
                    $installerOps[] = $op;
                    unset($operations[$idx]);
                }
            }
        }

        return array_merge($installerOps, $operations);
    }

    /**
     * Removals of packages should be executed before installations in
     * case two packages resolve to the same path (due to custom installers)
     *
     * @param  OperationInterface[] $operations
     * @return OperationInterface[] reordered operation list
     */
    private function moveUninstallsToFront(array $operations)
    {
        $uninstOps = array();
        foreach ($operations as $idx => $op) {
            if ($op instanceof UninstallOperation) {
                $uninstOps[] = $op;
                unset($operations[$idx]);
            }
        }

        return array_merge($uninstOps, $operations);
    }

    /**
     * @param  bool                     $withDevReqs
     * @param  RepositoryInterface|null $lockedRepository
     * @return Pool
     */
    private function createPool($withDevReqs, RepositoryInterface $lockedRepository = null)
    {
        if (!$this->update && $this->locker->isLocked()) { // install from lock
            $minimumStability = $this->locker->getMinimumStability();
            $stabilityFlags = $this->locker->getStabilityFlags();

            $requires = array();
            foreach ($lockedRepository->getPackages() as $package) {
                $constraint = new Constraint('=', $package->getVersion());
                $constraint->setPrettyString($package->getPrettyVersion());
                $requires[$package->getName()] = $constraint;
            }
        } else {
            $minimumStability = $this->package->getMinimumStability();
            $stabilityFlags = $this->package->getStabilityFlags();

            $requires = $this->package->getRequires();
            if ($withDevReqs) {
                $requires = array_merge($requires, $this->package->getDevRequires());
            }
        }

        $rootConstraints = array();
        foreach ($requires as $req => $constraint) {
            // skip platform requirements from the root package to avoid filtering out existing platform packages
            if ($this->ignorePlatformReqs && preg_match(PlatformRepository::PLATFORM_PACKAGE_REGEX, $req)) {
                continue;
            }
            if ($constraint instanceof Link) {
                $rootConstraints[$req] = $constraint->getConstraint();
            } else {
                $rootConstraints[$req] = $constraint;
            }
        }

        return new Pool($minimumStability, $stabilityFlags, $rootConstraints);
    }

    /**
     * @return DefaultPolicy
     */
    private function createPolicy()
    {
        $preferStable = null;
        $preferLowest = null;
        if (!$this->update && $this->locker->isLocked()) {
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
     * @param  RootPackageInterface $rootPackage
     * @param  PlatformRepository   $platformRepo
     * @return Request
     */
    private function createRequest(RootPackageInterface $rootPackage, PlatformRepository $platformRepo)
    {
        $request = new Request();

        $constraint = new Constraint('=', $rootPackage->getVersion());
        $constraint->setPrettyString($rootPackage->getPrettyVersion());
        $request->install($rootPackage->getName(), $constraint);

        $fixedPackages = $platformRepo->getPackages();
        if ($this->additionalInstalledRepository) {
            $additionalFixedPackages = $this->additionalInstalledRepository->getPackages();
            $fixedPackages = array_merge($fixedPackages, $additionalFixedPackages);
        }

        // fix the version of all platform packages + additionally installed packages
        // to prevent the solver trying to remove or update those
        $provided = $rootPackage->getProvides();
        foreach ($fixedPackages as $package) {
            $constraint = new Constraint('=', $package->getVersion());
            $constraint->setPrettyString($package->getPrettyVersion());

            // skip platform packages that are provided by the root package
            if ($package->getRepository() !== $platformRepo
                || !isset($provided[$package->getName()])
                || !$provided[$package->getName()]->getConstraint()->matches($constraint)
            ) {
                $request->fix($package->getName(), $constraint);
            }
        }

        return $request;
    }

    /**
     * @param  WritableRepositoryInterface $localRepo
     * @param  Pool                        $pool
     * @param  PolicyInterface             $policy
     * @param  array                       $repositories
     * @param  RepositoryInterface         $installedRepo
     * @param  RepositoryInterface         $lockedRepository
     * @param  bool                        $installFromLock
     * @param  bool                        $withDevReqs
     * @param  string                      $task
     * @param  array|null                  $operations
     * @return array
     */
    private function processDevPackages($localRepo, $pool, $policy, $repositories, $installedRepo, $lockedRepository, $installFromLock, $withDevReqs, $task, array $operations = null)
    {
        if ($task === 'force-updates' && null === $operations) {
            throw new \InvalidArgumentException('Missing operations argument');
        }
        if ($task === 'force-links') {
            $operations = array();
        }

        if (!$installFromLock && $this->updateWhitelist) {
            $currentPackages = $this->getCurrentPackages($withDevReqs, $installedRepo);
        }

        foreach ($localRepo->getCanonicalPackages() as $package) {
            // skip non-dev packages
            if (!$package->isDev()) {
                continue;
            }

            // skip packages that will be updated/uninstalled
            foreach ($operations as $operation) {
                if (('update' === $operation->getJobType() && $operation->getInitialPackage()->equals($package))
                    || ('uninstall' === $operation->getJobType() && $operation->getPackage()->equals($package))
                ) {
                    continue 2;
                }
            }

            // force update to locked version if it does not match the installed version
            if ($installFromLock) {
                foreach ($lockedRepository->findPackages($package->getName()) as $lockedPackage) {
                    if ($lockedPackage->isDev() && $lockedPackage->getVersion() === $package->getVersion()) {
                        if ($task === 'force-links') {
                            $package->setRequires($lockedPackage->getRequires());
                            $package->setConflicts($lockedPackage->getConflicts());
                            $package->setProvides($lockedPackage->getProvides());
                            $package->setReplaces($lockedPackage->getReplaces());
                        } elseif ($task === 'force-updates') {
                            if (($lockedPackage->getSourceReference() && $lockedPackage->getSourceReference() !== $package->getSourceReference())
                                || ($lockedPackage->getDistReference() && $lockedPackage->getDistReference() !== $package->getDistReference())
                            ) {
                                $operations[] = new UpdateOperation($package, $lockedPackage);
                            }
                        }

                        break;
                    }
                }
            } else {
                // force update to latest on update
                if ($this->update) {
                    // skip package if the whitelist is enabled and it is not in it
                    if ($this->updateWhitelist && !$this->isUpdateable($package)) {
                        // check if non-updateable packages are out of date compared to the lock file to ensure we don't corrupt it
                        foreach ($currentPackages as $curPackage) {
                            if ($curPackage->isDev() && $curPackage->getName() === $package->getName() && $curPackage->getVersion() === $package->getVersion()) {
                                if ($task === 'force-links') {
                                    $package->setRequires($curPackage->getRequires());
                                    $package->setConflicts($curPackage->getConflicts());
                                    $package->setProvides($curPackage->getProvides());
                                    $package->setReplaces($curPackage->getReplaces());
                                } elseif ($task === 'force-updates') {
                                    if (($curPackage->getSourceReference() && $curPackage->getSourceReference() !== $package->getSourceReference())
                                        || ($curPackage->getDistReference() && $curPackage->getDistReference() !== $package->getDistReference())
                                    ) {
                                        $operations[] = new UpdateOperation($package, $curPackage);
                                    }
                                }

                                break;
                            }
                        }

                        continue;
                    }

                    // find similar packages (name/version) in all repositories
                    $matches = $pool->whatProvides($package->getName(), new Constraint('=', $package->getVersion()));
                    foreach ($matches as $index => $match) {
                        // skip local packages
                        if (!in_array($match->getRepository(), $repositories, true)) {
                            unset($matches[$index]);
                            continue;
                        }

                        // skip providers/replacers
                        if ($match->getName() !== $package->getName()) {
                            unset($matches[$index]);
                            continue;
                        }

                        $matches[$index] = $match->getId();
                    }

                    // select preferred package according to policy rules
                    if ($matches && $matches = $policy->selectPreferredPackages($pool, array(), $matches)) {
                        $newPackage = $pool->literalToPackage($matches[0]);

                        if ($task === 'force-links' && $newPackage) {
                            $package->setRequires($newPackage->getRequires());
                            $package->setConflicts($newPackage->getConflicts());
                            $package->setProvides($newPackage->getProvides());
                            $package->setReplaces($newPackage->getReplaces());
                        }

                        if ($task === 'force-updates' && $newPackage && (
                            (($newPackage->getSourceReference() && $newPackage->getSourceReference() !== $package->getSourceReference())
                                || ($newPackage->getDistReference() && $newPackage->getDistReference() !== $package->getDistReference())
                            )
                        )) {
                            $operations[] = new UpdateOperation($package, $newPackage);
                        }
                    }
                }

                if ($task === 'force-updates') {
                    // force installed package to update to referenced version in root package if it does not match the installed version
                    $references = $this->package->getReferences();

                    if (isset($references[$package->getName()]) && $references[$package->getName()] !== $package->getSourceReference()) {
                        // changing the source ref to update to will be handled in the operations loop below
                        $operations[] = new UpdateOperation($package, clone $package);
                    }
                }
            }
        }

        return $operations;
    }

    /**
     * Loads the most "current" list of packages that are installed meaning from lock ideally or from installed repo as fallback
     * @param  bool                $withDevReqs
     * @param  RepositoryInterface $installedRepo
     * @return array
     */
    private function getCurrentPackages($withDevReqs, $installedRepo)
    {
        if ($this->locker->isLocked()) {
            try {
                return $this->locker->getLockedRepository($withDevReqs)->getPackages();
            } catch (\RuntimeException $e) {
                // fetch only non-dev packages from lock if doing a dev update fails due to a previously incomplete lock file
                return $this->locker->getLockedRepository()->getPackages();
            }
        }

        return $installedRepo->getPackages();
    }

    /**
     * @return array
     */
    private function getRootAliases()
    {
        if (!$this->update && $this->locker->isLocked()) {
            $aliases = $this->locker->getAliases();
        } else {
            $aliases = $this->package->getAliases();
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
     * @param Pool                        $pool
     * @param PolicyInterface             $policy
     * @param WritableRepositoryInterface $localRepo
     * @param array                       $repositories
     */
    private function processPackageUrls($pool, $policy, $localRepo, $repositories)
    {
        if (!$this->update) {
            return;
        }

        foreach ($localRepo->getCanonicalPackages() as $package) {
            // find similar packages (name/version) in all repositories
            $matches = $pool->whatProvides($package->getName(), new Constraint('=', $package->getVersion()));
            foreach ($matches as $index => $match) {
                // skip local packages
                if (!in_array($match->getRepository(), $repositories, true)) {
                    unset($matches[$index]);
                    continue;
                }

                // skip providers/replacers
                if ($match->getName() !== $package->getName()) {
                    unset($matches[$index]);
                    continue;
                }

                $matches[$index] = $match->getId();
            }

            // select preferred package according to policy rules
            if ($matches && $matches = $policy->selectPreferredPackages($pool, array(), $matches)) {
                $newPackage = $pool->literalToPackage($matches[0]);

                // update the dist and source URLs
                $sourceUrl = $package->getSourceUrl();
                $newSourceUrl = $newPackage->getSourceUrl();

                if ($sourceUrl !== $newSourceUrl) {
                    $package->setSourceType($newPackage->getSourceType());
                    $package->setSourceUrl($newSourceUrl);
                    $package->setSourceReference($newPackage->getSourceReference());
                }

                // only update dist url for github/bitbucket dists as they use a combination of dist url + dist reference to install
                // but for other urls this is ambiguous and could result in bad outcomes
                if (preg_match('{^https?://(?:(?:www\.)?bitbucket\.org|(api\.)?github\.com)/}', $newPackage->getDistUrl())) {
                    $package->setDistUrl($newPackage->getDistUrl());
                }
            }
        }
    }

    /**
     * @param PlatformRepository $platformRepo
     * @param array              $aliases
     */
    private function aliasPlatformPackages(PlatformRepository $platformRepo, $aliases)
    {
        foreach ($aliases as $package => $versions) {
            foreach ($versions as $version => $alias) {
                $packages = $platformRepo->findPackages($package, $version);
                foreach ($packages as $package) {
                    $aliasPackage = new AliasPackage($package, $alias['alias_normalized'], $alias['alias']);
                    $aliasPackage->setRootPackageAlias(true);
                    $platformRepo->addPackage($aliasPackage);
                }
            }
        }
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
            $patternRegexp = $this->packageNameToRegexp($whiteListedPattern);
            if (preg_match($patternRegexp, $package->getName())) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build a regexp from a package name, expanding * globs as required
     *
     * @param  string $whiteListedPattern
     * @return string
     */
    private function packageNameToRegexp($whiteListedPattern)
    {
        $cleanedWhiteListedPattern = str_replace('\\*', '.*', preg_quote($whiteListedPattern));

        return "{^" . $cleanedWhiteListedPattern . "$}i";
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
     * update whitelist themselves.
     *
     * @param RepositoryInterface $localRepo
     * @param bool                $devMode
     * @param array               $rootRequires    An array of links to packages in require of the root package
     * @param array               $rootDevRequires An array of links to packages in require-dev of the root package
     */
    private function whitelistUpdateDependencies($localRepo, $devMode, array $rootRequires, array $rootDevRequires)
    {
        if (!$this->updateWhitelist) {
            return;
        }

        $requiredPackageNames = array();
        foreach (array_merge($rootRequires, $rootDevRequires) as $require) {
            $requiredPackageNames[] = $require->getTarget();
        }

        if ($devMode) {
            $rootRequires = array_merge($rootRequires, $rootDevRequires);
        }

        $skipPackages = array();
        foreach ($rootRequires as $require) {
            $skipPackages[$require->getTarget()] = true;
        }

        $pool = new Pool;
        $pool->addRepository($localRepo);

        $seen = array();

        $rootRequiredPackageNames = array_keys($rootRequires);

        foreach ($this->updateWhitelist as $packageName => $void) {
            $packageQueue = new \SplQueue;

            $depPackages = $pool->whatProvides($packageName);

            $nameMatchesRequiredPackage = in_array($packageName, $requiredPackageNames, true);

            // check if the name is a glob pattern that did not match directly
            if (!$nameMatchesRequiredPackage) {
                $whitelistPatternRegexp = $this->packageNameToRegexp($packageName);
                foreach ($rootRequiredPackageNames as $rootRequiredPackageName) {
                    if (preg_match($whitelistPatternRegexp, $rootRequiredPackageName)) {
                        $nameMatchesRequiredPackage = true;
                        break;
                    }
                }
            }

            if (count($depPackages) == 0 && !$nameMatchesRequiredPackage && !in_array($packageName, array('nothing', 'lock'))) {
                $this->io->writeError('<warning>Package "' . $packageName . '" listed for update is not installed. Ignoring.</warning>');
            }

            foreach ($depPackages as $depPackage) {
                $packageQueue->enqueue($depPackage);
            }

            while (!$packageQueue->isEmpty()) {
                $package = $packageQueue->dequeue();
                if (isset($seen[$package->getId()])) {
                    continue;
                }

                $seen[$package->getId()] = true;
                $this->updateWhitelist[$package->getName()] = true;

                if (!$this->whitelistDependencies) {
                    continue;
                }

                $requires = $package->getRequires();

                foreach ($requires as $require) {
                    $requirePackages = $pool->whatProvides($require->getTarget());

                    foreach ($requirePackages as $requirePackage) {
                        if (isset($skipPackages[$requirePackage->getName()])) {
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
     * @param  RepositoryInterface $additionalInstalledRepository
     * @return $this
     */
    public function setAdditionalInstalledRepository(RepositoryInterface $additionalInstalledRepository)
    {
        $this->additionalInstalledRepository = $additionalInstalledRepository;

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
        $this->dryRun = (boolean) $dryRun;

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
        $this->preferSource = (boolean) $preferSource;

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
        $this->preferDist = (boolean) $preferDist;

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
        $this->optimizeAutoloader = (boolean) $optimizeAutoloader;
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
        $this->classMapAuthoritative = (boolean) $classMapAuthoritative;
        if ($this->classMapAuthoritative) {
            // Force optimizeAutoloader when classmap is authoritative
            $this->setOptimizeAutoloader(true);
        }

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
        $this->update = (boolean) $update;

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
        $this->devMode = (boolean) $devMode;

        return $this;
    }

    /**
     * set whether to run autoloader or not
     *
     * @param  bool      $dumpAutoloader
     * @return Installer
     */
    public function setDumpAutoloader($dumpAutoloader = true)
    {
        $this->dumpAutoloader = (boolean) $dumpAutoloader;

        return $this;
    }

    /**
     * set whether to run scripts or not
     *
     * @param  bool      $runScripts
     * @return Installer
     */
    public function setRunScripts($runScripts = true)
    {
        $this->runScripts = (boolean) $runScripts;

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
        $this->verbose = (boolean) $verbose;

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
        $this->ignorePlatformReqs = (boolean) $ignorePlatformReqs;

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
     * Should dependencies of whitelisted packages be updated recursively?
     *
     * @param  bool      $updateDependencies
     * @return Installer
     */
    public function setWhitelistDependencies($updateDependencies = true)
    {
        $this->whitelistDependencies = (boolean) $updateDependencies;

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
        $this->preferStable = (boolean) $preferStable;

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
        $this->preferLowest = (boolean) $preferLowest;

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
}
