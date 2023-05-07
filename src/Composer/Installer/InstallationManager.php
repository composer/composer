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

namespace Composer\Installer;

use Composer\IO\IOInterface;
use Composer\IO\ConsoleIO;
use Composer\Package\PackageInterface;
use Composer\Package\AliasPackage;
use Composer\Repository\InstalledRepositoryInterface;
use Composer\DependencyResolver\Operation\OperationInterface;
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\DependencyResolver\Operation\UpdateOperation;
use Composer\DependencyResolver\Operation\UninstallOperation;
use Composer\DependencyResolver\Operation\MarkAliasInstalledOperation;
use Composer\DependencyResolver\Operation\MarkAliasUninstalledOperation;
use Composer\Downloader\FileDownloader;
use Composer\EventDispatcher\EventDispatcher;
use Composer\Util\Loop;
use Composer\Util\Platform;
use React\Promise\PromiseInterface;
use Seld\Signal\SignalHandler;

/**
 * Package operation manager.
 *
 * @author Konstantin Kudryashov <ever.zet@gmail.com>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 * @author Nils Adermann <naderman@naderman.de>
 */
class InstallationManager
{
    /** @var array<InstallerInterface> */
    private $installers = [];
    /** @var array<string, InstallerInterface> */
    private $cache = [];
    /** @var array<string, array<PackageInterface>> */
    private $notifiablePackages = [];
    /** @var Loop */
    private $loop;
    /** @var IOInterface */
    private $io;
    /** @var ?EventDispatcher */
    private $eventDispatcher;
    /** @var bool */
    private $outputProgress;

    public function __construct(Loop $loop, IOInterface $io, ?EventDispatcher $eventDispatcher = null)
    {
        $this->loop = $loop;
        $this->io = $io;
        $this->eventDispatcher = $eventDispatcher;
    }

    public function reset(): void
    {
        $this->notifiablePackages = [];
        FileDownloader::$downloadMetadata = [];
    }

    /**
     * Adds installer
     *
     * @param InstallerInterface $installer installer instance
     */
    public function addInstaller(InstallerInterface $installer): void
    {
        array_unshift($this->installers, $installer);
        $this->cache = [];
    }

    /**
     * Removes installer
     *
     * @param InstallerInterface $installer installer instance
     */
    public function removeInstaller(InstallerInterface $installer): void
    {
        if (false !== ($key = array_search($installer, $this->installers, true))) {
            array_splice($this->installers, $key, 1);
            $this->cache = [];
        }
    }

    /**
     * Disables plugins.
     *
     * We prevent any plugins from being instantiated by simply
     * deactivating the installer for them. This ensure that no third-party
     * code is ever executed.
     */
    public function disablePlugins(): void
    {
        foreach ($this->installers as $i => $installer) {
            if (!$installer instanceof PluginInstaller) {
                continue;
            }

            unset($this->installers[$i]);
        }
    }

    /**
     * Returns installer for a specific package type.
     *
     * @param string $type package type
     *
     * @throws \InvalidArgumentException if installer for provided type is not registered
     */
    public function getInstaller(string $type): InstallerInterface
    {
        $type = strtolower($type);

        if (isset($this->cache[$type])) {
            return $this->cache[$type];
        }

        foreach ($this->installers as $installer) {
            if ($installer->supports($type)) {
                return $this->cache[$type] = $installer;
            }
        }

        throw new \InvalidArgumentException('Unknown installer type: '.$type);
    }

    /**
     * Checks whether provided package is installed in one of the registered installers.
     *
     * @param InstalledRepositoryInterface $repo    repository in which to check
     * @param PackageInterface             $package package instance
     */
    public function isPackageInstalled(InstalledRepositoryInterface $repo, PackageInterface $package): bool
    {
        if ($package instanceof AliasPackage) {
            return $repo->hasPackage($package) && $this->isPackageInstalled($repo, $package->getAliasOf());
        }

        return $this->getInstaller($package->getType())->isInstalled($repo, $package);
    }

