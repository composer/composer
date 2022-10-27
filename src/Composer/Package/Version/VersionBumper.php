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

namespace Composer\Package\Version;

use Composer\Package\PackageInterface;
use Composer\Package\Loader\ArrayLoader;
use Composer\Package\Dumper\ArrayDumper;
use Composer\Pcre\Preg;
use Composer\Semver\Constraint\Constraint;
use Composer\Semver\Constraint\ConstraintInterface;
use Composer\Semver\Constraint\MatchAllConstraint;
use Composer\Semver\Intervals;
use Composer\Util\Platform;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 * @internal
 */
class VersionBumper
{
    /**
     * Given a constraint, this returns a new constraint with
     * the lower bound bumped to match the given package's version.
     *
     * For example:
     *  * ^1.0 + 1.2.1            -> ^1.2.1
     *  * ^1.2 + 1.2.0            -> ^1.2
     *  * * + 1.2.0               -> >= 1.2
     *  * ^1.2 || ^2.3 + 1.3.0    -> ^1.3 || ^2.3
     *  * ^1.2 || ^2.3 + 2.4.0    -> ^1.2 || ^2.4
     *  * ^3@dev + 3.2.99999-dev  -> ^3.2@dev
     *  * ~2 + 2.0-beta.1         -> ~2
     *  * dev-master + dev-master -> dev-master
     */
    public function bumpRequirement(ConstraintInterface $constraint, PackageInterface $package): string
    {
        $parser = new VersionParser();
        $prettyConstraint = $constraint->getPrettyString();
        if (str_starts_with($constraint->getPrettyString(), 'dev-')) {
            return $prettyConstraint;
        }

        $version = $package->getVersion();
        if (str_starts_with($package->getVersion(), 'dev-')) {
            $loader = new ArrayLoader($parser);
            $dumper = new ArrayDumper();
            $extra = $loader->getBranchAlias($dumper->dump($package));

            // dev packages without branch alias cannot be processed
            if (null === $extra || $extra === VersionParser::DEFAULT_BRANCH_ALIAS) {
                return $prettyConstraint;
            }

            $version = $extra;
        }

        $intervals = Intervals::get($constraint);

        // complex constraints with branch names are not bumped
        if (\count($intervals['branches']['names']) > 0) {
            return $prettyConstraint;
        }

        $major = Preg::replace('{^(\d+).*}', '$1', $version);
        $newPrettyConstraint = Preg::replace('{(?:\.(?:0|9999999))+(-dev)?$}', '', $version);

        // not a simple stable version, abort
        if (!Preg::isMatch('{^\d+(\.\d+)*$}', $newPrettyConstraint)) {
            return $prettyConstraint;
        }

        if ($constraint instanceof MatchAllConstraint) {
            return '>= ' . $newPrettyConstraint;
        }

        $newPrettyConstraint = '^'.$newPrettyConstraint;

        $pattern = '{
            (?<=,|\ |\||^) # leading separator
            (?P<constraint>
                \^'.$major.'(?:\.\d+)* # e.g. ^2.anything
                | ~'.$major.'(?:\.\d+)? # e.g. ~2 or ~2.2 but no more
                | '.$major.'(?:\.[*x])+ # e.g. 2.* or 2.*.* or 2.x.x.x etc
            )
            (?=,|$|\ |\||@) # trailing separator
        }x';
        if (Preg::isMatchAllWithOffsets($pattern, $prettyConstraint, $matches)) {
            $modified = $prettyConstraint;
            foreach (array_reverse($matches['constraint']) as $match) {
                $modified = substr_replace($modified, $newPrettyConstraint, $match[1], Platform::strlen($match[0]));
            }

            // if it is strictly equal to the previous one then no need to change anything
            $newConstraint = $parser->parseConstraints($modified);
            if (Intervals::isSubsetOf($newConstraint, $constraint) && Intervals::isSubsetOf($constraint, $newConstraint)) {
                return $prettyConstraint;
            }

            return $modified;
        }

        return $prettyConstraint;
    }
}
