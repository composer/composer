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

namespace Composer\Package;

/**
 * The root package represents the project's composer.json and contains additional metadata
 *
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class RootPackage extends CompletePackage implements RootPackageInterface
{
    public const DEFAULT_PRETTY_VERSION = '1.0.0+no-version-set';

    /** @var key-of<BasePackage::STABILITIES> */
    protected $minimumStability = 'stable';
    /** @var bool */
    protected $preferStable = false;
    /** @var array<string, BasePackage::STABILITY_*> Map of package name to stability constant */
    protected $stabilityFlags = [];
    /** @var mixed[] */
    protected $config = [];
    /** @var array<string, string> Map of package name to reference/commit hash */
    protected $references = [];
    /** @var list<array{package: string, version: string, alias: string, alias_normalized: string}> */
    protected $aliases = [];

    /**
     * @inheritDoc
     */
    public function setMinimumStability(string $minimumStability): void
    {
        $this->minimumStability = $minimumStability;
    }

    /**
     * @inheritDoc
     */
    public function getMinimumStability(): string
    {
        return $this->minimumStability;
    }

    /**
     * @inheritDoc
     */
    public function setStabilityFlags(array $stabilityFlags): void
    {
        $this->stabilityFlags = $stabilityFlags;
    }

    /**
     * @inheritDoc
     */
    public function getStabilityFlags(): array
    {
        return $this->stabilityFlags;
    }

    /**
     * @inheritDoc
     */
    public function setPreferStable(bool $preferStable): void
    {
        $this->preferStable = $preferStable;
    }

    /**
     * @inheritDoc
     */
    public function getPreferStable(): bool
    {
        return $this->preferStable;
    }

    /**
     * @inheritDoc
     */
    public function setConfig(array $config): void
    {
        $this->config = $config;
    }

    /**
     * @inheritDoc
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * @inheritDoc
     */
    public function setReferences(array $references): void
    {
        $this->references = $references;
    }

    /**
     * @inheritDoc
     */
    public function getReferences(): array
    {
        return $this->references;
    }

    /**
     * @inheritDoc
     */
    public function setAliases(array $aliases): void
    {
        $this->aliases = $aliases;
    }

    /**
     * @inheritDoc
     */
    public function getAliases(): array
    {
        return $this->aliases;
    }
}