    /**
     * Install binary for the given package.
     * If the installer associated to this package doesn't handle that function, it'll do nothing.
     *
     * @param PackageInterface $package Package instance
     */
    public function ensureBinariesPresence(PackageInterface $package): void
    {
        try {
            $installer = $this->getInstaller($package->getType());
        } catch (\InvalidArgumentException $e) {
            // no installer found for the current package type (@see `getInstaller()`)
            return;
        }

        // if the given installer support installing binaries
        if ($installer instanceof BinaryPresenceInterface) {
            $installer->ensureBinariesPresence($package);
        }
    }

    /**
     * Executes solver operation.
     *
     * @param InstalledRepositoryInterface $repo         repository in which to add/remove/update packages
     * @param OperationInterface[]         $operations   operations to execute
     * @param bool                         $devMode      whether the install is being run in dev mode
     * @param bool                         $runScripts   whether to dispatch script events
     * @param bool                         $downloadOnly whether to only download packages
     */
    public function execute(InstalledRepositoryInterface $repo, array $operations, bool $devMode = true, bool $runScripts = true, bool $downloadOnly = false): void
    {
        /** @var array<callable(): ?PromiseInterface> */
        $cleanupPromises = [];

        $signalHandler = SignalHandler::create([SignalHandler::SIGINT, SignalHandler::SIGTERM, SignalHandler::SIGHUP], function (string $signal, SignalHandler $handler) use (&$cleanupPromises) {
            $this->io->writeError('Received '.$signal.', aborting', true, IOInterface::DEBUG);
            $this->runCleanup($cleanupPromises);
            $handler->exitWithLastSignal();
        });

        try {
            // execute operations in batches to make sure download-modifying-plugins are installed
            // before the other packages get downloaded
            $batches = [];
            $batch = [];
            foreach ($operations as $index => $operation) {
                if ($operation instanceof UpdateOperation || $operation instanceof InstallOperation) {
                    $package = $operation instanceof UpdateOperation ? $operation->getTargetPackage() : $operation->getPackage();
                    if ($package->getType() === 'composer-plugin' && ($extra = $package->getExtra()) && isset($extra['plugin-modifies-downloads']) && $extra['plugin-modifies-downloads'] === true) {
                        if ($batch) {
                            $batches[] = $batch;
                        }
                        $batches[] = [$index => $operation];
                        $batch = [];

                        continue;
                    }
                }
                $batch[$index] = $operation;
            }

            if ($batch) {
                $batches[] = $batch;
            }

            foreach ($batches as $batch) {
                $this->downloadAndExecuteBatch($repo, $batch, $cleanupPromises, $devMode, $runScripts, $downloadOnly, $operations);
            }
        } catch (\Exception $e) {
            $this->runCleanup($cleanupPromises);

            throw $e;
        } finally {
            $signalHandler->unregister();
        }

        if ($downloadOnly) {
            return;
        }

        // do a last write so that we write the repository even if nothing changed
        // as that can trigger an update of some files like InstalledVersions.php if
        // running a new composer version
        $repo->write($devMode, $this);
    }

