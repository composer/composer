<?php declare(strict_types=1);

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
use Composer\Console\GithubActionError;
use Composer\DependencyResolver\DefaultPolicy;
use Composer\DependencyResolver\LocalRepoTransaction;
use Composer\DependencyResolver\LockTransaction;
use Composer\DependencyResolver\Operation\UpdateOperation;
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\DependencyResolver\Operation\UninstallOperation;
use Composer\DependencyResolver\PoolOptimizer;
use Composer\DependencyResolver\Pool;
use Composer\DependencyResolver\Request;
use Composer\DependencyResolver\Solver;
use Composer\DependencyResolver\SolverProblemsException;
use Composer\DependencyResolver\PolicyInterface;
use Composer\Downloader\DownloadManager;
use Composer\Downloader\TransportException;
use Composer\EventDispatcher\EventDispatcher;
use Composer\Filter\PlatformRequirementFilter\IgnoreListPlatformRequirementFilter;
use Composer\Filter\PlatformRequirementFilter\PlatformRequirementFilterFactory;
use Composer\Filter\PlatformRequirementFilter\PlatformRequirementFilterInterface;
use Composer\Installer\InstallationManager;
use Composer\Installer\InstallerEvents;
use Composer\Installer\SuggestedPackagesReporter;
use Composer\IO\IOInterface;
use Composer\Package\AliasPackage;
use Composer\Package\RootAliasPackage;
use Composer\Package\BasePackage;
use Composer\Package\CompletePackage;
use Composer\Package\CompletePackageInterface;
use Composer\Package\Link;
use Composer\Package\Loader\ArrayLoader;
use Composer\Package\Dumper\ArrayDumper;
use Composer\Package\Version\VersionParser;
use Composer\Package\Package;
use Composer\Repository\ArrayRepository;
use Composer\Repository\RepositorySet;
use Composer\Repository\CompositeRepository;
use Composer\Semver\Constraint\Constraint;
use Composer\Package\Locker;
use Composer\Package\RootPackageInterface;
use Composer\Repository\InstalledArrayRepository;
use Composer\Repository\InstalledRepositoryInterface;
use Composer\Repository\InstalledRepository;
use Composer\Repository\RootPackageRepository;
use Composer\Repository\PlatformRepository;
use Composer\Repository\RepositoryInterface;
use Composer\Repository\RepositoryManager;
use Composer\Repository\LockArrayRepository;
use Composer\Script\ScriptEvents;
use Composer\Semver\Constraint\ConstraintInterface;
use Composer\Advisory\Auditor;
use Composer\Util\Platform;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 * @author Beau Simensen <beau@dflydev.com>
 * @author Konstantin Kudryashov <ever.zet@gmail.com>
 * @author Nils Adermann <naderman@naderman.de>
 */
class Installer
{
    public const ERROR_NONE = 0; // no error/success state
    public const ERROR_GENERIC_FAILURE = 1;
    public const ERROR_NO_LOCK_FILE_FOR_PARTIAL_UPDATE = 3;
    public const ERROR_LOCK_FILE_INVALID = 4;
    // used/declared in SolverProblemsException, carried over here for completeness
    public const ERROR_DEPENDENCY_RESOLUTION_FAILED = 2;

    /**
     * @var IOInterface
     */
    protected $io;

    /**
     * @var Config
     */
    protected $config;

    /**
     * @var RootPackageInterface&BasePackage
     */
    protected $package;

    // TODO can we get rid of the below and just use the package itself?
    /**
     * @var RootPackageInterface&BasePackage
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

    /** @var bool */
    protected $preferSource = false;
    /** @var bool */
    protected $preferDist = false;
    /** @var bool */
    protected $optimizeAutoloader = false;
    /** @var bool */
    protected $classMapAuthoritative = false;
    /** @var bool */
    protected $apcuAutoloader = false;
    /** @var string|null */
    protected $apcuAutoloaderPrefix = null;
    /** @var bool */
    protected $devMode = false;
    /** @var bool */
    protected $dryRun = false;
    /** @var bool */
    protected $verbose = false;
    /** @var bool */
    protected $update = false;
    /** @var bool */
    protected $install = true;
    /** @var bool */
    protected $dumpAutoloader = true;
    /** @var bool */
    protected $runScripts = true;
    /** @var bool */
    protected $preferStable = false;
    /** @var bool */
    protected $preferLowest = false;
    /** @var bool */
    protected $writeLock;
    /** @var bool */
    protected $executeOperations = true;
    /** @var bool */
    protected $audit = true;
    /** @var Auditor::FORMAT_* */
    protected $auditFormat = Auditor::FORMAT_SUMMARY;

    /** @var bool */
    protected $updateMirrors = false;
    /**
     * Array of package names/globs flagged for update
     *
     * @var string[]|null
     */
    protected $updateAllowList = null;
    /** @var Request::UPDATE_* */
    protected $updateAllowTransitiveDependencies = Request::UPDATE_ONLY_LISTED;

