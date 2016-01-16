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
use Composer\Installer;
use Composer\IO\IOInterface;

class FactoryMock extends Factory
{
    public static function createConfig(IOInterface $io = null, $cwd = null)
    {
        $config = new Config(true, $cwd);

        $config->merge(array(
            'config' => array('home' => sys_get_temp_dir().'/composer-test'),
            'repositories' => array('packagist' => false),
        ));

        return $config;
    }

    protected function addLocalRepository(IOInterface $io, RepositoryManager $rm, $vendorDir)
    {
    }

    protected function createInstallationManager()
    {
        return new InstallationManagerMock;
    }

    protected function createDefaultInstallers(Installer\InstallationManager $im, Composer $composer, IOInterface $io)
    {
    }

    protected function purgePackages(WritableRepositoryInterface $repo, Installer\InstallationManager $im)
    {
    }
}