    /**
     * @param OperationInterface[] $operations    List of operations to execute in this batch
     * @param array<callable(): ?PromiseInterface> $cleanupPromises
     * @param OperationInterface[] $allOperations Complete list of operations to be executed in the install job, used for event listeners
     */
    private function downloadAndExecuteBatch(InstalledRepositoryInterface $repo, array $operations, array &$cleanupPromises, bool $devMode, bool $runScripts, bool $downloadOnly, array $allOperations): void
    {
        $promises = [];

        foreach ($operations as $index => $operation) {
            $opType = $operation->getOperationType();

            // ignoring alias ops as they don't need to execute anything at this stage
            if (!in_array($opType, ['update', 'install', 'uninstall'])) {
                continue;
            }

            if ($opType === 'update') {
                /** @var UpdateOperation $operation */
                $package = $operation->getTargetPackage();
                $initialPackage = $operation->getInitialPackage();
            } else {
                /** @var InstallOperation|MarkAliasInstalledOperation|MarkAliasUninstalledOperation|UninstallOperation $operation */
                $package = $operation->getPackage();
                $initialPackage = null;
            }
            $installer = $this->getInstaller($package->getType());

            $cleanupPromises[$index] = static function () use ($opType, $installer, $package, $initialPackage): ?PromiseInterface {
                // avoid calling cleanup if the download was not even initialized for a package
                // as without installation source configured nothing will work
                if (!$package->getInstallationSource()) {
                    return \React\Promise\resolve(null);
                }

                return $installer->cleanup($opType, $package, $initialPackage);
            };

            if ($opType !== 'uninstall') {
                $promise = $installer->download($package, $initialPackage);
                if ($promise) {
                    $promises[] = $promise;
                }
            }
        }

        // execute all downloads first
        if (count($promises)) {
            $this->waitOnPromises($promises);
        }

        if ($downloadOnly) {
            $this->runCleanup($cleanupPromises);
            return;
        }

        // execute operations in batches to make sure every plugin is installed in the
        // right order and activated before the packages depending on it are installed
        $batches = [];
        $batch = [];
        foreach ($operations as $index => $operation) {
            if ($operation instanceof InstallOperation || $operation instanceof UpdateOperation) {
                $package = $operation instanceof UpdateOperation ? $operation->getTargetPackage() : $operation->getPackage();
                if ($package->getType() === 'composer-plugin' || $package->getType() === 'composer-installer') {
                    if ($batch) {
                        $batches[] = $batch;
                    }
                    $batches[] = [$index => $operation];
                    $batch = [];

                    continue;
                }
            }
            $batch[$index] = $operation;
        }

        if ($batch) {
            $batches[] = $batch;
        }

        foreach ($batches as $batch) {
            $this->executeBatch($repo, $batch, $cleanupPromises, $devMode, $runScripts, $allOperations);
        }
    }

    /**
     * @param OperationInterface[] $operations    List of operations to execute in this batch
     * @param array<callable(): ?PromiseInterface> $cleanupPromises
     * @param OperationInterface[] $allOperations Complete list of operations to be executed in the install job, used for event listeners
     */
    private function executeBatch(InstalledRepositoryInterface $repo, array $operations, array $cleanupPromises, bool $devMode, bool $runScripts, array $allOperations): void
    {
        $promises = [];
        $postExecCallbacks = [];

        foreach ($operations as $index => $operation) {
            $opType = $operation->getOperationType();

            // ignoring alias ops as they don't need to execute anything
            if (!in_array($opType, ['update', 'install', 'uninstall'])) {
                // output alias ops in debug verbosity as they have no output otherwise
                if ($this->io->isDebug()) {
                    $this->io->writeError('  - ' . $operation->show(false));
                }
                $this->{$opType}($repo, $operation);

                continue;
            }

            if ($opType === 'update') {
                /** @var UpdateOperation $operation */
                $package = $operation->getTargetPackage();
                $initialPackage = $operation->getInitialPackage();
            } else {
                /** @var InstallOperation|MarkAliasInstalledOperation|MarkAliasUninstalledOperation|UninstallOperation $operation */
                $package = $operation->getPackage();
                $initialPackage = null;
            }

            $installer = $this->getInstaller($package->getType());

            $eventName = [
                'install' => PackageEvents::PRE_PACKAGE_INSTALL,
                'update' => PackageEvents::PRE_PACKAGE_UPDATE,
                'uninstall' => PackageEvents::PRE_PACKAGE_UNINSTALL,
            ][$opType] ?? null;

            if (null !== $eventName && $runScripts && $this->eventDispatcher) {
                $this->eventDispatcher->dispatchPackageEvent($eventName, $devMode, $repo, $allOperations, $operation);
            }

            $dispatcher = $this->eventDispatcher;
            $io = $this->io;

            $promise = $installer->prepare($opType, $package, $initialPackage);
            if (!$promise instanceof PromiseInterface) {
                $promise = \React\Promise\resolve(null);
            }

            $promise = $promise->then(function () use ($opType, $repo, $operation) {
                return $this->{$opType}($repo, $operation);
            })->then($cleanupPromises[$index])
            ->then(function () use ($devMode, $repo): void {
                $repo->write($devMode, $this);
            }, static function ($e) use ($opType, $package, $io): void {
                $io->writeError('    <error>' . ucfirst($opType) .' of '.$package->getPrettyName().' failed</error>');

                throw $e;
            });

            $eventName = [
                'install' => PackageEvents::POST_PACKAGE_INSTALL,
                'update' => PackageEvents::POST_PACKAGE_UPDATE,
                'uninstall' => PackageEvents::POST_PACKAGE_UNINSTALL,
            ][$opType] ?? null;

            if (null !== $eventName && $runScripts && $dispatcher) {
                $postExecCallbacks[] = static function () use ($dispatcher, $eventName, $devMode, $repo, $allOperations, $operation): void {
                    $dispatcher->dispatchPackageEvent($eventName, $devMode, $repo, $allOperations, $operation);
                };
            }

            $promises[] = $promise;
        }

        // execute all prepare => installs/updates/removes => cleanup steps
        if (count($promises)) {
            $this->waitOnPromises($promises);
        }

        Platform::workaroundFilesystemIssues();

        foreach ($postExecCallbacks as $cb) {
            $cb();
        }
    }

