<?php

/*
 * This file is part of Composer.
 *
 * (c) Nils Adermann <naderman@naderman.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Composer\DependencyResolver;

use Composer\DependencyResolver\RelationConstraint\RelationConstraintInterface;

/**
 * @author Nils Adermann <naderman@naderman.de>
 */
class PackageRelation
{
    protected $fromPackageName;
    protected $toPackageName;
    protected $constraint;
    protected $description;

    public function __construct($fromPackageName, $toPackageName, RelationConstraintInterface $constraint, $description = 'relates to')
    {
        $this->fromPackageName = $fromPackageName;
        $this->toPackageName = $toPackageName;
        $this->constraint = $constraint;
        $this->description = $description;
    }

    public function getToPackageName()
    {
        return $this->toPackageName;
    }

    public function getFromPackageName()
    {
        return $this->fromPackageName;
    }

    public function getConstraint()
    {
        return $this->constraint;
    }

    public function __toString()
    {
        return $this->fromPackageName.' '.$this->description.' '.$this->toPackageName.' ('.$this->constraint.')';
    }
}
