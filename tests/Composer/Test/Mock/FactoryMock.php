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

namespace Composer\Test\Mock;

use Composer\Installer\InstallationManager;
use Composer\Package\Loader\RootPackageLoader;
use Composer\Config;
use Composer\Factory;
use Composer\PartialComposer;
use Composer\Repository\RepositoryManager;
use Composer\Package\Version\VersionGuesser;
use Composer\Package\Version\VersionParser;
use Composer\Package\RootPackageInterface;
use Composer\EventDispatcher\EventDispatcher;
use Composer\IO\IOInterface;
use Composer\Repository\InstalledArrayRepository;
use Composer\Repository\InstalledRepositoryInterface;
use Composer\Test\TestCase;
use Composer\Util\Loop;
use Composer\Util\ProcessExecutor;

class FactoryMock extends Factory
{
    public static function createConfig(?IOInterface $io = null, ?string $cwd = null): Config
    {
        $config = new Config(true, $cwd);

        $config->merge([
            'config' => ['home' => TestCase::getUniqueTmpDirectory()],
            'repositories' => ['packagist' => false],
        ]);

        return $config;
    }

    protected function loadRootPackage(RepositoryManager $rm, Config $config, VersionParser $parser, VersionGuesser $guesser, IOInterface $io): RootPackageLoader
    {
        return new \Composer\Package\Loader\RootPackageLoader($rm, $config, $parser, new VersionGuesserMock(), $io);
    }

    protected function addLocalRepository(IOInterface $io, RepositoryManager $rm, string $vendorDir, RootPackageInterface $rootPackage, ?ProcessExecutor $process = null): void
    {
        $rm->setLocalRepository(new InstalledArrayRepository);
    }

    public function createInstallationManager(?Loop $loop = null, ?IOInterface $io = null, ?EventDispatcher $dispatcher = null): InstallationManager
    {
        return new InstallationManagerMock();
    }

    protected function createDefaultInstallers(InstallationManager $im, PartialComposer $composer, IOInterface $io, ?ProcessExecutor $process = null): void
    {
    }

    protected function purgePackages(InstalledRepositoryInterface $repo, InstallationManager $im): void
    {
    }
}
