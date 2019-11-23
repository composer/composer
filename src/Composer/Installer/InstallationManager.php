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

namespace Composer\Installer;

use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Package\AliasPackage;
use Composer\Repository\RepositoryInterface;
use Composer\Repository\InstalledRepositoryInterface;
use Composer\DependencyResolver\Operation\OperationInterface;
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\DependencyResolver\Operation\UpdateOperation;
use Composer\DependencyResolver\Operation\UninstallOperation;
use Composer\DependencyResolver\Operation\MarkAliasInstalledOperation;
use Composer\DependencyResolver\Operation\MarkAliasUninstalledOperation;
use Composer\EventDispatcher\EventDispatcher;
use Composer\Util\StreamContextFactory;
use Composer\Util\Loop;

/**
 * Package operation manager.
 *
 * @author Konstantin Kudryashov <ever.zet@gmail.com>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 * @author Nils Adermann <naderman@naderman.de>
 */
class InstallationManager
{
    private $installers = array();
    private $cache = array();
    private $notifiablePackages = array();
    private $loop;
    private $io;
    private $eventDispatcher;

    public function __construct(Loop $loop, IOInterface $io, EventDispatcher $eventDispatcher = null)
    {
        $this->loop = $loop;
        $this->io = $io;
        $this->eventDispatcher = $eventDispatcher;
    }

    public function reset()
    {
        $this->notifiablePackages = array();
    }

    /**
     * Adds installer
     *
     * @param InstallerInterface $installer installer instance
     */
    public function addInstaller(InstallerInterface $installer)
    {
        array_unshift($this->installers, $installer);
        $this->cache = array();
    }

    /**
     * Removes installer
     *
     * @param InstallerInterface $installer installer instance
     */
    public function removeInstaller(InstallerInterface $installer)
    {
        if (false !== ($key = array_search($installer, $this->installers, true))) {
            array_splice($this->installers, $key, 1);
            $this->cache = array();
        }
    }

    /**
     * Disables plugins.
     *
     * We prevent any plugins from being instantiated by simply
     * deactivating the installer for them. This ensure that no third-party
     * code is ever executed.
     */
    public function disablePlugins()
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
     * @return InstallerInterface
     */
    public function getInstaller($type)
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
     *
     * @return bool
     */
    public function isPackageInstalled(InstalledRepositoryInterface $repo, PackageInterface $package)
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
    public function ensureBinariesPresence(PackageInterface $package)
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
     * @param RepositoryInterface  $repo       repository in which to add/remove/update packages
     * @param OperationInterface[] $operations operations to execute
     * @param bool                 $devMode    whether the install is being run in dev mode
     * @param bool                 $operation  whether to dispatch script events
     */
    public function execute(RepositoryInterface $repo, array $operations, $devMode = true, $runScripts = true)
    {
        $promises = array();

        foreach ($operations as $operation) {
            $jobType = $operation->getJobType();
            $promise = null;

            if ($jobType === 'install') {
                $package = $operation->getPackage();
                $installer = $this->getInstaller($package->getType());
                $promise = $installer->download($package);
            } elseif ($jobType === 'update') {
                $target = $operation->getTargetPackage();
                $targetType = $target->getType();
                $installer = $this->getInstaller($targetType);
                $promise = $installer->download($target, $operation->getInitialPackage());
            }

            if ($promise) {
                $promises[] = $promise;
            }
        }

        if (!empty($promises)) {
            $this->loop->wait($promises);
        }

        foreach ($operations as $operation) {
            $jobType = $operation->getJobType();

            // ignoring alias ops as they don't need to execute anything
            if (!in_array($jobType, array('update', 'install', 'uninstall'))) {
                // output alias ops in debug verbosity as they have no output otherwise
                if ($this->io->isDebug()) {
                    $this->io->writeError('  - ' . $operation->show(false));
                }
                continue;
            }

            if ($jobType === 'install' || $jobType === 'uninstall') {
                $package = $operation->getPackage();
                $initialPackage = null;
            } elseif ($jobType === 'update') {
                $package = $operation->getTargetPackage();
                $initialPackage = $operation->getInitialPackage();
            }
            $installer = $this->getInstaller($package->getType());

            $event = 'Composer\Installer\PackageEvents::PRE_PACKAGE_'.strtoupper($jobType);
            if (defined($event) && $runScripts && $this->eventDispatcher) {
                $this->eventDispatcher->dispatchPackageEvent(constant($event), $devMode, $repo, $operations, $operation);
            }

            $dispatcher = $this->eventDispatcher;
            $installManager = $this;
            $loop = $this->loop;
            $io = $this->io;

            $promise = new \React\Promise\Promise(function ($resolve, $reject) use ($installer, $jobType, $package, $initialPackage) {
                $promise = $installer->prepare($jobType, $package, $initialPackage);
                if (null === $promise) {
                    $promise = new \React\Promise\Promise(function ($resolve, $reject) { $resolve(); });
                }

                return $promise->then($resolve, $reject);
            });

            $promise = $promise->then(function () use ($jobType, $installManager, $repo, $operation) {
                $promise = $installManager->$jobType($repo, $operation);
                if (null === $promise) {
                    $promise = new \React\Promise\Promise(function ($resolve, $reject) { $resolve(); });
                }

                return $promise;
            })->then(function () use ($jobType, $installer, $package, $initialPackage) {
                $promise = $installer->cleanup($jobType, $package, $initialPackage);
                if (null === $promise) {
                    $promise = new \React\Promise\Promise(function ($resolve, $reject) { $resolve(); });
                }

                return $promise;
            })->then(function () use ($jobType, $installer, $package, $initialPackage, $runScripts, $dispatcher, $installManager, $devMode, $repo, $operations, $operation) {
                $repo->write($devMode, $installManager);

                $event = 'Composer\Installer\PackageEvents::POST_PACKAGE_'.strtoupper($jobType);
                if (defined($event) && $runScripts && $dispatcher) {
                    $dispatcher->dispatchPackageEvent(constant($event), $devMode, $repo, $operations, $operation);
                }
            }, function ($e) use ($jobType, $installer, $package, $initialPackage, $loop, $io) {
                $this->io->writeError('    <error>' . ucfirst($jobType) .' of '.$package->getPrettyName().' failed</error>');

                $promise = $installer->cleanup($jobType, $package, $initialPackage);
                $loop->wait(array($promise));

                throw $e;
            });

            $promises[] = $promise;
        }

        if (!empty($promises)) {
            $this->loop->wait($promises);
        }
    }

