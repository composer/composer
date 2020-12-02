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

use Composer\Semver\Constraint\ConstraintInterface;
use Composer\Semver\Constraint\MultiConstraint;

/**
 * Exception thrown when an attempt is made to use a linked package for conflicting requirements.
 *
 * @author Giuseppe Mazzapica <giuseppe.mazzapica@gmail.com>
 */
class LinkPackageConstraintException extends \RuntimeException
{
    public function __construct(
        LinkPackage $package,
        ConstraintInterface $constraint,
        MultiConstraint $previous,
        $branchAlias = null
    ) {

        $constraintString = $constraint->getPrettyString();
        $message = sprintf(
            "Linked package \"%s\"\ncan't replace %s\nbecause already used to replace\n\"%s\".",
            $package->getName(),
            $branchAlias ? "\"$branchAlias\" (alias of $constraintString)" : "\"$constraintString\"",
            $previous->getPrettyString()
        );

        parent::__construct($message);
    }
}
