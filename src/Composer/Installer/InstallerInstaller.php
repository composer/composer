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
use Composer\Autoload\AutoloadGenerator;
use Composer\Downloader\DownloadManager;
use Composer\Repository\InstalledRepositoryInterface;
use Composer\DependencyResolver\Operation\OperationInterface;
use Composer\Package\PackageInterface;

/**
 * Installer installation manager.
 *
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class InstallerInstaller extends LibraryInstaller
{
    private $installationManager;
    private static $classCounter = 0;

    /**
     * @param   string                      $vendorDir  relative path for packages home
     * @param   string                      $binDir     relative path for binaries
     * @param   DownloadManager             $dm         download manager
     * @param   IOInterface                 $io         io instance
     * @param   InstallationManager         $im         installation manager
     * @param   array                       $localRepositories array of InstalledRepositoryInterface
     */
    public function __construct($vendorDir, $binDir, DownloadManager $dm, IOInterface $io, InstallationManager $im, array $localRepositories)
    {
        parent::__construct($vendorDir, $binDir, $dm, $io, 'composer-installer');
        $this->installationManager = $im;

        foreach ($localRepositories as $repo) {
            foreach ($repo->getPackages() as $package) {
                if ('composer-installer' === $package->getType()) {
                    $this->registerInstaller($package);
                }
            }
        }
    }

    /**
     * {@inheritDoc}
     */
    public function install(InstalledRepositoryInterface $repo, PackageInterface $package)
    {
        $extra = $package->getExtra();
        if (empty($extra['class'])) {
            throw new \UnexpectedValueException('Error while installing '.$package->getPrettyName().', composer-installer packages should have a class defined in their extra key to be usable.');
        }

        parent::install($repo, $package);
        $this->registerInstaller($package);
    }

    /**
     * {@inheritDoc}
     */
    public function update(InstalledRepositoryInterface $repo, PackageInterface $initial, PackageInterface $target)
    {
        $extra = $target->getExtra();
        if (empty($extra['class'])) {
            throw new \UnexpectedValueException('Error while installing '.$target->getPrettyName().', composer-installer packages should have a class defined in their extra key to be usable.');
        }

        parent::update($repo, $initial, $target);
        $this->registerInstaller($target);
    }

    private function registerInstaller(PackageInterface $package)
    {
        $downloadPath = $this->getInstallPath($package);

        $extra = $package->getExtra();
        $class = $extra['class'];

        $generator = new AutoloadGenerator;
        $map = $generator->parseAutoloads(array(array($package, $downloadPath)));
        $classLoader = $generator->createLoader($map);
        $classLoader->register();

        if (class_exists($class, false)) {
            $code = file_get_contents($classLoader->findFile($class));
            $code = preg_replace('{^class\s+(\S+)}mi', 'class $1_composer_tmp'.self::$classCounter, $code);
            eval('?>'.$code);
            $class .= '_composer_tmp'.self::$classCounter;
            self::$classCounter++;
        }

        $extra = $package->getExtra();
        $installer = new $class($this->vendorDir, $this->binDir, $this->downloadManager, $this->io);
        $this->installationManager->addInstaller($installer);
    }
}
