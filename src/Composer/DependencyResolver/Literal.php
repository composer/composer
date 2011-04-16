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

namespace Composer\DependencyResolver;

/**
 * @author Nils Adermann <naderman@naderman.de>
 */
class Literal
{
    protected $wanted;

    public function __construct(Package $package, $wanted)
    {
        $this->package = $package;
        $this->wanted = $wanted;
    }

    public function isWanted()
    {
        return $this->wanted;
    }

    public function getPackage()
    {
        return $this->package;
    }

    public function getPackageId()
    {
        return $this->package->getId();
    }

    public function getId()
    {
        return (($this->wanted) ? 1 : -1) * $this->package->getId();
    }

    public function __toString()
    {
        return ($this->isWanted() ? '+' : '-').$this->getPackage();
    }

    public function inverted()
    {
        return new Literal($this->getPackage(), !$this->isWanted());
    }

    public function equals(Literal $b)
    {
        return $this->getId() === $b->getId();
    }
}
