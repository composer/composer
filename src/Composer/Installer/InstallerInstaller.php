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

use Composer\Autoload\AutoloadGenerator;
use Composer\Downloader\DownloadManager;
use Composer\Repository\WritableRepositoryInterface;
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

    /**
     * @param   string                      $dir        relative path for packages home
     * @param   DownloadManager             $dm         download manager
     * @param   WritableRepositoryInterface $repository repository controller
     */
    public function __construct($directory, DownloadManager $dm, WritableRepositoryInterface $repository, InstallationManager $im)
    {
        parent::__construct($directory, $dm, $repository, 'composer-installer');
        $this->installationManager = $im;

        foreach ($repository->getPackages() as $package) {
            $this->registerInstaller($package);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function install(PackageInterface $package)
    {
        $extra = $package->getExtra();
        if (empty($extra['class'])) {
            throw new \UnexpectedValueException('Error while installing '.$target->getPrettyName().', composer-installer packages should have a class defined in their extra key to be usable.')
        }

        parent::install($package);
        $this->registerInstaller($package);
    }

    /**
     * {@inheritDoc}
     */
    public function update(PackageInterface $initial, PackageInterface $target)
    {
        $extra = $target->getExtra();
        if (empty($extra['class'])) {
            throw new \UnexpectedValueException('Error while installing '.$target->getPrettyName().', composer-installer packages should have a class defined in their extra key to be usable.')
        }

        parent::update($initial, $target);
        $this->registerInstaller($target);
    }

    private function registerInstaller(PackageInterface $package)
    {
        $downloadPath = $this->getInstallPath($package);

        $class = $extra['class'];
        if (class_exists($class, false)) {
            $reflClass = new \ReflectionClass($class);
            $code = file_get_contents($reflClass->getFileName());
            $code = preg_replace('{^class (\S+)}mi', 'class $1_composer_tmp', $code);
            eval($code);
            $class .= '_composer_tmp';
        } else {
            $generator = new AutoloadGenerator;
            $map = $generator->parseAutoloads(array($target, $downloadPath));
            $generator->createLoader($map)->register();
        }

        $extra = $package->getExtra();
        $installer = new $class($this->directory, $this->downloadManager, $this->repository);
        $this->installationManager->addInstaller($installer);
    }
}
