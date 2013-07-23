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

use Composer\Composer;
use Composer\Package\Package;
use Composer\IO\IOInterface;
use Composer\Repository\InstalledRepositoryInterface;
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
     * Initializes Installer installer.
     *
     * @param IOInterface $io
     * @param Composer    $composer
     * @param string      $type
     */
    public function __construct(IOInterface $io, Composer $composer, $type = 'library')
    {
        parent::__construct($io, $composer, 'composer-installer');
        $this->installationManager = $composer->getInstallationManager();

        $repo = $composer->getRepositoryManager()->getLocalRepository();
        foreach ($repo->getPackages() as $package) {
            if ('composer-installer' === $package->getType()) {
                $this->registerInstaller($package);
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
        $classes = is_array($extra['class']) ? $extra['class'] : array($extra['class']);

        $generator = $this->composer->getAutoloadGenerator();
        $map = $generator->parseAutoloads(array(array($package, $downloadPath)), new Package('dummy', '1.0.0.0', '1.0.0'));
        $classLoader = $generator->createLoader($map);
        $classLoader->register();

        foreach ($classes as $class) {
            if (class_exists($class, false)) {
                $code = file_get_contents($classLoader->findFile($class));
                $code = preg_replace('{^(\s*)class\s+(\S+)}mi', '$1class $2_composer_tmp'.self::$classCounter, $code);
                eval('?>'.$code);
                $class .= '_composer_tmp'.self::$classCounter;
                self::$classCounter++;
            }

            $installer = new $class($this->io, $this->composer);
            $this->installationManager->addInstaller($installer);
        }
    }
}
