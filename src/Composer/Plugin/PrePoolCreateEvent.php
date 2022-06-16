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

namespace Composer\Plugin;

use Composer\DependencyResolver\Request;
use Composer\EventDispatcher\Event;
use Composer\Package\BasePackage;
use Composer\Repository\RepositoryInterface;

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
     * @var int[] array of stability => BasePackage::STABILITY_* value
     * @phpstan-var array<string, BasePackage::STABILITY_*>
     */
    private $acceptableStabilities;
    /**
     * @var int[] array of package name => BasePackage::STABILITY_* value
     * @phpstan-var array<string, BasePackage::STABILITY_*>
     */
    private $stabilityFlags;
    /**
     * @var array[] of package => version => [alias, alias_normalized]
     * @phpstan-var array<string, array<string, array{alias: string, alias_normalized: string}>>
     */
    private $rootAliases;
    /**
     * @var string[]
     * @phpstan-var array<string, string>
     */
    private $rootReferences;
    /**
     * @var BasePackage[]
     */
    private $packages;
    /**
     * @var BasePackage[]
     */
    private $unacceptableFixedPackages;

    /**
     * @param string                $name                   The event name
     * @param RepositoryInterface[] $repositories
     * @param int[]                 $acceptableStabilities  array of stability => BasePackage::STABILITY_* value
     * @param int[]                 $stabilityFlags         array of package name => BasePackage::STABILITY_* value
     * @param array[]               $rootAliases            array of package => version => [alias, alias_normalized]
     * @param string[]              $rootReferences
     * @param BasePackage[]         $packages
     * @param BasePackage[]         $unacceptableFixedPackages
     *
     * @phpstan-param array<string, BasePackage::STABILITY_*> $acceptableStabilities
     * @phpstan-param array<string, BasePackage::STABILITY_*> $stabilityFlags
     * @phpstan-param array<string, array<string, array{alias: string, alias_normalized: string}>> $rootAliases
     * @phpstan-param array<string, string> $rootReferences
     */
    public function __construct(string $name, array $repositories, Request $request, array $acceptableStabilities, array $stabilityFlags, array $rootAliases, array $rootReferences, array $packages, array $unacceptableFixedPackages)
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
    public function getRepositories(): array
    {
        return $this->repositories;
    }

    /**
     * @return Request
     */
    public function getRequest(): Request
    {
        return $this->request;
    }

    /**
     * @return int[] array of stability => BasePackage::STABILITY_* value
     * @phpstan-return array<string, BasePackage::STABILITY_*>
     */
    public function getAcceptableStabilities(): array
    {
        return $this->acceptableStabilities;
    }

    /**
     * @return int[] array of package name => BasePackage::STABILITY_* value
     * @phpstan-return array<string, BasePackage::STABILITY_*>
     */
    public function getStabilityFlags(): array
    {
        return $this->stabilityFlags;
    }

    /**
     * @return array[] of package => version => [alias, alias_normalized]
     * @phpstan-return array<string, array<string, array{alias: string, alias_normalized: string}>>
     */
    public function getRootAliases(): array
    {
        return $this->rootAliases;
    }

    /**
     * @return string[]
     * @phpstan-return array<string, string>
     */
    public function getRootReferences(): array
    {
        return $this->rootReferences;
    }

    /**
     * @return BasePackage[]
     */
    public function getPackages(): array
    {
        return $this->packages;
    }

    /**
     * @return BasePackage[]
     */
    public function getUnacceptableFixedPackages(): array
    {
        return $this->unacceptableFixedPackages;
    }

    /**
     * @param BasePackage[] $packages
     *
     * @return void
     */
    public function setPackages(array $packages): void
    {
        $this->packages = $packages;
    }

    /**
     * @param BasePackage[] $packages
     *
     * @return void
     */
    public function setUnacceptableFixedPackages(array $packages): void
    {
        $this->unacceptableFixedPackages = $packages;
    }
}
