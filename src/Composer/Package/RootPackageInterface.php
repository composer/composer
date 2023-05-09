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
 * Defines additional fields that are only needed for the root package
 *
 * PackageInterface & derivatives are considered internal, you may use them in type hints but extending/implementing them is not recommended and not supported. Things may change without notice.
 *
 * @author Jordi Boggiano <j.boggiano@seld.be>
 *
 * @phpstan-import-type AutoloadRules from PackageInterface
 * @phpstan-import-type DevAutoloadRules from PackageInterface
 */
interface RootPackageInterface extends CompletePackageInterface
{
    /**
     * Returns a set of package names and their aliases
     *
     * @return list<array{package: string, version: string, alias: string, alias_normalized: string}>
     */
    public function getAliases(): array;

    /**
     * Returns the minimum stability of the package
     */
    public function getMinimumStability(): string;

    /**
     * Returns the stability flags to apply to dependencies
     *
     * array('foo/bar' => 'dev')
     *
     * @return array<string, BasePackage::STABILITY_*>
     */
    public function getStabilityFlags(): array;

    /**
     * Returns a set of package names and source references that must be enforced on them
     *
     * array('foo/bar' => 'abcd1234')
     *
     * @return array<string, string>
     */
    public function getReferences(): array;

    /**
     * Returns true if the root package prefers picking stable packages over unstable ones
     */
    public function getPreferStable(): bool;

    /**
     * Sets the list of trusted packages.
     *
     * @param string[] $trusted
     */
    public function setTrusted(array $trusted): void;

    /**
     * Returns the list of trusted packages (a wildcard can be used to trust all packages from a specific vendor).
     *
     * @return string[]
     */
    public function getTrusted(): array;

    /**
     * Sets the list of trusted packages in dev.
     *
     * @param string[] $devTrusted
     */
    public function setDevTrusted(array $devTrusted): void;

    /**
     * Returns the list of packages trusted in dev (a wildcard can be used to trust all packages from a specific vendor).
     *
     * @return string[]
     */
    public function getDevTrusted(): array;

    /**
     * Returns the root package's configuration
     *
     * @return mixed[]
     */
    public function getConfig(): array;

    /**
     * Set the required packages
     *
     * @param Link[] $requires A set of package links
     */
    public function setRequires(array $requires): void;

    /**
     * Set the recommended packages
     *
     * @param Link[] $devRequires A set of package links
     */
    public function setDevRequires(array $devRequires): void;

    /**
     * Set the conflicting packages
     *
     * @param Link[] $conflicts A set of package links
     */
    public function setConflicts(array $conflicts): void;

    /**
     * Set the provided virtual packages
     *
     * @param Link[] $provides A set of package links
     */
    public function setProvides(array $provides): void;

    /**
     * Set the packages this one replaces
     *
     * @param Link[] $replaces A set of package links
     */
    public function setReplaces(array $replaces): void;

    /**
     * Set the autoload mapping
     *
     * @param array $autoload Mapping of autoloading rules
     * @phpstan-param AutoloadRules $autoload
     */
    public function setAutoload(array $autoload): void;

    /**
     * Set the dev autoload mapping
     *
     * @param array $devAutoload Mapping of dev autoloading rules
     * @phpstan-param DevAutoloadRules $devAutoload
     */
    public function setDevAutoload(array $devAutoload): void;

    /**
     * Set the stabilityFlags
     *
     * @param array<string, BasePackage::STABILITY_*> $stabilityFlags
     */
    public function setStabilityFlags(array $stabilityFlags): void;

    /**
     * Set the minimumStability
     */
    public function setMinimumStability(string $minimumStability): void;

    /**
     * Set the preferStable
     */
    public function setPreferStable(bool $preferStable): void;

    /**
     * Set the config
     *
     * @param mixed[] $config
     */
    public function setConfig(array $config): void;

    /**
     * Set the references
     *
     * @param array<string, string> $references
     */
    public function setReferences(array $references): void;

    /**
     * Set the aliases
     *
     * @param list<array{package: string, version: string, alias: string, alias_normalized: string}> $aliases
     */
    public function setAliases(array $aliases): void;

    /**
     * Set the suggested packages
     *
     * @param array<string, string> $suggests A set of package names/comments
     */
    public function setSuggests(array $suggests): void;

    /**
     * @param mixed[] $extra
     */
    public function setExtra(array $extra): void;
}