    /**
     * Executes install operation.
     *
     * @param RepositoryInterface $repo      repository in which to check
     * @param InstallOperation    $operation operation instance
     */
    public function install(RepositoryInterface $repo, InstallOperation $operation)
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
     * @param RepositoryInterface $repo      repository in which to check
     * @param UpdateOperation     $operation operation instance
     */
    public function update(RepositoryInterface $repo, UpdateOperation $operation)
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
            $this->getInstaller($initialType)->uninstall($repo, $initial);
            $installer = $this->getInstaller($targetType);
            $promise = $installer->install($repo, $target);
        }

        return $promise;
    }

    /**
     * Uninstalls package.
     *
     * @param RepositoryInterface $repo      repository in which to check
     * @param UninstallOperation  $operation operation instance
     */
    public function uninstall(RepositoryInterface $repo, UninstallOperation $operation)
    {
        $package = $operation->getPackage();
        $installer = $this->getInstaller($package->getType());

        return $installer->uninstall($repo, $package);
    }

    /**
     * Executes markAliasInstalled operation.
     *
     * @param RepositoryInterface         $repo      repository in which to check
     * @param MarkAliasInstalledOperation $operation operation instance
     */
    public function markAliasInstalled(RepositoryInterface $repo, MarkAliasInstalledOperation $operation)
    {
        $package = $operation->getPackage();

        if (!$repo->hasPackage($package)) {
            $repo->addPackage(clone $package);
        }
    }

    /**
     * Executes markAlias operation.
     *
     * @param RepositoryInterface           $repo      repository in which to check
     * @param MarkAliasUninstalledOperation $operation operation instance
     */
    public function markAliasUninstalled(RepositoryInterface $repo, MarkAliasUninstalledOperation $operation)
    {
        $package = $operation->getPackage();

        $repo->removePackage($package);
    }

    /**
     * Returns the installation path of a package
     *
     * @param  PackageInterface $package
     * @return string           path
     */
    public function getInstallPath(PackageInterface $package)
    {
        $installer = $this->getInstaller($package->getType());

        return $installer->getInstallPath($package);
    }

    public function notifyInstalls(IOInterface $io)
    {
        foreach ($this->notifiablePackages as $repoUrl => $packages) {
            $repositoryName = parse_url($repoUrl, PHP_URL_HOST);
            if ($io->hasAuthentication($repositoryName)) {
                $auth = $io->getAuthentication($repositoryName);
                $authStr = base64_encode($auth['username'] . ':' . $auth['password']);
                $authHeader = 'Authorization: Basic '.$authStr;
            }

            // non-batch API, deprecated
            if (strpos($repoUrl, '%package%')) {
                foreach ($packages as $package) {
                    $url = str_replace('%package%', $package->getPrettyName(), $repoUrl);

                    $params = array(
                        'version' => $package->getPrettyVersion(),
                        'version_normalized' => $package->getVersion(),
                    );
                    $opts = array('http' =>
                        array(
                            'method' => 'POST',
                            'header' => array('Content-type: application/x-www-form-urlencoded'),
                            'content' => http_build_query($params, '', '&'),
                            'timeout' => 3,
                        ),
                    );
                    if (isset($authHeader)) {
                        $opts['http']['header'][] = $authHeader;
                    }

                    $context = StreamContextFactory::getContext($url, $opts);
                    @file_get_contents($url, false, $context);
                }

                continue;
            }

            $postData = array('downloads' => array());
            foreach ($packages as $package) {
                $postData['downloads'][] = array(
                    'name' => $package->getPrettyName(),
                    'version' => $package->getVersion(),
                );
            }

            $opts = array('http' =>
                array(
                    'method' => 'POST',
                    'header' => array('Content-Type: application/json'),
                    'content' => json_encode($postData),
                    'timeout' => 6,
                ),
            );
            if (isset($authHeader)) {
                $opts['http']['header'][] = $authHeader;
            }

            $context = StreamContextFactory::getContext($repoUrl, $opts);
            @file_get_contents($repoUrl, false, $context);
        }

        $this->reset();
    }

    private function markForNotification(PackageInterface $package)
    {
        if ($package->getNotificationUrl()) {
            $this->notifiablePackages[$package->getNotificationUrl()][$package->getName()] = $package;
        }
    }
}
