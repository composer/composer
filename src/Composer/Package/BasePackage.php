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

namespace Composer\Package;

use Composer\Package\LinkConstraint\LinkConstraintInterface;
use Composer\Repository\RepositoryInterface;

/**
 * Base class for packages providing name storage and default match implementation
 *
 * @author Nils Adermann <naderman@naderman.de>
 */
abstract class BasePackage implements PackageInterface
{
    protected $name;
    protected $repository;

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

        foreach ($this->getProvides() as $link) {
            $names[] = $link->getTarget();
        }

        foreach ($this->getReplaces() as $link) {
            $names[] = $link->getTarget();
        }

        return $names;
    }

    /**
     * Checks if the package matches the given constraint directly or through
     * provided or replaced packages
     *
     * @param string                  $name       Name of the package to be matched
     * @param LinkConstraintInterface $constraint The constraint to verify
     * @return bool                               Whether this package matches the name and constraint
     */
    public function matches($name, LinkConstraintInterface $constraint)
    {
        if ($this->name === $name) {
            return $constraint->matches($this->getReleaseType(), $this->getVersion());
        }

        foreach ($this->getProvides() as $link) {
            if ($link->getTarget() === $name) {
                return $constraint->matches($link->getConstraint());
            }
        }

        foreach ($this->getReplaces() as $link) {
            if ($link->getTarget() === $name) {
                return $constraint->matches($link->getConstraint());
            }
        }

        return false;
    }

    public function getRepository()
    {
        return $this->repository;
    }

    public function setRepository(RepositoryInterface $repository)
    {
        if ($this->repository) {
            throw new \LogicException('A package can only be added to one repository');
        }
        $this->repository = $repository;
    }

    /**
     * Converts the package into a readable and unique string
     *
     * @return string
     */
    public function __toString()
    {
        return $this->getName().'-'.$this->getReleaseType().'-'.$this->getVersion();
    }

    /**
     * Parses a version string and returns an array with the version and its type (dev, alpha, beta, RC, stable)
     *
     * @param string $version
     * @return array
     */
    public static function parseVersion($version)
    {
        if (!preg_match('#^v?(\d+)(\.\d+)?(\.\d+)?-?(?:(beta|RC\d+|alpha|dev)?\d*)$#i', $version, $matches)) {
            throw new \UnexpectedValueException('Invalid version string '.$version);
        }

        return array(
            'version' => $matches[1]
                .(!empty($matches[2]) ? $matches[2] : '.0')
                .(!empty($matches[3]) ? $matches[3] : '.0'),
            'type' => strtolower(!empty($matches[4]) ? $matches[4] : 'stable'),
        );
    }

}
