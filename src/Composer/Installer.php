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
use Composer\DependencyResolver\SolverProblemsException;
use Composer\Downloader\DownloadManager;
use Composer\Installer\InstallationManager;
use Composer\Config;
use Composer\Installer\NoopInstaller;
use Composer\IO\IOInterface;
use Composer\Package\AliasPackage;
use Composer\Package\Link;
use Composer\Package\LinkConstraint\VersionConstraint;
use Composer\Package\Locker;
use Composer\Package\PackageInterface;
use Composer\Package\RootPackageInterface;
use Composer\Repository\CompositeRepository;
use Composer\Repository\InstalledArrayRepository;
use Composer\Repository\PlatformRepository;
use Composer\Repository\RepositoryInterface;
use Composer\Repository\RepositoryManager;
use Composer\Script\EventDispatcher;
use Composer\Script\ScriptEvents;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 * @author Beau Simensen <beau@dflydev.com>
 * @author Konstantin Kudryashov <ever.zet@gmail.com>
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
    protected $devMode = false;
    protected $dryRun = false;
    protected $verbose = false;
    protected $update = false;
    protected $runScripts = true;
    protected $updateWhitelist = null;

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
     */
    public function run()
    {
        if ($this->dryRun) {
            $this->verbose = true;
            $this->runScripts = false;
            $this->installationManager->addInstaller(new NoopInstaller);
        }

        if ($this->preferSource) {
            $this->downloadManager->setPreferSource(true);
        }
        if ($this->preferDist) {
            $this->downloadManager->setPreferDist(true);
        }

        // create installed repo, this contains all local packages + platform packages (php & extensions)
        $installedRootPackage = clone $this->package;
        $installedRootPackage->setRequires(array());
        $installedRootPackage->setDevRequires(array());

        $platformRepo = new PlatformRepository();
        $repos = array_merge(
            $this->repositoryManager->getLocalRepositories(),
            array(
                new InstalledArrayRepository(array($installedRootPackage)),
                $platformRepo,
            )
        );
        $installedRepo = new CompositeRepository($repos);
        if ($this->additionalInstalledRepository) {
            $installedRepo->addRepository($this->additionalInstalledRepository);
        }

        $aliases = $this->getRootAliases();
        $this->aliasPlatformPackages($platformRepo, $aliases);

        if ($this->runScripts) {
            // dispatch pre event
            $eventName = $this->update ? ScriptEvents::PRE_UPDATE_CMD : ScriptEvents::PRE_INSTALL_CMD;
            $this->eventDispatcher->dispatchCommandEvent($eventName);
        }

        $this->suggestedPackages = array();
        if (!$this->doInstall($this->repositoryManager->getLocalRepository(), $installedRepo, $aliases)) {
            return false;
        }
        if ($this->devMode) {
            if (!$this->doInstall($this->repositoryManager->getLocalDevRepository(), $installedRepo, $aliases, true)) {
                return false;
            }
        }

        // output suggestions
        foreach ($this->suggestedPackages as $suggestion) {
            $target = $suggestion['target'];
            if ($installedRepo->filterPackages(function (PackageInterface $package) use ($target) {
                if (in_array($target, $package->getNames())) {
                    return false;
                }
            })) {
                $this->io->write($suggestion['source'].' suggests installing '.$suggestion['target'].' ('.$suggestion['reason'].')');
            }
        }

        if (!$this->dryRun) {
            // write lock
            if ($this->update || !$this->locker->isLocked()) {
                $updatedLock = $this->locker->setLockData(
                    $this->repositoryManager->getLocalRepository()->getPackages(),
                    $this->devMode ? $this->repositoryManager->getLocalDevRepository()->getPackages() : null,
                    $aliases,
                    $this->package->getMinimumStability(),
                    $this->package->getStabilityFlags()
                );
                if ($updatedLock) {
                    $this->io->write('<info>Writing lock file</info>');
                }
            }

            // write autoloader
            $this->io->write('<info>Generating autoload files</info>');
            $localRepos = new CompositeRepository($this->repositoryManager->getLocalRepositories());
            $this->autoloadGenerator->dump($this->config, $localRepos, $this->package, $this->installationManager, 'composer');

            if ($this->runScripts) {
                // dispatch post event
                $eventName = $this->update ? ScriptEvents::POST_UPDATE_CMD : ScriptEvents::POST_INSTALL_CMD;
                $this->eventDispatcher->dispatchCommandEvent($eventName);
            }
        }

        return true;
    }

    protected function doInstall($localRepo, $installedRepo, $aliases, $devMode = false)
    {
        $minimumStability = $this->package->getMinimumStability();
        $stabilityFlags = $this->package->getStabilityFlags();

        // initialize locker to create aliased packages
        $installFromLock = false;
        if (!$this->update && $this->locker->isLocked($devMode)) {
            $installFromLock = true;
            $lockedRepository = $this->locker->getLockedRepository($devMode);
            $minimumStability = $this->locker->getMinimumStability();
            $stabilityFlags = $this->locker->getStabilityFlags();
        }

        $this->whitelistUpdateDependencies(
            $localRepo,
            $devMode,
            $this->package->getRequires(),
            $this->package->getDevRequires()
        );

        $this->io->write('<info>Loading composer repositories with package information</info>');

        // creating repository pool
        $pool = new Pool($minimumStability, $stabilityFlags);
        $pool->addRepository($installedRepo, $aliases);
        if ($installFromLock) {
            $pool->addRepository($lockedRepository, $aliases);
        }

        if (!$installFromLock || !$this->locker->isCompleteFormat($devMode)) {
            $repositories = $this->repositoryManager->getRepositories();
            foreach ($repositories as $repository) {
                $pool->addRepository($repository, $aliases);
            }
        }

        // creating requirements request
        $request = new Request($pool);

        $constraint = new VersionConstraint('=', $this->package->getVersion());
        $request->install($this->package->getName(), $constraint);

        if ($this->update) {
            $this->io->write('<info>Updating '.($devMode ? 'dev ': '').'dependencies</info>');

            $request->updateAll();

            $links = $devMode ? $this->package->getDevRequires() : $this->package->getRequires();

            foreach ($links as $link) {
                $request->install($link->getTarget(), $link->getConstraint());
            }
        } elseif ($installFromLock) {
            $this->io->write('<info>Installing '.($devMode ? 'dev ': '').'dependencies from lock file</info>');

            if (!$this->locker->isFresh() && !$devMode) {
                $this->io->write('<warning>Your lock file is out of sync with your composer.json, run "composer.phar update" to update dependencies</warning>');
            }

            foreach ($lockedRepository->getPackages() as $package) {
                $version = $package->getVersion();
                if (isset($aliases[$package->getName()][$version])) {
                    $version = $aliases[$package->getName()][$version]['alias_normalized'];
                }
                $constraint = new VersionConstraint('=', $version);
                $request->install($package->getName(), $constraint);
            }
        } else {
            $this->io->write('<info>Installing '.($devMode ? 'dev ': '').'dependencies</info>');

            $links = $devMode ? $this->package->getDevRequires() : $this->package->getRequires();

            foreach ($links as $link) {
                $request->install($link->getTarget(), $link->getConstraint());
            }
        }

        // fix the version of all installed packages (+ platform) that are not
        // in the current local repo to prevent rogue updates (e.g. non-dev
        // updating when in dev)
        foreach ($installedRepo->getPackages() as $package) {
            if ($package->getRepository() === $localRepo) {
                continue;
            }

            $constraint = new VersionConstraint('=', $package->getVersion());
            $request->install($package->getName(), $constraint);
        }

        // if the updateWhitelist is enabled, packages not in it are also fixed
        // to the version specified in the lock, or their currently installed version
        if ($this->update && $this->updateWhitelist) {
            if ($this->locker->isLocked($devMode)) {
                $currentPackages = $this->locker->getLockedRepository($devMode)->getPackages();
            } else {
                $currentPackages = $installedRepo->getPackages();
            }

            // collect links from composer as well as installed packages
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
                        if ($this->isUpdateable($curPackage)) {
                            break;
                        }

                        $constraint = new VersionConstraint('=', $curPackage->getVersion());
                        $request->install($curPackage->getName(), $constraint);
                    }
                }
            }
        }

        // prepare solver
        $policy = new DefaultPolicy();
        $solver = new Solver($policy, $pool, $installedRepo);

        // solve dependencies
        try {
            $operations = $solver->solve($request);
        } catch (SolverProblemsException $e) {
            $this->io->write('<error>Your requirements could not be resolved to an installable set of packages.</error>');
            $this->io->write($e->getMessage());

            return false;
        }

        // force dev packages to be updated if we update or install from a (potentially new) lock
        foreach ($localRepo->getPackages() as $package) {
            // skip non-dev packages
            if (!$package->isDev()) {
                continue;
            }

            if ($package instanceof AliasPackage) {
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
                    if (
                        $lockedPackage->isDev()
                        && (
                            ($lockedPackage->getSourceReference() && $lockedPackage->getSourceReference() !== $package->getSourceReference())
                            || ($lockedPackage->getDistReference() && $lockedPackage->getDistReference() !== $package->getDistReference())
                        )
                    ) {
                        $operations[] = new UpdateOperation($package, $lockedPackage);

                        break;
                    }
                }
            } else {
                // force update to latest on update
                if ($this->update) {
                    // skip package if the whitelist is enabled and it is not in it
                    if ($this->updateWhitelist && !$this->isUpdateable($package)) {
                        continue;
                    }

                    $newPackage = null;
                    $matches = $pool->whatProvides($package->getName(), new VersionConstraint('=', $package->getVersion()));
                    foreach ($matches as $match) {
                        // skip local packages
                        if (!in_array($match->getRepository(), $repositories, true)) {
                            continue;
                        }

                        // skip providers/replacers
                        if ($match->getName() !== $package->getName()) {
                            continue;
                        }

                        $newPackage = $match;
                        break;
                    }

                    if ($newPackage && $newPackage->getSourceReference() !== $package->getSourceReference()) {
                        $operations[] = new UpdateOperation($package, $newPackage);
                    }
                }

                // force installed package to update to referenced version if it does not match the installed version
                $references = $this->package->getReferences();

                if (isset($references[$package->getName()]) && $references[$package->getName()] !== $package->getSourceReference()) {
                    // changing the source ref to update to will be handled in the operations loop below
                    $operations[] = new UpdateOperation($package, clone $package);
                }
            }
        }

        // execute operations
        if (!$operations) {
            $this->io->write('Nothing to install or update');
        }

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

            $event = 'Composer\Script\ScriptEvents::PRE_PACKAGE_'.strtoupper($operation->getJobType());
            if (defined($event) && $this->runScripts) {
                $this->eventDispatcher->dispatchPackageEvent(constant($event), $operation);
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
            }

            // output alias operations in verbose mode, or all ops in dry run
            if ($this->dryRun || ($this->verbose && false !== strpos($operation->getJobType(), 'Alias'))) {
                $this->io->write('  - ' . $operation);
            }

            $this->installationManager->execute($localRepo, $operation);

            $event = 'Composer\Script\ScriptEvents::POST_PACKAGE_'.strtoupper($operation->getJobType());
            if (defined($event) && $this->runScripts) {
                $this->eventDispatcher->dispatchPackageEvent(constant($event), $operation);
            }

            if (!$this->dryRun) {
                $localRepo->write();
            }
        }

        return true;
    }

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
                'alias_normalized' => $alias['alias_normalized']
            );
        }

        return $normalizedAliases;
    }

    private function aliasPlatformPackages(PlatformRepository $platformRepo, $aliases)
    {
        foreach ($aliases as $package => $versions) {
            foreach ($versions as $version => $alias) {
                $packages = $platformRepo->findPackages($package, $version);
                foreach ($packages as $package) {
                    $package->setAlias($alias['alias_normalized']);
                    $package->setPrettyAlias($alias['alias']);
                    $aliasPackage = new AliasPackage($package, $alias['alias_normalized'], $alias['alias']);
                    $aliasPackage->setRootPackageAlias(true);
                    $platformRepo->addPackage($aliasPackage);
                }
            }
        }
    }

    private function isUpdateable(PackageInterface $package)
    {
        if (!$this->updateWhitelist) {
            throw new \LogicException('isUpdateable should only be called when a whitelist is present');
        }

        return isset($this->updateWhitelist[$package->getName()]);
    }

    /**
     * Adds all dependencies of the update whitelist to the whitelist, too.
     *
     * Packages which are listed as requirements in the root package will be
     * skipped including their dependencies, unless they are listed in the
     * update whitelist themselves.
     *
     * @param RepositoryInterface $localRepo
     * @param boolean             $devMode
     * @param array               $rootRequires    An array of links to packages in require of the root package
     * @param array               $rootDevRequires An array of links to packages in require-dev of the root package
     */
    private function whitelistUpdateDependencies($localRepo, $devMode, array $rootRequires, array $rootDevRequires)
    {
        if (!$this->updateWhitelist) {
            return;
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

        foreach ($this->updateWhitelist as $packageName => $void) {
            $packageQueue = new \SplQueue;

            foreach ($pool->whatProvides($packageName) as $depPackage) {
                $packageQueue->enqueue($depPackage);
            }

            while (!$packageQueue->isEmpty()) {
                $package = $packageQueue->dequeue();
                if (isset($seen[$package->getId()])) {
                    continue;
                }

                $seen[$package->getId()] = true;
                $this->updateWhitelist[$package->getName()] = true;

                $requires = $package->getRequires();
                if ($devMode) {
                    $requires = array_merge($requires, $package->getDevRequires());
                }

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
     * Create Installer
     *
     * @param  IOInterface       $io
     * @param  Composer          $composer
     * @param  EventDispatcher   $eventDispatcher
     * @param  AutoloadGenerator $autoloadGenerator
     * @return Installer
     */
    public static function create(IOInterface $io, Composer $composer, EventDispatcher $eventDispatcher = null, AutoloadGenerator $autoloadGenerator = null)
    {
        $eventDispatcher = $eventDispatcher ?: new EventDispatcher($composer, $io);
        $autoloadGenerator = $autoloadGenerator ?: new AutoloadGenerator;

        return new static(
            $io,
            $composer->getConfig(),
            $composer->getPackage(),
            $composer->getDownloadManager(),
            $composer->getRepositoryManager(),
            $composer->getLocker(),
            $composer->getInstallationManager(),
            $eventDispatcher,
            $autoloadGenerator
        );
    }

    public function setAdditionalInstalledRepository(RepositoryInterface $additionalInstalledRepository)
    {
        $this->additionalInstalledRepository = $additionalInstalledRepository;

        return $this;
    }

    /**
     * wether to run in drymode or not
     *
     * @param  boolean   $dryRun
     * @return Installer
     */
    public function setDryRun($dryRun = true)
    {
        $this->dryRun = (boolean) $dryRun;

        return $this;
    }

    /**
     * prefer source installation
     *
     * @param  boolean   $preferSource
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
     * @param  boolean   $preferDist
     * @return Installer
     */
    public function setPreferDist($preferDist = true)
    {
        $this->preferDist = (boolean) $preferDist;

        return $this;
    }

    /**
     * update packages
     *
     * @param  boolean   $update
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
     * @param  boolean   $devMode
     * @return Installer
     */
    public function setDevMode($devMode = true)
    {
        $this->devMode = (boolean) $devMode;

        return $this;
    }

    /**
     * set whether to run scripts or not
     *
     * @param  boolean   $runScripts
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
     * @param  boolean   $verbose
     * @return Installer
     */
    public function setVerbose($verbose = true)
    {
        $this->verbose = (boolean) $verbose;

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
     * Disables custom installers.
     *
     * Call this if you want to ensure that third-party code never gets
     * executed. The default is to automatically install, and execute
     * custom third-party installers.
     */
    public function disableCustomInstallers()
    {
        $this->installationManager->disableCustomInstallers();
    }
}
