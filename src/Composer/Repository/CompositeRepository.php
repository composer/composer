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

namespace Composer\Repository;

use Composer\Package\BasePackage;
use Composer\Package\PackageInterface;

/**
 * Composite repository.
 *
 * @author Beau Simensen <beau@dflydev.com>
 */
class CompositeRepository implements RepositoryInterface
{
    /**
     * List of repositories
     * @var RepositoryInterface[]
     */
    private $repositories;

    /**
     * Constructor
     * @param RepositoryInterface[] $repositories
     */
    public function __construct(array $repositories)
    {
        $this->repositories = [];
        foreach ($repositories as $repo) {
            $this->addRepository($repo);
        }
    }

    public function getRepoName(): string
    {
        return 'composite repo ('.implode(', ', array_map(function ($repo): string {
            return $repo->getRepoName();
        }, $this->repositories)).')';
    }

    /**
     * Returns all the wrapped repositories
     *
     * @return RepositoryInterface[]
     */
    public function getRepositories(): array
    {
        return $this->repositories;
    }

    /**
     * @inheritDoc
     */
    public function hasPackage(PackageInterface $package): bool
    {
        foreach ($this->repositories as $repository) {
            /* @var $repository RepositoryInterface */
            if ($repository->hasPackage($package)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @inheritDoc
     */
    public function findPackage($name, $constraint): ?BasePackage
    {
        foreach ($this->repositories as $repository) {
            /* @var $repository RepositoryInterface */
            $package = $repository->findPackage($name, $constraint);
            if (null !== $package) {
                return $package;
            }
        }

        return null;
    }

    /**
     * @inheritDoc
     */
    public function findPackages($name, $constraint = null): array
    {
        $packages = [];
        foreach ($this->repositories as $repository) {
            /* @var $repository RepositoryInterface */
            $packages[] = $repository->findPackages($name, $constraint);
        }

        return $packages ? call_user_func_array('array_merge', $packages) : [];
    }

    /**
     * @inheritDoc
     */
    public function loadPackages(array $packageNameMap, array $acceptableStabilities, array $stabilityFlags, array $alreadyLoaded = []): array
    {
        $packages = [];
        $namesFound = [];
        foreach ($this->repositories as $repository) {
            /* @var $repository RepositoryInterface */
            $result = $repository->loadPackages($packageNameMap, $acceptableStabilities, $stabilityFlags, $alreadyLoaded);
            $packages[] = $result['packages'];
            $namesFound[] = $result['namesFound'];
        }

        return [
            'packages' => $packages ? call_user_func_array('array_merge', $packages) : [],
            'namesFound' => $namesFound ? array_unique(call_user_func_array('array_merge', $namesFound)) : [],
        ];
    }

    /**
     * @inheritDoc
     */
    public function search(string $query, int $mode = 0, ?string $type = null): array
    {
        $matches = [];
        foreach ($this->repositories as $repository) {
            /* @var $repository RepositoryInterface */
            $matches[] = $repository->search($query, $mode, $type);
        }

        return $matches ? call_user_func_array('array_merge', $matches) : [];
    }

    /**
     * @inheritDoc
     */
    public function getPackages(): array
    {
        $packages = [];
        foreach ($this->repositories as $repository) {
            /* @var $repository RepositoryInterface */
            $packages[] = $repository->getPackages();
        }

        return $packages ? call_user_func_array('array_merge', $packages) : [];
    }

    /**
     * @inheritDoc
     */
    public function getProviders($packageName): array
    {
        $results = [];
        foreach ($this->repositories as $repository) {
            /* @var $repository RepositoryInterface */
            $results[] = $repository->getProviders($packageName);
        }

        return $results ? call_user_func_array('array_merge', $results) : [];
    }

    /**
     * @return void
     */
    public function removePackage(PackageInterface $package): void
    {
        foreach ($this->repositories as $repository) {
            if ($repository instanceof WritableRepositoryInterface) {
                $repository->removePackage($package);
            }
        }
    }

    /**
     * @inheritDoc
     */
    public function count(): int
    {
        $total = 0;
        foreach ($this->repositories as $repository) {
            /* @var $repository RepositoryInterface */
            $total += $repository->count();
        }

        return $total;
    }

    /**
     * Add a repository.
     * @param RepositoryInterface $repository
     *
     * @return void
     */
    public function addRepository(RepositoryInterface $repository): void
    {
        if ($repository instanceof self) {
            foreach ($repository->getRepositories() as $repo) {
                $this->addRepository($repo);
            }
        } else {
            $this->repositories[] = $repository;
        }
    }
}