    /**
     * @var SuggestedPackagesReporter
     */
    protected $suggestedPackagesReporter;

    /**
     * @var PlatformRequirementFilterInterface
     */
    protected $platformRequirementFilter;

    /**
     * @var ?RepositoryInterface
     */
    protected $additionalFixedRepository;

    /** @var array<string, ConstraintInterface> */
    protected $temporaryConstraints = [];

    /**
     * Constructor
     *
     * @param IOInterface          $io
     * @param Config               $config
     * @param RootPackageInterface&BasePackage $package
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
        $this->suggestedPackagesReporter = new SuggestedPackagesReporter($this->io);
        $this->platformRequirementFilter = PlatformRequirementFilterFactory::ignoreNothing();

        $this->writeLock = $config->get('lock');
    }

    /**
     * Run installation (or update)
     *
     * @throws \Exception
     * @return int        0 on success or a positive error code on failure
     * @phpstan-return self::ERROR_*
     */
    public function run(): int
    {
        // Disable GC to save CPU cycles, as the dependency solver can create hundreds of thousands
        // of PHP objects, the GC can spend quite some time walking the tree of references looking
        // for stuff to collect while there is nothing to collect. This slows things down dramatically
        // and turning it off results in much better performance. Do not try this at home however.
        gc_collect_cycles();
        gc_disable();

        if ($this->updateAllowList && $this->updateMirrors) {
            throw new \RuntimeException("The installer options updateMirrors and updateAllowList are mutually exclusive.");
        }

        $isFreshInstall = $this->repositoryManager->getLocalRepository()->isFresh();

        // Force update if there is no lock file present
        if (!$this->update && !$this->locker->isLocked()) {
            $this->io->writeError('<warning>No composer.lock file present. Updating dependencies to latest instead of installing from lock file. See https://getcomposer.org/install for more information.</warning>');
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

        if ($this->update && !$this->install) {
            $this->dumpAutoloader = false;
        }

        if ($this->runScripts) {
            Platform::putEnv('COMPOSER_DEV_MODE', $this->devMode ? '1' : '0');

            // dispatch pre event
            // should we treat this more strictly as running an update and then running an install, triggering events multiple times?
            $eventName = $this->update ? ScriptEvents::PRE_UPDATE_CMD : ScriptEvents::PRE_INSTALL_CMD;
            $this->eventDispatcher->dispatchScript($eventName, $this->devMode);
        }

        $this->downloadManager->setPreferSource($this->preferSource);
        $this->downloadManager->setPreferDist($this->preferDist);

        $localRepo = $this->repositoryManager->getLocalRepository();

        try {
            if ($this->update) {
                $res = $this->doUpdate($localRepo, $this->install);
            } else {
                $res = $this->doInstall($localRepo);
            }
            if ($res !== 0) {
                return $res;
            }
        } catch (\Exception $e) {
            if ($this->executeOperations && $this->install && $this->config->get('notify-on-install')) {
                $this->installationManager->notifyInstalls($this->io);
            }

            throw $e;
        }
        if ($this->executeOperations && $this->install && $this->config->get('notify-on-install')) {
            $this->installationManager->notifyInstalls($this->io);
        }

        if ($this->update) {
            $installedRepo = new InstalledRepository(array(
                $this->locker->getLockedRepository($this->devMode),
                $this->createPlatformRepo(false),
                new RootPackageRepository(clone $this->package),
            ));
            if ($isFreshInstall) {
                $this->suggestedPackagesReporter->addSuggestionsFromPackage($this->package);
            }
            $this->suggestedPackagesReporter->outputMinimalistic($installedRepo);
        }

        // Find abandoned packages and warn user
        $lockedRepository = $this->locker->getLockedRepository(true);
        foreach ($lockedRepository->getPackages() as $package) {
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

            $this->autoloadGenerator->setClassMapAuthoritative($this->classMapAuthoritative);
            $this->autoloadGenerator->setApcu($this->apcuAutoloader, $this->apcuAutoloaderPrefix);
            $this->autoloadGenerator->setRunScripts($this->runScripts);
            $this->autoloadGenerator->setPlatformRequirementFilter($this->platformRequirementFilter);
            $this->autoloadGenerator->dump($this->config, $localRepo, $this->package, $this->installationManager, 'composer', $this->optimizeAutoloader);
        }

        if ($this->install && $this->executeOperations) {
            // force binaries re-generation in case they are missing
            foreach ($localRepo->getPackages() as $package) {
                $this->installationManager->ensureBinariesPresence($package);
            }
        }

        $fundingCount = 0;
        foreach ($localRepo->getPackages() as $package) {
            if ($package instanceof CompletePackageInterface && !$package instanceof AliasPackage && $package->getFunding()) {
                $fundingCount++;
            }
        }
        if ($fundingCount > 0) {
            $this->io->writeError(array(
                sprintf(
                    "<info>%d package%s you are using %s looking for funding.</info>",
                    $fundingCount,
                    1 === $fundingCount ? '' : 's',
                    1 === $fundingCount ? 'is' : 'are'
                ),
                '<info>Use the `composer fund` command to find out more!</info>',
            ));
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

        if ($this->audit) {
            if ($this->update && !$this->install) {
                $packages = $lockedRepository->getCanonicalPackages();
                $target = 'locked';
            } else {
                $packages = $localRepo->getCanonicalPackages();
                $target = 'installed';
            }
            if (count($packages) > 0) {
                try {
                    $auditor = new Auditor();
                    $repoSet = new RepositorySet();
                    foreach ($this->repositoryManager->getRepositories() as $repo) {
                        $repoSet->addRepository($repo);
                    }
                    $auditor->audit($this->io, $repoSet, $packages, $this->auditFormat);
                } catch (TransportException $e) {
                    $this->io->error('Failed to audit '.$target.' packages.');
                    if ($this->io->isVerbose()) {
                        $this->io->error('['.get_class($e).'] '.$e->getMessage());
                    }
                }
            } else {
                $this->io->writeError('No '.$target.' packages - skipping audit.');
            }
        }

        return 0;
    }

    /**
     * @param bool $doInstall
     *
     * @return int
     * @phpstan-return self::ERROR_*
     */
    protected function doUpdate(InstalledRepositoryInterface $localRepo, bool $doInstall): int
    {
        $platformRepo = $this->createPlatformRepo(true);
        $aliases = $this->getRootAliases(true);

        $lockedRepository = null;

        try {
            if ($this->locker->isLocked()) {
                $lockedRepository = $this->locker->getLockedRepository(true);
            }
        } catch (\Seld\JsonLint\ParsingException $e) {
            if ($this->updateAllowList || $this->updateMirrors) {
                // in case we are doing a partial update or updating mirrors, the lock file is needed so we error
                throw $e;
            }
            // otherwise, ignoring parse errors as the lock file will be regenerated from scratch when
            // doing a full update
        }

        if (($this->updateAllowList || $this->updateMirrors) && !$lockedRepository) {
            $this->io->writeError('<error>Cannot update ' . ($this->updateMirrors ? 'lock file information' : 'only a partial set of packages') . ' without a lock file present. Run `composer update` to generate a lock file.</error>', true, IOInterface::QUIET);

            return self::ERROR_NO_LOCK_FILE_FOR_PARTIAL_UPDATE;
        }

        $this->io->writeError('<info>Loading composer repositories with package information</info>');

        // creating repository set
        $policy = $this->createPolicy(true);
        $repositorySet = $this->createRepositorySet(true, $platformRepo, $aliases);
        $repositories = $this->repositoryManager->getRepositories();
        foreach ($repositories as $repository) {
            $repositorySet->addRepository($repository);
        }
        if ($lockedRepository) {
            $repositorySet->addRepository($lockedRepository);
        }

        $request = $this->createRequest($this->fixedRootPackage, $platformRepo, $lockedRepository);
        $this->requirePackagesForUpdate($request, $lockedRepository, true);

        // pass the allow list into the request, so the pool builder can apply it
        if ($this->updateAllowList) {
            $request->setUpdateAllowList($this->updateAllowList, $this->updateAllowTransitiveDependencies);
        }

        $pool = $repositorySet->createPool($request, $this->io, $this->eventDispatcher, $this->createPoolOptimizer($policy));

        $this->io->writeError('<info>Updating dependencies</info>');

        // solve dependencies
        $solver = new Solver($policy, $pool, $this->io);
        try {
            $lockTransaction = $solver->solve($request, $this->platformRequirementFilter);
            $ruleSetSize = $solver->getRuleSetSize();
            $solver = null;
        } catch (SolverProblemsException $e) {
            $err = 'Your requirements could not be resolved to an installable set of packages.';
            $prettyProblem = $e->getPrettyString($repositorySet, $request, $pool, $this->io->isVerbose());

            $this->io->writeError('<error>'. $err .'</error>', true, IOInterface::QUIET);
            $this->io->writeError($prettyProblem);
            if (!$this->devMode) {
                $this->io->writeError('<warning>Running update with --no-dev does not mean require-dev is ignored, it just means the packages will not be installed. If dev requirements are blocking the update you have to resolve those problems.</warning>', true, IOInterface::QUIET);
            }

            $ghe = new GithubActionError($this->io);
            $ghe->emit($err."\n".$prettyProblem);

            return max(self::ERROR_GENERIC_FAILURE, $e->getCode());
        }

        $this->io->writeError("Analyzed ".count($pool)." packages to resolve dependencies", true, IOInterface::VERBOSE);
        $this->io->writeError("Analyzed ".$ruleSetSize." rules to resolve dependencies", true, IOInterface::VERBOSE);

        $pool = null;

        if (!$lockTransaction->getOperations()) {
            $this->io->writeError('Nothing to modify in lock file');
        }

        $exitCode = $this->extractDevPackages($lockTransaction, $platformRepo, $aliases, $policy, $lockedRepository);
        if ($exitCode !== 0) {
            return $exitCode;
        }

        // exists as of composer/semver 3.3.0
        if (method_exists('Composer\Semver\CompilingMatcher', 'clear')) { // @phpstan-ignore-line
            \Composer\Semver\CompilingMatcher::clear();
        }

        // write lock
        $platformReqs = $this->extractPlatformRequirements($this->package->getRequires());
        $platformDevReqs = $this->extractPlatformRequirements($this->package->getDevRequires());

        $installsUpdates = $uninstalls = array();
        if ($lockTransaction->getOperations()) {
            $installNames = $updateNames = $uninstallNames = array();
            foreach ($lockTransaction->getOperations() as $operation) {
                if ($operation instanceof InstallOperation) {
                    $installsUpdates[] = $operation;
                    $installNames[] = $operation->getPackage()->getPrettyName().':'.$operation->getPackage()->getFullPrettyVersion();
                } elseif ($operation instanceof UpdateOperation) {
                    // when mirrors/metadata from a package gets updated we do not want to list it as an
                    // update in the output as it is only an internal lock file metadata update
                    if ($this->updateMirrors
                        && $operation->getInitialPackage()->getName() === $operation->getTargetPackage()->getName()
                        && $operation->getInitialPackage()->getVersion() === $operation->getTargetPackage()->getVersion()
                    ) {
                        continue;
                    }

                    $installsUpdates[] = $operation;
                    $updateNames[] = $operation->getTargetPackage()->getPrettyName().':'.$operation->getTargetPackage()->getFullPrettyVersion();
                } elseif ($operation instanceof UninstallOperation) {
                    $uninstalls[] = $operation;
                    $uninstallNames[] = $operation->getPackage()->getPrettyName();
                }
            }

            if ($this->config->get('lock')) {
                $this->io->writeError(sprintf(
                    "<info>Lock file operations: %d install%s, %d update%s, %d removal%s</info>",
                    count($installNames),
                    1 === count($installNames) ? '' : 's',
                    count($updateNames),
                    1 === count($updateNames) ? '' : 's',
                    count($uninstalls),
                    1 === count($uninstalls) ? '' : 's'
                ));
                if ($installNames) {
                    $this->io->writeError("Installs: ".implode(', ', $installNames), true, IOInterface::VERBOSE);
                }
                if ($updateNames) {
                    $this->io->writeError("Updates: ".implode(', ', $updateNames), true, IOInterface::VERBOSE);
                }
                if ($uninstalls) {
                    $this->io->writeError("Removals: ".implode(', ', $uninstallNames), true, IOInterface::VERBOSE);
                }
            }
        }

        $sortByName = static function ($a, $b): int {
            if ($a instanceof UpdateOperation) {
                $a = $a->getTargetPackage()->getName();
            } else {
                $a = $a->getPackage()->getName();
            }
            if ($b instanceof UpdateOperation) {
                $b = $b->getTargetPackage()->getName();
            } else {
                $b = $b->getPackage()->getName();
            }

            return strcmp($a, $b);
        };
        usort($uninstalls, $sortByName);
        usort($installsUpdates, $sortByName);

        foreach (array_merge($uninstalls, $installsUpdates) as $operation) {
            // collect suggestions
            if ($operation instanceof InstallOperation) {
                $this->suggestedPackagesReporter->addSuggestionsFromPackage($operation->getPackage());
            }

            // output op if lock file is enabled, but alias op only in debug verbosity
            if ($this->config->get('lock') && (false === strpos($operation->getOperationType(), 'Alias') || $this->io->isDebug())) {
                $this->io->writeError('  - ' . $operation->show(true));
            }
        }

        $updatedLock = $this->locker->setLockData(
            $lockTransaction->getNewLockPackages(false, $this->updateMirrors),
            $lockTransaction->getNewLockPackages(true, $this->updateMirrors),
            $platformReqs,
            $platformDevReqs,
            $lockTransaction->getAliases($aliases),
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

        // see https://github.com/composer/composer/issues/2764
        if ($this->executeOperations && count($lockTransaction->getOperations()) > 0) {
            $vendorDir = $this->config->get('vendor-dir');
            if (is_dir($vendorDir)) {
                // suppress errors as this fails sometimes on OSX for no apparent reason
                // see https://github.com/composer/composer/issues/4070#issuecomment-129792748
                @touch($vendorDir);
            }
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
     *
     * @param array<int, array<string, string>> $aliases
     *
     * @return int
     *
     * @phpstan-param list<array{package: string, version: string, alias: string, alias_normalized: string}> $aliases
     * @phpstan-return self::ERROR_*
     */
    protected function extractDevPackages(LockTransaction $lockTransaction, PlatformRepository $platformRepo, array $aliases, PolicyInterface $policy, LockArrayRepository $lockedRepository = null): int
    {
        if (!$this->package->getDevRequires()) {
            return 0;
        }

        $resultRepo = new ArrayRepository(array());
        $loader = new ArrayLoader(null, true);
        $dumper = new ArrayDumper();
        foreach ($lockTransaction->getNewLockPackages(false) as $pkg) {
            $resultRepo->addPackage($loader->load($dumper->dump($pkg)));
        }

        $repositorySet = $this->createRepositorySet(true, $platformRepo, $aliases);
        $repositorySet->addRepository($resultRepo);

        $request = $this->createRequest($this->fixedRootPackage, $platformRepo);
        $this->requirePackagesForUpdate($request, $lockedRepository, false);

        $pool = $repositorySet->createPoolWithAllPackages();

        $solver = new Solver($policy, $pool, $this->io);
        try {
            $nonDevLockTransaction = $solver->solve($request, $this->platformRequirementFilter);
            $solver = null;
        } catch (SolverProblemsException $e) {
            $err = 'Unable to find a compatible set of packages based on your non-dev requirements alone.';
            $prettyProblem = $e->getPrettyString($repositorySet, $request, $pool, $this->io->isVerbose(), true);

            $this->io->writeError('<error>'. $err .'</error>', true, IOInterface::QUIET);
            $this->io->writeError('Your requirements can be resolved successfully when require-dev packages are present.');
            $this->io->writeError('You may need to move packages from require-dev or some of their dependencies to require.');
            $this->io->writeError($prettyProblem);

            $ghe = new GithubActionError($this->io);
            $ghe->emit($err."\n".$prettyProblem);

            return $e->getCode();
        }

        $lockTransaction->setNonDevPackages($nonDevLockTransaction);

        return 0;
    }

    /**
     * @param  InstalledRepositoryInterface $localRepo
     * @param  bool                         $alreadySolved Whether the function is called as part of an update command or independently
     * @return int                          exit code
     * @phpstan-return self::ERROR_*
     */
    protected function doInstall(InstalledRepositoryInterface $localRepo, bool $alreadySolved = false): int
    {
        if ($this->config->get('lock')) {
            $this->io->writeError('<info>Installing dependencies from lock file'.($this->devMode ? ' (including require-dev)' : '').'</info>');
        }

        $lockedRepository = $this->locker->getLockedRepository($this->devMode);

        // verify that the lock file works with the current platform repository
        // we can skip this part if we're doing this as the second step after an update
        if (!$alreadySolved) {
            $this->io->writeError('<info>Verifying lock file contents can be installed on current platform.</info>');

            $platformRepo = $this->createPlatformRepo(false);
            // creating repository set
            $policy = $this->createPolicy(false);
            // use aliases from lock file only, so empty root aliases here
            $repositorySet = $this->createRepositorySet(false, $platformRepo, array(), $lockedRepository);
            $repositorySet->addRepository($lockedRepository);

            // creating requirements request
            $request = $this->createRequest($this->fixedRootPackage, $platformRepo, $lockedRepository);

            if (!$this->locker->isFresh()) {
                $this->io->writeError('<warning>Warning: The lock file is not up to date with the latest changes in composer.json. You may be getting outdated dependencies. It is recommended that you run `composer update` or `composer update <package name>`.</warning>', true, IOInterface::QUIET);
            }

            foreach ($lockedRepository->getPackages() as $package) {
                $request->fixLockedPackage($package);
            }

            foreach ($this->locker->getPlatformRequirements($this->devMode) as $link) {
                $request->requireName($link->getTarget(), $link->getConstraint());
            }

            $pool = $repositorySet->createPool($request, $this->io, $this->eventDispatcher);

            // solve dependencies
            $solver = new Solver($policy, $pool, $this->io);
            try {
                $lockTransaction = $solver->solve($request, $this->platformRequirementFilter);
                $solver = null;

                // installing the locked packages on this platform resulted in lock modifying operations, there wasn't a conflict, but the lock file as-is seems to not work on this system
                if (0 !== count($lockTransaction->getOperations())) {
                    $this->io->writeError('<error>Your lock file cannot be installed on this system without changes. Please run composer update.</error>', true, IOInterface::QUIET);

                    return self::ERROR_LOCK_FILE_INVALID;
                }
            } catch (SolverProblemsException $e) {
                $err = 'Your lock file does not contain a compatible set of packages. Please run composer update.';
                $prettyProblem = $e->getPrettyString($repositorySet, $request, $pool, $this->io->isVerbose());

                $this->io->writeError('<error>'. $err .'</error>', true, IOInterface::QUIET);
                $this->io->writeError($prettyProblem);

                $ghe = new GithubActionError($this->io);
                $ghe->emit($err."\n".$prettyProblem);

                return max(self::ERROR_GENERIC_FAILURE, $e->getCode());
            }
        }

        // TODO in how far do we need to do anything here to ensure dev packages being updated to latest in lock without version change are treated correctly?
        $localRepoTransaction = new LocalRepoTransaction($lockedRepository, $localRepo);
        $this->eventDispatcher->dispatchInstallerEvent(InstallerEvents::PRE_OPERATIONS_EXEC, $this->devMode, $this->executeOperations, $localRepoTransaction);

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
            $localRepo->setDevPackageNames($this->locker->getDevPackageNames());
            $this->installationManager->execute($localRepo, $localRepoTransaction->getOperations(), $this->devMode, $this->runScripts);
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

    /**
     * @param bool $forUpdate
     *
     * @return PlatformRepository
     */
    protected function createPlatformRepo(bool $forUpdate): PlatformRepository
    {
        if ($forUpdate) {
            $platformOverrides = $this->config->get('platform') ?: array();
        } else {
            $platformOverrides = $this->locker->getPlatformOverrides();
        }

        return new PlatformRepository(array(), $platformOverrides);
    }

    /**
     * @param  bool                              $forUpdate
     * @param  array<int, array<string, string>> $rootAliases
     * @param  RepositoryInterface|null          $lockedRepository
     *
     * @return RepositorySet
     *
     * @phpstan-param list<array{package: string, version: string, alias: string, alias_normalized: string}> $rootAliases
     */
    private function createRepositorySet(bool $forUpdate, PlatformRepository $platformRepo, array $rootAliases = array(), ?RepositoryInterface $lockedRepository = null): RepositorySet
    {
        if ($forUpdate) {
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
            if ($constraint instanceof Link) {
                $constraint = $constraint->getConstraint();
            }
            // skip platform requirements from the root package to avoid filtering out existing platform packages
            if ($this->platformRequirementFilter->isIgnored($req)) {
                continue;
            } elseif ($this->platformRequirementFilter instanceof IgnoreListPlatformRequirementFilter) {
                $constraint = $this->platformRequirementFilter->filterConstraint($req, $constraint);
            }
            $rootRequires[$req] = $constraint;
        }

        $this->fixedRootPackage = clone $this->package;
        $this->fixedRootPackage->setRequires(array());
        $this->fixedRootPackage->setDevRequires(array());

        $stabilityFlags[$this->package->getName()] = BasePackage::$stabilities[VersionParser::parseStability($this->package->getVersion())];

        $repositorySet = new RepositorySet($minimumStability, $stabilityFlags, $rootAliases, $this->package->getReferences(), $rootRequires, $this->temporaryConstraints);
        $repositorySet->addRepository(new RootPackageRepository($this->fixedRootPackage));
        $repositorySet->addRepository($platformRepo);
        if ($this->additionalFixedRepository) {
            // allow using installed repos if needed to avoid warnings about installed repositories being used in the RepositorySet
            // see https://github.com/composer/composer/pull/9574
            $additionalFixedRepositories = $this->additionalFixedRepository;
            if ($additionalFixedRepositories instanceof CompositeRepository) {
                $additionalFixedRepositories = $additionalFixedRepositories->getRepositories();
            } else {
                $additionalFixedRepositories = array($additionalFixedRepositories);
            }
            foreach ($additionalFixedRepositories as $additionalFixedRepository) {
                if ($additionalFixedRepository instanceof InstalledRepository || $additionalFixedRepository instanceof InstalledRepositoryInterface) {
                    $repositorySet->allowInstalledRepositories();
                    break;
                }
            }

            $repositorySet->addRepository($this->additionalFixedRepository);
        }

        return $repositorySet;
    }

    /**
     * @param bool $forUpdate
     *
     * @return DefaultPolicy
     */
    private function createPolicy(bool $forUpdate): DefaultPolicy
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
     * @param RootPackageInterface&BasePackage $rootPackage
     * @return Request
     */
    private function createRequest(RootPackageInterface $rootPackage, PlatformRepository $platformRepo, LockArrayRepository $lockedRepository = null): Request
    {
        $request = new Request($lockedRepository);

        $request->fixPackage($rootPackage);
        if ($rootPackage instanceof RootAliasPackage) {
            $request->fixPackage($rootPackage->getAliasOf());
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
                $request->fixPackage($package);
            }
        }

        return $request;
    }

    /**
     * @param LockArrayRepository|null $lockedRepository
     * @param bool                     $includeDevRequires
     *
     * @return void
     */
    private function requirePackagesForUpdate(Request $request, LockArrayRepository $lockedRepository = null, bool $includeDevRequires = true): void
    {
        // if we're updating mirrors we want to keep exactly the same versions installed which are in the lock file, but we want current remote metadata
        if ($this->updateMirrors) {
            $excludedPackages = array();
            if (!$includeDevRequires) {
                $excludedPackages = array_flip($this->locker->getDevPackageNames());
            }

            foreach ($lockedRepository->getPackages() as $lockedPackage) {
                // exclude alias packages here as for root aliases, both alias and aliased are
                // present in the lock repo and we only want to require the aliased version
                if (!$lockedPackage instanceof AliasPackage && !isset($excludedPackages[$lockedPackage->getName()])) {
                    $request->requireName($lockedPackage->getName(), new Constraint('==', $lockedPackage->getVersion()));
                }
            }
        } else {
            $links = $this->package->getRequires();
            if ($includeDevRequires) {
                $links = array_merge($links, $this->package->getDevRequires());
            }
            foreach ($links as $link) {
                $request->requireName($link->getTarget(), $link->getConstraint());
            }
        }
    }

    /**
     * @param bool $forUpdate
     *
     * @return array<int, array<string, string>>
     *
     * @phpstan-return list<array{package: string, version: string, alias: string, alias_normalized: string}>
     */
    private function getRootAliases(bool $forUpdate): array
    {
        if ($forUpdate) {
            $aliases = $this->package->getAliases();
        } else {
            $aliases = $this->locker->getAliases();
        }

        return $aliases;
    }

    /**
     * @param Link[] $links
     *
     * @return array<string, string>
     */
    private function extractPlatformRequirements(array $links): array
    {
        $platformReqs = array();
        foreach ($links as $link) {
            if (PlatformRepository::isPlatformPackage($link->getTarget())) {
                $platformReqs[$link->getTarget()] = $link->getPrettyConstraint();
            }
        }

        return $platformReqs;
    }

    /**
     * Replace local repositories with InstalledArrayRepository instances
     *
     * This is to prevent any accidental modification of the existing repos on disk
     *
     * @return void
     */
    private function mockLocalRepositories(RepositoryManager $rm): void
    {
        $packages = array();
        foreach ($rm->getLocalRepository()->getPackages() as $package) {
            $packages[(string) $package] = clone $package;
        }
        foreach ($packages as $key => $package) {
            if ($package instanceof AliasPackage) {
                $alias = (string) $package->getAliasOf();
                $className = get_class($package);
                $packages[$key] = new $className($packages[$alias], $package->getVersion(), $package->getPrettyVersion());
            }
        }
        $rm->setLocalRepository(
            new InstalledArrayRepository($packages)
        );
    }

    /**
     * @return PoolOptimizer|null
     */
    private function createPoolOptimizer(PolicyInterface $policy): ?PoolOptimizer
    {
        // Not the best architectural decision here, would need to be able
        // to configure from the outside of Installer but this is only
        // a debugging tool and should never be required in any other use case
        if ('0' === Platform::getEnv('COMPOSER_POOL_OPTIMIZER')) {
            $this->io->write('Pool Optimizer was disabled for debugging purposes.', true, IOInterface::DEBUG);

            return null;
        }

        return new PoolOptimizer($policy);
    }

    /**
     * Create Installer
     *
     * @param  IOInterface $io
     * @param  Composer    $composer
     * @return Installer
     */
    public static function create(IOInterface $io, Composer $composer): self
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
    public function setAdditionalFixedRepository(RepositoryInterface $additionalFixedRepository): self
    {
        $this->additionalFixedRepository = $additionalFixedRepository;

        return $this;
    }

    /**
     * @param array<string, ConstraintInterface> $constraints
     * @return Installer
     */
    public function setTemporaryConstraints(array $constraints): self
    {
        $this->temporaryConstraints = $constraints;

        return $this;
    }

    /**
     * Whether to run in drymode or not
     *
     * @param  bool      $dryRun
     * @return Installer
     */
    public function setDryRun(bool $dryRun = true): self
    {
        $this->dryRun = (bool) $dryRun;

        return $this;
    }

    /**
     * Checks, if this is a dry run (simulation mode).
     *
     * @return bool
     */
    public function isDryRun(): bool
    {
        return $this->dryRun;
    }

    /**
     * prefer source installation
     *
     * @param  bool      $preferSource
     * @return Installer
     */
    public function setPreferSource(bool $preferSource = true): self
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
    public function setPreferDist(bool $preferDist = true): self
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
    public function setOptimizeAutoloader(bool $optimizeAutoloader): self
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
    public function setClassMapAuthoritative(bool $classMapAuthoritative): self
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
     * @param  bool        $apcuAutoloader
     * @param  string|null $apcuAutoloaderPrefix
     * @return Installer
     */
    public function setApcuAutoloader(bool $apcuAutoloader, ?string $apcuAutoloaderPrefix = null): self
    {
        $this->apcuAutoloader = $apcuAutoloader;
        $this->apcuAutoloaderPrefix = $apcuAutoloaderPrefix;

        return $this;
    }

    /**
     * update packages
     *
     * @param  bool      $update
     * @return Installer
     */
    public function setUpdate(bool $update): self
    {
        $this->update = (bool) $update;

        return $this;
    }

    /**
     * Allows disabling the install step after an update
     *
     * @param  bool      $install
     * @return Installer
     */
    public function setInstall(bool $install): self
    {
        $this->install = (bool) $install;

        return $this;
    }

    /**
     * enables dev packages
     *
     * @param  bool      $devMode
     * @return Installer
     */
    public function setDevMode(bool $devMode = true): self
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
    public function setDumpAutoloader(bool $dumpAutoloader = true): self
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
     * @deprecated Use setRunScripts(false) on the EventDispatcher instance being injected instead
     */
    public function setRunScripts(bool $runScripts = true): self
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
    public function setConfig(Config $config): self
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
    public function setVerbose(bool $verbose = true): self
    {
        $this->verbose = (bool) $verbose;

        return $this;
    }

    /**
     * Checks, if running in verbose mode.
     *
     * @return bool
     */
    public function isVerbose(): bool
    {
        return $this->verbose;
    }

    /**
     * set ignore Platform Package requirements
     *
     * If this is set to true, all platform requirements are ignored
     * If this is set to false, no platform requirements are ignored
     * If this is set to string[], those packages will be ignored
     *
     * @param  bool|string[] $ignorePlatformReqs
     *
     * @return Installer
     *
     * @deprecated use setPlatformRequirementFilter instead
     */
    public function setIgnorePlatformRequirements($ignorePlatformReqs): self
    {
        trigger_error('Installer::setIgnorePlatformRequirements is deprecated since Composer 2.2, use setPlatformRequirementFilter instead.', E_USER_DEPRECATED);

        return $this->setPlatformRequirementFilter(PlatformRequirementFilterFactory::fromBoolOrList($ignorePlatformReqs));
    }

    /**
     * @param PlatformRequirementFilterInterface $platformRequirementFilter
     * @return Installer
     */
    public function setPlatformRequirementFilter(PlatformRequirementFilterInterface $platformRequirementFilter): self
    {
        $this->platformRequirementFilter = $platformRequirementFilter;

        return $this;
    }

    /**
     * Update the lock file to the exact same versions and references but use current remote metadata like URLs and mirror info
     *
     * @param  bool      $updateMirrors
     * @return Installer
     */
    public function setUpdateMirrors(bool $updateMirrors): self
    {
        $this->updateMirrors = $updateMirrors;

        return $this;
    }

    /**
     * restrict the update operation to a few packages, all other packages
     * that are already installed will be kept at their current version
     *
     * @param string[] $packages
     *
     * @return Installer
     */
    public function setUpdateAllowList(array $packages): self
    {
        $this->updateAllowList = array_flip(array_map('strtolower', $packages));

        return $this;
    }

    /**
     * Should dependencies of packages marked for update be updated?
     *
     * Depending on the chosen constant this will either only update the directly named packages, all transitive
     * dependencies which are not root requirement or all transitive dependencies including root requirements
     *
     * @param  int       $updateAllowTransitiveDependencies One of the UPDATE_ constants on the Request class
     * @return Installer
     */
    public function setUpdateAllowTransitiveDependencies(int $updateAllowTransitiveDependencies): self
    {
        if (!in_array($updateAllowTransitiveDependencies, array(Request::UPDATE_ONLY_LISTED, Request::UPDATE_LISTED_WITH_TRANSITIVE_DEPS_NO_ROOT_REQUIRE, Request::UPDATE_LISTED_WITH_TRANSITIVE_DEPS), true)) {
            throw new \RuntimeException("Invalid value for updateAllowTransitiveDependencies supplied");
        }

        $this->updateAllowTransitiveDependencies = $updateAllowTransitiveDependencies;

        return $this;
    }

    /**
     * Should packages be preferred in a stable version when updating?
     *
     * @param  bool      $preferStable
     * @return Installer
     */
    public function setPreferStable(bool $preferStable = true): self
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
    public function setPreferLowest(bool $preferLowest = true): self
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
    public function setWriteLock(bool $writeLock = true): self
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
    public function setExecuteOperations(bool $executeOperations = true): self
    {
        $this->executeOperations = (bool) $executeOperations;

        return $this;
    }

    /**
     * Should an audit be run after installation is complete?
     *
     * @param boolean $audit
     * @return Installer
     */
    public function setAudit(bool $audit): self
    {
        $this->audit = $audit;

        return $this;
    }

    /**
     * What format should be used for audit output?
     *
     * @param Auditor::FORMAT_* $auditFormat
     * @return Installer
     */
    public function setAuditFormat(string $auditFormat): self
    {
        $this->auditFormat = $auditFormat;

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
    public function disablePlugins(): self
    {
        $this->installationManager->disablePlugins();

        return $this;
    }

    /**
     * @param  SuggestedPackagesReporter $suggestedPackagesReporter
     * @return Installer
     */
    public function setSuggestedPackagesReporter(SuggestedPackagesReporter $suggestedPackagesReporter): self
    {
        $this->suggestedPackagesReporter = $suggestedPackagesReporter;

        return $this;
    }
}
