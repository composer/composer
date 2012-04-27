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
use Composer\Package\LinkConstraint\VersionConstraint;
use Composer\Repository\RepositoryInterface;
use Composer\Repository\PlatformRepository;

/**
 * Base class for packages providing name storage and default match implementation
 *
 * @author Nils Adermann <naderman@naderman.de>
 */
abstract class BasePackage implements PackageInterface
{
    public static $supportedLinkTypes = array(
        'require'   => array('description' => 'requires', 'method' => 'requires'),
        'conflict'  => array('description' => 'conflicts', 'method' => 'conflicts'),
        'provide'   => array('description' => 'provides', 'method' => 'provides'),
        'replace'   => array('description' => 'replaces', 'method' => 'replaces'),
        'require-dev' => array('description' => 'requires (for development)', 'method' => 'devRequires'),
    );

    protected $name;
    protected $prettyName;

    protected $repository;
    protected $id;

    /**
     * All descendants' constructors should call this parent constructor
     *
     * @param string $name The package's name
     */
    public function __construct($name)
    {
        $this->prettyName = $name;
        $this->name = strtolower($name);
        $this->id = -1;
    }

    /**
     * {@inheritDoc}
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * {@inheritDoc}
     */
    public function getPrettyName()
    {
        return $this->prettyName;
    }

    /**
     * {@inheritDoc}
     */
    public function getNames()
    {
        $names = array(
            $this->getName() => true,
        );

        foreach ($this->getProvides() as $link) {
            $names[$link->getTarget()] = true;
        }

        foreach ($this->getReplaces() as $link) {
            $names[$link->getTarget()] = true;
        }

        return array_keys($names);
    }

    /**
     * {@inheritDoc}
     */
    public function setId($id)
    {
        $this->id = $id;
    }

    /**
     * {@inheritDoc}
     */
    public function getId()
    {
        return $this->id;
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
            return $constraint->matches(new VersionConstraint('==', $this->getVersion()));
        }

        foreach ($this->getProvides() as $link) {
            if ($link->getTarget() === $name && $constraint->matches($link->getConstraint())) {
                return true;
            }
        }

        foreach ($this->getReplaces() as $link) {
            if ($link->getTarget() === $name && $constraint->matches($link->getConstraint())) {
                return true;
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
     * checks if this package is a platform package
     *
     * @return boolean
     */
    public function isPlatform()
    {
        return $this->getRepository() instanceof PlatformRepository;
    }

    /**
     * Returns package unique name, constructed from name, version and release type.
     *
     * @return string
     */
    public function getUniqueName()
    {
        return $this->getName().'-'.$this->getVersion();
    }

    public function equals(PackageInterface $package)
    {
        $self = $this;
        if ($this instanceof AliasPackage) {
            $self = $this->getAliasOf();
        }
        if ($package instanceof AliasPackage) {
            $package = $package->getAliasOf();
        }
        return $package === $self;
    }

    /**
     * Converts the package into a readable and unique string
     *
     * @return string
     */
    public function __toString()
    {
        return $this->getUniqueName();
    }

    public function __clone()
    {
        $this->repository = null;
    }
}
