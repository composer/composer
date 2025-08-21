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
     *  * ^1.2.0 + 1.3.0          -> ^1.3.0
     *  * ^1.2 || ^2.3 + 1.3.0    -> ^1.3 || ^2.3
     *  * ^1.2 || ^2.3 + 2.4.0    -> ^1.2 || ^2.4
     *  * ^3@dev + 3.2.99999-dev  -> ^3.2@dev
     *  * ~2 + 2.0-beta.1         -> ~2
     *  * ~2.0.0 + 2.0.3          -> ~2.0.3
     *  * ~2.0 + 2.0.3            -> ^2.0.3
     *  * dev-master + dev-master -> dev-master
     *  * * + 1.2.3               -> >=1.2.3
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

        $major = Preg::replace('{^([1-9]+|0\.\d+).*}', '$1', $version);
        $versionWithoutSuffix = Preg::replace('{(?:\.(?:0|9999999))+(-dev)?$}', '', $version);
        $newPrettyConstraint = '^'.$versionWithoutSuffix;

        // not a simple stable version, abort
        if (!Preg::isMatch('{^\^\d+(\.\d+)*$}', $newPrettyConstraint)) {
            return $prettyConstraint;
        }

        $pattern = '{
            (?<=,|\ |\||^) # leading separator
            (?P<constraint>
                \^v?'.$major.'(?:\.\d+)* # e.g. ^2.anything
                | ~v?'.$major.'(?:\.\d+){1,3} # e.g. ~2.2 or ~2.2.2 or ~2.2.2.2
                | v?'.$major.'(?:\.[*x])+ # e.g. 2.* or 2.*.* or 2.x.x.x etc
                | >=v?\d(?:\.\d+)* # e.g. >=2 or >=1.2 etc
                | \* # full wildcard
            )
            (?=,|$|\ |\||@) # trailing separator
        }x';
        if (Preg::isMatchAllWithOffsets($pattern, $prettyConstraint, $matches)) {
            $modified = $prettyConstraint;
            foreach (array_reverse($matches['constraint']) as $match) {
                assert(is_string($match[0]));
                $suffix = '';
                if (substr_count($match[0], '.') === 2 && substr_count($versionWithoutSuffix, '.') === 1) {
                    $suffix = '.0';
                }
                if (str_starts_with($match[0], '~') && substr_count($match[0], '.') !== 1) {
                    // take as many version bits from the current version as we have in the constraint to bump it without making it more specific
                    $versionBits = explode('.', $versionWithoutSuffix);
                    $versionBits = array_pad($versionBits, substr_count($match[0], '.') + 1, '0');
                    $replacement = '~'.implode('.', array_slice($versionBits, 0, substr_count($match[0], '.') + 1));
                } elseif ($match[0] === '*' || str_starts_with($match[0], '>=')) {
                    $replacement = '>='.$versionWithoutSuffix.$suffix;
                } else {
                    $replacement = $newPrettyConstraint.$suffix;
                }
                $modified = substr_replace($modified, $replacement, $match[1], Platform::strlen($match[0]));
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
