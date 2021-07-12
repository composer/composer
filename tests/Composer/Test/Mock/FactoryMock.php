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

namespace Composer\Test\Mock;

use Composer\Composer;
use Composer\Config;
use Composer\Factory;
use Composer\Repository\RepositoryManager;
use Composer\Repository\WritableRepositoryInterface;
use Composer\Package\Version\VersionGuesser;
use Composer\Package\Version\VersionParser;
use Composer\Package\RootPackageInterface;
use Composer\Installer;
use Composer\EventDispatcher\EventDispatcher;
use Composer\IO\IOInterface;
use Composer\Test\TestCase;
use Composer\Util\Loop;
use Composer\Util\ProcessExecutor;

class FactoryMock extends Factory
{
    public static function createConfig(IOInterface $io = null, $cwd = null)
    {
        $config = new Config(true, $cwd);

        $config->merge(array(
            'config' => array('home' => TestCase::getUniqueTmpDirectory()),
            'repositories' => array('packagist' => false),
        ));

        return $config;
    }

    protected function loadRootPackage(RepositoryManager $rm, Config $config, VersionParser $parser, VersionGuesser $guesser, IOInterface $io)
    {
        return new \Composer\Package\Loader\RootPackageLoader($rm, $config, $parser, new VersionGuesserMock(), $io);
    }

    protected function addLocalRepository(IOInterface $io, RepositoryManager $rm, $vendorDir, RootPackageInterface $rootPackage, ProcessExecutor $process = null)
    {
    }

    public function createInstallationManager(Loop $loop, IOInterface $io, EventDispatcher $dispatcher = null)
    {
        return new InstallationManagerMock();
    }

    protected function createDefaultInstallers(Installer\InstallationManager $im, Composer $composer, IOInterface $io, ProcessExecutor $process = null)
    {
    }

    protected function purgePackages(WritableRepositoryInterface $repo, Installer\InstallationManager $im)
    {
    }
}
