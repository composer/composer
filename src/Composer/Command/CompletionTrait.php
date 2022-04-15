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

namespace Composer\Command;

use Composer\Composer;
use Composer\Package\PackageInterface;
use Composer\Repository\CompositeRepository;
use Composer\Repository\InstalledRepository;
use Composer\Repository\PlatformRepository;
use Composer\Repository\RepositoryInterface;
use Composer\Repository\RootPackageRepository;
use Symfony\Component\Console\Completion\CompletionInput;

/**
 * Adds completion to arguments and options.
 *
 * @internal
 */
trait CompletionTrait
{
    /**
     * @see BaseCommand::requireComposer()
     */
    abstract public function requireComposer(bool $disablePlugins = null, bool $disableScripts = null): Composer;

    /**
     * Suggestion values for "prefer-install" option
     *
     * @return string[]
     */
    private function suggestPreferInstall(): array
    {
        return ['dist', 'source', 'auto'];
    }

    /**
     * Suggest package names from installed.
     */
    private function suggestInstalledPackage(): \Closure
    {
        return function (): array {
            $composer = $this->requireComposer();
            $installedRepos = [new RootPackageRepository(clone $composer->getPackage())];

            $locker = $composer->getLocker();
            if ($locker->isLocked()) {
                $installedRepos[] = $locker->getLockedRepository(true);
            } else {
                $installedRepos[] = $composer->getRepositoryManager()->getLocalRepository();
            }

            $installedRepo = new InstalledRepository($installedRepos);

            return array_map(function (PackageInterface $package) {
                return $package->getName();
            }, $installedRepo->getPackages());
        };
    }

    /**
     * Suggest package names available on all configured repositories.
     * @todo rework to list packages from cache
     */
    private function suggestAvailablePackage(): \Closure
    {
        return function (CompletionInput $input) {
            $composer = $this->requireComposer();
            $repos = new CompositeRepository($composer->getRepositoryManager()->getRepositories());

            $packages = $repos->search('^' . preg_quote($input->getCompletionValue()), RepositoryInterface::SEARCH_NAME);

            return array_column(array_slice($packages, 0, 150), 'name');
        };
    }

    /**
     * Suggest package names available on all configured repositories or
     * ext- packages from the ones available on the currently-running PHP
     */
    private function suggestAvailablePackageOrExtension(): \Closure
    {
        return function (CompletionInput $input) {
            if (!str_starts_with($input->getCompletionValue(), 'ext-')) {
                return $this->suggestAvailablePackage()($input);
            }

            $repos = new PlatformRepository([], $this->requireComposer()->getConfig()->get('platform') ?? []);

            return array_map(function (PackageInterface $package) {
                return $package->getName();
            }, $repos->getPackages());
        };
    }
}
