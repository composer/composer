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

namespace Composer\Plugin;

use Composer\EventDispatcher\Event;
use Composer\Repository\RepositoryInterface;
use Composer\DependencyResolver\Request;
use Composer\Package\PackageInterface;

/**
 * The pre command run event.
 *
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class PrePoolCreateEvent extends Event
{
    /**
     * @var RepositoryInterface[]
     */
    private $repositories;
    /**
     * @var Request
     */
    private $request;
    /**
     * @var array
     */
    private $acceptableStabilities;
    /**
     * @var array
     */
    private $stabilityFlags;
    /**
     * @var array
     */
    private $rootAliases;
    /**
     * @var array
     */
    private $rootReferences;
    /**
     * @var PackageInterface[]
     */
    private $packages;
    /**
     * @var PackageInterface[]
     */
    private $unacceptableFixedPackages;

    /**
     * @param string                $name         The event name
     * @param RepositoryInterface[] $repositories
     */
    public function __construct($name, array $repositories, Request $request, array $acceptableStabilities, array $stabilityFlags, array $rootAliases, array $rootReferences, array $packages, array $unacceptableFixedPackages)
    {
        parent::__construct($name);

        $this->repositories = $repositories;
        $this->request = $request;
        $this->acceptableStabilities = $acceptableStabilities;
        $this->stabilityFlags = $stabilityFlags;
        $this->rootAliases = $rootAliases;
        $this->rootReferences = $rootReferences;
        $this->packages = $packages;
        $this->unacceptableFixedPackages = $unacceptableFixedPackages;
    }

    /**
     * @return RepositoryInterface[]
     */
    public function getRepositories()
    {
        return $this->repositories;
    }

    /**
     * @return Request
     */
    public function getRequest()
    {
        return $this->request;
    }

    /**
     * @return array
     */
    public function getAcceptableStabilities()
    {
        return $this->acceptableStabilities;
    }

    /**
     * @return array
     */
    public function getStabilityFlags()
    {
        return $this->stabilityFlags;
    }

    /**
     * @return array[] of package => version => [alias, alias_normalized]
     * @phpstan-return array<string, array<string, array{alias: string, alias_normalized: string}>>
     */
    public function getRootAliases()
    {
        return $this->rootAliases;
    }

    /**
     * @return array
     */
    public function getRootReferences()
    {
        return $this->rootReferences;
    }

    /**
     * @return PackageInterface[]
     */
    public function getPackages()
    {
        return $this->packages;
    }

    /**
     * @return PackageInterface[]
     */
    public function getUnacceptableFixedPackages()
    {
        return $this->unacceptableFixedPackages;
    }

    /**
     * @param PackageInterface[] $packages
     */
    public function setPackages(array $packages)
    {
        $this->packages = $packages;
    }

    /**
     * @param PackageInterface[] $packages
     */
    public function setUnacceptableFixedPackages(array $packages)
    {
        $this->unacceptableFixedPackages = $packages;
    }
}
