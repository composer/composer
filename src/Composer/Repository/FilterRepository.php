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

namespace Composer\Repository;

use Composer\Package\PackageInterface;
use Composer\Package\BasePackage;

/**
 * Filters which packages are seen as canonical on this repo by loadPackages
 *
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class FilterRepository implements RepositoryInterface
{
    /** @var ?string */
    private $only = null;
    /** @var ?string */
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
            $this->only = '{^(?:'.implode('|', array_map(function ($val) {
                return BasePackage::packageNameToRegexp($val, '%s');
            }, $options['only'])) .')$}iD';
        }
        if (isset($options['exclude'])) {
            if (!is_array($options['exclude'])) {
                throw new \InvalidArgumentException('"exclude" key for repository '.$repo->getRepoName().' should be an array');
            }
            $this->exclude = '{^(?:'.implode('|', array_map(function ($val) {
                return BasePackage::packageNameToRegexp($val, '%s');
            }, $options['exclude'])) .')$}iD';
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

    public function getRepoName()
    {
        return $this->repo->getRepoName();
    }

    /**
     * Returns the wrapped repositories
     *
     * @return RepositoryInterface
     */
    public function getRepository()
    {
        return $this->repo;
    }

    /**
     * @inheritDoc
     */
    public function hasPackage(PackageInterface $package)
    {
        return $this->repo->hasPackage($package);
    }

    /**
     * @inheritDoc
     */
    public function findPackage($name, $constraint)
    {
        if (!$this->isAllowed($name)) {
            return null;
        }

        return $this->repo->findPackage($name, $constraint);
    }

    /**
     * @inheritDoc
     */
    public function findPackages($name, $constraint = null)
    {
        if (!$this->isAllowed($name)) {
            return array();
        }

        return $this->repo->findPackages($name, $constraint);
    }

    /**
     * @inheritDoc
     */
    public function loadPackages(array $packageMap, array $acceptableStabilities, array $stabilityFlags, array $alreadyLoaded = array())
    {
        foreach ($packageMap as $name => $constraint) {
            if (!$this->isAllowed($name)) {
                unset($packageMap[$name]);
            }
        }

        if (!$packageMap) {
            return array('namesFound' => array(), 'packages' => array());
        }

        $result = $this->repo->loadPackages($packageMap, $acceptableStabilities, $stabilityFlags, $alreadyLoaded);
        if (!$this->canonical) {
            $result['namesFound'] = array();
        }

        return $result;
    }

    /**
     * @inheritDoc
     */
    public function search($query, $mode = 0, $type = null)
    {
        $result = array();

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
    public function getPackages()
    {
        $result = array();
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
    public function getProviders($packageName)
    {
        $result = array();
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
    #[\ReturnTypeWillChange]
    public function count()
    {
        if ($this->repo->count() > 0) {
            return count($this->getPackages());
        }

        return 0;
    }

    /**
     * @param string $name
     *
     * @return bool
     */
    private function isAllowed($name)
    {
        if (!$this->only && !$this->exclude) {
            return true;
        }

        if ($this->only) {
            return (bool) preg_match($this->only, $name);
        }

        return !preg_match($this->exclude, $name);
    }
}
