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
use Composer\Util\StreamContextFactory;

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
     * Executes solver operation.
     *
     * @param RepositoryInterface $repo      repository in which to check
     * @param OperationInterface  $operation operation instance
     */
    public function execute(RepositoryInterface $repo, OperationInterface $operation)
    {
        $method = $operation->getJobType();
        $this->$method($repo, $operation);
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
        $installer->install($repo, $package);
        $this->markForNotification($package);
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
        $targetType  = $target->getType();

        if ($initialType === $targetType) {
            $installer = $this->getInstaller($initialType);
            $installer->update($repo, $initial, $target);
            $this->markForNotification($target);
        } else {
            $this->getInstaller($initialType)->uninstall($repo, $initial);
            $this->getInstaller($targetType)->install($repo, $target);
        }
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
        $installer->uninstall($repo, $package);
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
                            'method'  => 'POST',
                            'header'  => array('Content-type: application/x-www-form-urlencoded'),
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
                    'method'  => 'POST',
                    'header'  => array('Content-Type: application/json'),
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