    /**
     * @param PromiseInterface[] $promises
     */
    private function waitOnPromises(array $promises): void
    {
        $progress = null;
        if (
            $this->outputProgress
            && $this->io instanceof ConsoleIO
            && !Platform::getEnv('CI')
            && !$this->io->isDebug()
            && count($promises) > 1
        ) {
            $progress = $this->io->getProgressBar();
        }
        $this->loop->wait($promises, $progress);
        if ($progress) {
            $progress->clear();
            // ProgressBar in non-decorated output does not output a final line-break and clear() does nothing
            if (!$this->io->isDecorated()) {
                $this->io->writeError('');
            }
        }
    }

    /**
     * Executes download operation.
     *
     * $param PackageInterface $package
     */
    public function download(PackageInterface $package): ?PromiseInterface
    {
        $installer = $this->getInstaller($package->getType());
        $promise = $installer->cleanup("install", $package);

        return $promise;
    }

    /**
     * Executes install operation.
     *
     * @param InstalledRepositoryInterface $repo      repository in which to check
     * @param InstallOperation             $operation operation instance
     */
    public function install(InstalledRepositoryInterface $repo, InstallOperation $operation): ?PromiseInterface
    {
        $package = $operation->getPackage();
        $installer = $this->getInstaller($package->getType());
        $promise = $installer->install($repo, $package);
        $this->markForNotification($package);

        return $promise;
    }

    /**
     * Executes update operation.
     *
     * @param InstalledRepositoryInterface $repo      repository in which to check
     * @param UpdateOperation              $operation operation instance
     */
    public function update(InstalledRepositoryInterface $repo, UpdateOperation $operation): ?PromiseInterface
    {
        $initial = $operation->getInitialPackage();
        $target = $operation->getTargetPackage();

        $initialType = $initial->getType();
        $targetType = $target->getType();

        if ($initialType === $targetType) {
            $installer = $this->getInstaller($initialType);
            $promise = $installer->update($repo, $initial, $target);
            $this->markForNotification($target);
        } else {
            $promise = $this->getInstaller($initialType)->uninstall($repo, $initial);
            if (!$promise instanceof PromiseInterface) {
                $promise = \React\Promise\resolve(null);
            }

            $installer = $this->getInstaller($targetType);
            $promise = $promise->then(static function () use ($installer, $repo, $target): PromiseInterface {
                $promise = $installer->install($repo, $target);
                if ($promise instanceof PromiseInterface) {
                    return $promise;
                }

                return \React\Promise\resolve(null);
            });
        }

        return $promise;
    }

    /**
     * Uninstalls package.
     *
     * @param InstalledRepositoryInterface $repo      repository in which to check
     * @param UninstallOperation           $operation operation instance
     */
    public function uninstall(InstalledRepositoryInterface $repo, UninstallOperation $operation): ?PromiseInterface
    {
        $package = $operation->getPackage();
        $installer = $this->getInstaller($package->getType());

        return $installer->uninstall($repo, $package);
    }

