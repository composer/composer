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

use Composer\Package\PackageInterface;
use Composer\Package\BasePackage;
use Composer\Pcre\Preg;

/**
 * Filters which packages are seen as canonical on this repo by loadPackages
 *
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class FilterRepository implements RepositoryInterface
{
    /** @var ?string */
    private $only = null;
    /** @var ?non-empty-string */
    private $exclude = null;
    /** @var bool */
    private $canonical = true;
    /** @var RepositoryInterface */
    private $repo;

    /**
     * @param array{only?: array<string>, exclude?: array<string>, canonical?: bool} $options
     */
    public function __construct(RepositoryInterface $repo, array $options)
    {
        if (isset($options['only'])) {
            if (!is_array($options['only'])) {
                throw new \InvalidArgumentException('"only" key for repository '.$repo->getRepoName().' should be an array');
            }
            $this->only = BasePackage::packageNamesToRegexp($options['only']);
        }
        if (isset($options['exclude'])) {
            if (!is_array($options['exclude'])) {
                throw new \InvalidArgumentException('"exclude" key for repository '.$repo->getRepoName().' should be an array');
            }
            $this->exclude = BasePackage::packageNamesToRegexp($options['exclude']);
        }
        if ($this->exclude && $this->only) {
            throw new \InvalidArgumentException('Only one of "only" and "exclude" can be specified for repository '.$repo->getRepoName());
        }
        if (isset($options['canonical'])) {
            if (!is_bool($options['canonical'])) {
                throw new \InvalidArgumentException('"canonical" key for repository '.$repo->getRepoName().' should be a boolean');
            }
            $this->canonical = $options['canonical'];
        }

        $this->repo = $repo;
    }

    public function getRepoName(): string
    {
        return $this->repo->getRepoName();
    }

    /**
     * Returns the wrapped repositories
     */
    public function getRepository(): RepositoryInterface
    {
        return $this->repo;
    }

    /**
     * @inheritDoc
     */
    public function hasPackage(PackageInterface $package): bool
    {
        return $this->repo->hasPackage($package);
    }

    /**
     * @inheritDoc
     */
    public function findPackage($name, $constraint): ?BasePackage
    {
        if (!$this->isAllowed($name)) {
            return null;
        }

        return $this->repo->findPackage($name, $constraint);
    }

    /**
     * @inheritDoc
     */
    public function findPackages($name, $constraint = null): array
    {
        if (!$this->isAllowed($name)) {
            return [];
        }

        return $this->repo->findPackages($name, $constraint);
    }

    /**
     * @inheritDoc
     */
    public function loadPackages(array $packageNameMap, array $acceptableStabilities, array $stabilityFlags, array $alreadyLoaded = []): array
    {
        foreach ($packageNameMap as $name => $constraint) {
            if (!$this->isAllowed($name)) {
                unset($packageNameMap[$name]);
            }
        }

        if (!$packageNameMap) {
            return ['namesFound' => [], 'packages' => []];
        }

        $result = $this->repo->loadPackages($packageNameMap, $acceptableStabilities, $stabilityFlags, $alreadyLoaded);
        if (!$this->canonical) {
            $result['namesFound'] = [];
        }

        return $result;
    }

    /**
     * @inheritDoc
     */
    public function search(string $query, int $mode = 0, ?string $type = null): array
    {
        $result = [];

        foreach ($this->repo->search($query, $mode, $type) as $package) {
            if ($this->isAllowed($package['name'])) {
                $result[] = $package;
            }
        }

        return $result;
    }

    /**
     * @inheritDoc
     */
    public function getPackages(): array
    {
        $result = [];
        foreach ($this->repo->getPackages() as $package) {
            if ($this->isAllowed($package->getName())) {
                $result[] = $package;
            }
        }

        return $result;
    }

    /**
     * @inheritDoc
     */
    public function getProviders($packageName): array
    {
        $result = [];
        foreach ($this->repo->getProviders($packageName) as $name => $provider) {
            if ($this->isAllowed($provider['name'])) {
                $result[$name] = $provider;
            }
        }

        return $result;
    }

    /**
     * @inheritDoc
     */
    public function count(): int
    {
        if ($this->repo->count() > 0) {
            return count($this->getPackages());
        }

        return 0;
    }

    private function isAllowed(string $name): bool
    {
        if (!$this->only && !$this->exclude) {
            return true;
        }

        if ($this->only) {
            return Preg::isMatch($this->only, $name);
        }

        if ($this->exclude === null) {
            return true;
        }

        return !Preg::isMatch($this->exclude, $name);
    }
}
