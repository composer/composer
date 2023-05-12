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
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class RootAliasPackage extends CompleteAliasPackage implements RootPackageInterface
{
    /** @var RootPackage */
    protected $aliasOf;

    /**
     * All descendants' constructors should call this parent constructor
     *
     * @param RootPackage $aliasOf       The package this package is an alias of
     * @param string      $version       The version the alias must report
     * @param string      $prettyVersion The alias's non-normalized version
     */
    public function __construct(RootPackage $aliasOf, string $version, string $prettyVersion)
    {
        parent::__construct($aliasOf, $version, $prettyVersion);
    }

    /**
     * @return RootPackage
     */
    public function getAliasOf()
    {
        return $this->aliasOf;
    }

    /**
     * @inheritDoc
     */
    public function getAliases(): array
    {
        return $this->aliasOf->getAliases();
    }

    /**
     * @inheritDoc
     */
    public function getMinimumStability(): string
    {
        return $this->aliasOf->getMinimumStability();
    }

    /**
     * @inheritDoc
     */
    public function getStabilityFlags(): array
    {
        return $this->aliasOf->getStabilityFlags();
    }

    /**
     * @inheritDoc
     */
    public function getReferences(): array
    {
        return $this->aliasOf->getReferences();
    }

    /**
     * @inheritDoc
     */
    public function getPreferStable(): bool
    {
        return $this->aliasOf->getPreferStable();
    }

    /**
     * @inheritDoc
     */
    public function getConfig(): array
    {
        return $this->aliasOf->getConfig();
    }

    /**
     * @inheritDoc
     */
    public function setRequires(array $requires): void
    {
        $this->requires = $this->replaceSelfVersionDependencies($requires, Link::TYPE_REQUIRE);

        $this->aliasOf->setRequires($requires);
    }

    /**
     * @inheritDoc
     */
    public function setDevRequires(array $devRequires): void
    {
        $this->devRequires = $this->replaceSelfVersionDependencies($devRequires, Link::TYPE_DEV_REQUIRE);

        $this->aliasOf->setDevRequires($devRequires);
    }

    /**
     * @inheritDoc
     */
    public function setConflicts(array $conflicts): void
    {
        $this->conflicts = $this->replaceSelfVersionDependencies($conflicts, Link::TYPE_CONFLICT);
        $this->aliasOf->setConflicts($conflicts);
    }

    /**
     * @inheritDoc
     */
    public function setProvides(array $provides): void
    {
        $this->provides = $this->replaceSelfVersionDependencies($provides, Link::TYPE_PROVIDE);
        $this->aliasOf->setProvides($provides);
    }

    /**
     * @inheritDoc
     */
    public function setReplaces(array $replaces): void
    {
        $this->replaces = $this->replaceSelfVersionDependencies($replaces, Link::TYPE_REPLACE);
        $this->aliasOf->setReplaces($replaces);
    }

    /**
     * @inheritDoc
     */
    public function setAutoload(array $autoload): void
    {
        $this->aliasOf->setAutoload($autoload);
    }

    /**
     * @inheritDoc
     */
    public function setDevAutoload(array $devAutoload): void
    {
        $this->aliasOf->setDevAutoload($devAutoload);
    }

    /**
     * @inheritDoc
     */
    public function setStabilityFlags(array $stabilityFlags): void
    {
        $this->aliasOf->setStabilityFlags($stabilityFlags);
    }

    /**
     * @inheritDoc
     */
    public function setMinimumStability(string $minimumStability): void
    {
        $this->aliasOf->setMinimumStability($minimumStability);
    }

    /**
     * @inheritDoc
     */
    public function setPreferStable(bool $preferStable): void
    {
        $this->aliasOf->setPreferStable($preferStable);
    }

    /**
     * @inheritDoc
     */
    public function setConfig(array $config): void
    {
        $this->aliasOf->setConfig($config);
    }

    /**
     * @inheritDoc
     */
    public function setReferences(array $references): void
    {
        $this->aliasOf->setReferences($references);
    }

    /**
     * @inheritDoc
     */
    public function setAliases(array $aliases): void
    {
        $this->aliasOf->setAliases($aliases);
    }

    /**
     * @inheritDoc
     */
    public function setSuggests(array $suggests): void
    {
        $this->aliasOf->setSuggests($suggests);
    }

    /**
     * @inheritDoc
     */
    public function setExtra(array $extra): void
    {
        $this->aliasOf->setExtra($extra);
    }

    public function __clone()
    {
        parent::__clone();
        $this->aliasOf = clone $this->aliasOf;
    }

    /**
     * @inheritDoc
     */
    public function setTrusted(?array $trusted = null): void
    {
        $this->aliasOf->setTrusted($trusted);
    }

    /**
     * @inheritDoc
     */
    public function getTrusted(): ?array
    {
        return $this->aliasOf->getTrusted();
    }

    /**
     * @inheritDoc
     */
    public function setDevTrusted(?array $devTrusted = null): void
    {
        $this->aliasOf->setDevTrusted($devTrusted);
    }

    /**
     * @inheritDoc
     */
    public function getDevTrusted(): ?array
    {
        return $this->aliasOf->getDevTrusted();
    }
}
