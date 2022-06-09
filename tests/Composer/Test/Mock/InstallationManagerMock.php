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

use React\Promise\PromiseInterface;
use Composer\Installer\InstallationManager;
use Composer\Repository\InstalledRepositoryInterface;
use Composer\Package\PackageInterface;
use Composer\IO\IOInterface;
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\DependencyResolver\Operation\UpdateOperation;
use Composer\DependencyResolver\Operation\UninstallOperation;
use Composer\DependencyResolver\Operation\MarkAliasInstalledOperation;
use Composer\DependencyResolver\Operation\MarkAliasUninstalledOperation;

class InstallationManagerMock extends InstallationManager
{
    /**
     * @var PackageInterface[]
     */
    private $installed = array();
    /**
     * @var PackageInterface[][]
     */
    private $updated = array();
    /**
     * @var PackageInterface[]
     */
    private $uninstalled = array();
    /**
     * @var string[]
     */
    private $trace = array();

    public function __construct()
    {
    }

    public function execute(InstalledRepositoryInterface $repo, array $operations, $devMode = true, $runScripts = true): void
    {
        foreach ($operations as $operation) {
            $method = $operation->getOperationType();
            // skipping download() step here for tests
            $this->$method($repo, $operation);
        }
    }

    public function getInstallPath(PackageInterface $package): string
    {
        return 'vendor/'.$package->getName();
    }

    public function isPackageInstalled(InstalledRepositoryInterface $repo, PackageInterface $package): bool
    {
        return $repo->hasPackage($package);
    }

    /**
     * @inheritDoc
     */
    public function install(InstalledRepositoryInterface $repo, InstallOperation $operation): ?PromiseInterface
    {
        $this->installed[] = $operation->getPackage();
        $this->trace[] = strip_tags((string) $operation);
        $repo->addPackage(clone $operation->getPackage());

        return null;
    }

    /**
     * @inheritDoc
     */
    public function update(InstalledRepositoryInterface $repo, UpdateOperation $operation): ?PromiseInterface
    {
        $this->updated[] = array($operation->getInitialPackage(), $operation->getTargetPackage());
        $this->trace[] = strip_tags((string) $operation);
        $repo->removePackage($operation->getInitialPackage());
        if (!$repo->hasPackage($operation->getTargetPackage())) {
            $repo->addPackage(clone $operation->getTargetPackage());
        }

        return null;
    }

    /**
     * @inheritDoc
     */
    public function uninstall(InstalledRepositoryInterface $repo, UninstallOperation $operation): ?PromiseInterface
    {
        $this->uninstalled[] = $operation->getPackage();
        $this->trace[] = strip_tags((string) $operation);
        $repo->removePackage($operation->getPackage());

        return null;
    }

    public function markAliasInstalled(InstalledRepositoryInterface $repo, MarkAliasInstalledOperation $operation): void
    {
        $package = $operation->getPackage();

        $this->installed[] = $package;
        $this->trace[] = strip_tags((string) $operation);

        parent::markAliasInstalled($repo, $operation);
    }

    public function markAliasUninstalled(InstalledRepositoryInterface $repo, MarkAliasUninstalledOperation $operation): void
    {
        $this->uninstalled[] = $operation->getPackage();
        $this->trace[] = strip_tags((string) $operation);

        parent::markAliasUninstalled($repo, $operation);
    }

    /** @return string[] */
    public function getTrace(): array
    {
        return $this->trace;
    }

    /** @return PackageInterface[] */
    public function getInstalledPackages(): array
    {
        return $this->installed;
    }

    /** @return PackageInterface[][] */
    public function getUpdatedPackages(): array
    {
        return $this->updated;
    }

    /** @return PackageInterface[] */
    public function getUninstalledPackages(): array
    {
        return $this->uninstalled;
    }

    public function notifyInstalls(IOInterface $io): void
    {
        // noop
    }

    /** @return PackageInterface[] */
    public function getInstalledPackagesByType(): array
    {
        return $this->installed;
    }
}