    /**
     * Executes markAliasInstalled operation.
     *
     * @param InstalledRepositoryInterface $repo      repository in which to check
     * @param MarkAliasInstalledOperation  $operation operation instance
     */
    public function markAliasInstalled(InstalledRepositoryInterface $repo, MarkAliasInstalledOperation $operation): void
    {
        $package = $operation->getPackage();

        if (!$repo->hasPackage($package)) {
            $repo->addPackage(clone $package);
        }
    }

    /**
     * Executes markAlias operation.
     *
     * @param InstalledRepositoryInterface  $repo      repository in which to check
     * @param MarkAliasUninstalledOperation $operation operation instance
     */
    public function markAliasUninstalled(InstalledRepositoryInterface $repo, MarkAliasUninstalledOperation $operation): void
    {
        $package = $operation->getPackage();

        $repo->removePackage($package);
    }

    /**
     * Returns the installation path of a package
     *
     * @return string|null absolute path to install to, which does not end with a slash, or null if the package does not have anything installed on disk
     */
    public function getInstallPath(PackageInterface $package): ?string
    {
        $installer = $this->getInstaller($package->getType());

        return $installer->getInstallPath($package);
    }

    public function setOutputProgress(bool $outputProgress): void
    {
        $this->outputProgress = $outputProgress;
    }

    public function notifyInstalls(IOInterface $io): void
    {
        $promises = [];

        try {
            foreach ($this->notifiablePackages as $repoUrl => $packages) {
                // non-batch API, deprecated
                if (strpos($repoUrl, '%package%')) {
                    foreach ($packages as $package) {
                        $url = str_replace('%package%', $package->getPrettyName(), $repoUrl);

                        $params = [
                            'version' => $package->getPrettyVersion(),
                            'version_normalized' => $package->getVersion(),
                        ];
                        $opts = [
                            'retry-auth-failure' => false,
                            'http' => [
                                'method' => 'POST',
                                'header' => ['Content-type: application/x-www-form-urlencoded'],
                                'content' => http_build_query($params, '', '&'),
                                'timeout' => 3,
                            ],
                        ];

                        $promises[] = $this->loop->getHttpDownloader()->add($url, $opts);
                    }

                    continue;
                }

                $postData = ['downloads' => []];
                foreach ($packages as $package) {
                    $packageNotification = [
                        'name' => $package->getPrettyName(),
                        'version' => $package->getVersion(),
                    ];
                    if (strpos($repoUrl, 'packagist.org/') !== false) {
                        if (isset(FileDownloader::$downloadMetadata[$package->getName()])) {
                            $packageNotification['downloaded'] = FileDownloader::$downloadMetadata[$package->getName()];
                        } else {
                            $packageNotification['downloaded'] = false;
                        }
                    }
                    $postData['downloads'][] = $packageNotification;
                }

                $opts = [
                    'retry-auth-failure' => false,
                    'http' => [
                        'method' => 'POST',
                        'header' => ['Content-Type: application/json'],
                        'content' => json_encode($postData),
                        'timeout' => 6,
                    ],
                ];

                $promises[] = $this->loop->getHttpDownloader()->add($repoUrl, $opts);
            }

            $this->loop->wait($promises);
        } catch (\Exception $e) {
        }

        $this->reset();
    }

    private function markForNotification(PackageInterface $package): void
    {
        if ($package->getNotificationUrl()) {
            $this->notifiablePackages[$package->getNotificationUrl()][$package->getName()] = $package;
        }
    }

    /**
     * @param array<callable(): ?PromiseInterface> $cleanupPromises
     * @return void
     */
    private function runCleanup(array $cleanupPromises): void
    {
        $promises = [];

        $this->loop->abortJobs();

        foreach ($cleanupPromises as $cleanup) {
            $promises[] = new \React\Promise\Promise(static function ($resolve, $reject) use ($cleanup): void {
                $promise = $cleanup();
                if (!$promise instanceof PromiseInterface) {
                    $resolve();
                } else {
                    $promise->then(static function () use ($resolve): void {
                        $resolve();
                    });
                }
            });
        }

        if (!empty($promises)) {
            $this->loop->wait($promises);
        }
    }
}
