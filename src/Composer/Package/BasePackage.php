<?php

/*
 * This file is part of Composer.
 *
 * (c) Nils Adermann <naderman@naderman.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Composer\Package;

use Composer\DependencyResolver\RelationConstraint\RelationConstraintInterface;

/**
 * Base class for packages providing name storage and default match implementation
 *
 * @author Nils Adermann <naderman@naderman.de>
 */
abstract class Package implements PackageInterface
{
    protected $name;
    protected $id;

    /**
     * All descendents' constructors should call this parent constructor
     *
     * @param string $name The package's name
     */
    public function __construct($name)
    {
        $this->name = $name;

    }

    /**
     * Returns the package's name without version info, thus not a unique identifier
     *
     * @return string package name
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * Returns a set of names that could refer to this package
     *
     * No version or release type information should be included in any of the
     * names. Provided or replaced package names need to be returned as well.
     *
     * @return array An array of strings refering to this package
     */
    public function getNames()
    {
        $names = array(
            $this->getName(),
        );

        foreach ($this->getProvides() as $relation) {
            $names[] = $relation->getToPackageName();
        }

        foreach ($this->getReplaces() as $relation) {
            $names[] = $relation->getToPackageName();
        }

        return $names;
    }

    /**
     * Checks if the package matches the given constraint directly or through
     * provided or replaced packages
     *
     * @param string                      $name       Name of the package to be matched
     * @param RelationConstraintInterface $constraint The constraint to verify
     * @return bool                                   Whether this package matches the name and constraint
     */
    public function matches($name, RelationConstraintInterface $constraint)
    {
        if ($this->name === $name) {
            return $constraint->matches($this->getReleaseType(), $this->getVersion());
        }

        foreach ($this->getProvides() as $relation) {
            if ($relation->getToPackageName() === $name) {
                return $constraint->matches($relation->getToReleaseType(), $relation->getToVersion());
            }
        }

        foreach ($this->getReplaces() as $relation) {
            if ($relation->getToPackageName() === $name) {
                return $constraint->matches($relation->getToReleaseType(), $relation->getToVersion());
            }
        }

        return false;
    }

    /**
     * Returns the release type of this package, e.g. stable or beta
     *
     * @return string The release type
     */
    abstract public function getReleaseType();

    /**
     * Returns the version of this package
     *
     * @return string version
     */
    abstract public function getVersion();

    /**
     * Returns a set of relations to packages which need to be installed before
     * this package can be installed
     *
     * @return array An array of package relations defining required packages
     */
    abstract public function getRequires();

    /**
     * Returns a set of relations to packages which must not be installed at the
     * same time as this package
     *
     * @return array An array of package relations defining conflicting packages
     */
    abstract public function getConflicts();

    /**
     * Returns a set of relations to virtual packages that are provided through
     * this package
     *
     * @return array An array of package relations defining provided packages
     */
    abstract public function getProvides();

    /**
     * Returns a set of relations to packages which can alternatively be
     * satisfied by installing this package
     *
     * @return array An array of package relations defining replaced packages
     */
    abstract public function getReplaces();

    /**
     * Returns a set of relations to packages which are recommended in
     * combination with this package.
     *
     * @return array An array of package relations defining recommended packages
     */
    abstract public function getRecommends();

    /**
     * Returns a set of relations to packages which are suggested in combination
     * with this package.
     *
     * @return array An array of package relations defining suggested packages
     */
    abstract public function getSuggests();

    /**
     * Converts the package into a readable and unique string
     *
     * @return string
     */
    public function __toString()
    {
        return $this->getName().'-'.$this->getReleaseType().'-'.$this->getVersion();
    }
}
