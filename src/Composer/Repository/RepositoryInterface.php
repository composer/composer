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
use Composer\Semver\Constraint\ConstraintInterface;

/**
 * Repository interface.
 *
 * @author Nils Adermann <naderman@naderman.de>
 * @author Konstantin Kudryashov <ever.zet@gmail.com>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
interface RepositoryInterface extends \Countable
{
    public const SEARCH_FULLTEXT = 0;
    public const SEARCH_NAME = 1;
    public const SEARCH_VENDOR = 2;

    /**
     * Checks if specified package registered (installed).
     *
     * @param PackageInterface $package package instance
     *
     * @return bool
     */
    public function hasPackage(PackageInterface $package);

    /**
     * Searches for the first match of a package by name and version.
     *
     * @param string                     $name       package name
     * @param string|ConstraintInterface $constraint package version or version constraint to match against
     *
     * @return BasePackage|null
     */
    public function findPackage(string $name, $constraint);

    /**
     * Searches for all packages matching a name and optionally a version.
     *
     * @param string                     $name       package name
     * @param string|ConstraintInterface $constraint package version or version constraint to match against
     *
     * @return BasePackage[]
     */
    public function findPackages(string $name, $constraint = null);

    /**
     * Returns list of registered packages.
     *
     * @return BasePackage[]
     */
    public function getPackages();

    /**
     * Returns list of registered packages with the supplied name
     *
     * - The packages returned are the packages found which match the constraints, acceptable stability and stability flags provided
     * - The namesFound returned are names which should be considered as canonically found in this repository, that should not be looked up in any further lower priority repositories
     *
     * @param ConstraintInterface[]                          $packageNameMap        package names pointing to constraints
     * @param array<string, int>        $acceptableStabilities array of stability => BasePackage::STABILITY_* value
     * @param array<string, BasePackage::STABILITY_*>        $stabilityFlags        an array of package name => BasePackage::STABILITY_* value
     * @param array<string, array<string, PackageInterface>> $alreadyLoaded         an array of package name => package version => package
     *
     * @return array
     *
     * @phpstan-param  array<key-of<BasePackage::STABILITIES>, BasePackage::STABILITY_*> $acceptableStabilities
     * @phpstan-param  array<string, ConstraintInterface|null> $packageNameMap
     * @phpstan-return array{namesFound: array<string>, packages: array<BasePackage>}
     */
    public function loadPackages(array $packageNameMap, array $acceptableStabilities, array $stabilityFlags, array $alreadyLoaded = []);

    /**
     * Searches the repository for packages containing the query
     *
     * @param string  $query search query, for SEARCH_NAME and SEARCH_VENDOR regular expressions metacharacters are supported by implementations, and user input should be escaped through preg_quote by callers
     * @param int     $mode  a set of SEARCH_* constants to search on, implementations should do a best effort only, default is SEARCH_FULLTEXT
     * @param ?string $type  The type of package to search for. Defaults to all types of packages
     *
     * @return array[] an array of array('name' => '...', 'description' => '...'|null, 'abandoned' => 'string'|true|unset) For SEARCH_VENDOR the name will be in "vendor" form
     * @phpstan-return list<array{name: string, description: ?string, abandoned?: string|true, url?: string}>
     */
    public function search(string $query, int $mode = 0, ?string $type = null);

    /**
     * Returns a list of packages providing a given package name
     *
     * Packages which have the same name as $packageName should not be returned, only those that have a "provide" on it.
     *
     * @param string $packageName package name which must be provided
     *
     * @return array[] an array with the provider name as key and value of array('name' => '...', 'description' => '...', 'type' => '...')
     * @phpstan-return array<string, array{name: string, description: string, type: string}>
     */
    public function getProviders(string $packageName);

    /**
     * Returns a name representing this repository to the user
     *
     * This is best effort and definitely can not always be very precise
     *
     * @return string
     */
    public function getRepoName();
}
