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

namespace Composer\Filter\PlatformRequirementFilter;

use Composer\Package\BasePackage;
use Composer\Pcre\Preg;
use Composer\Repository\PlatformRepository;
use Composer\Semver\Constraint\Constraint;
use Composer\Semver\Constraint\ConstraintInterface;
use Composer\Semver\Constraint\MatchAllConstraint;
use Composer\Semver\Constraint\MultiConstraint;
use Composer\Semver\Interval;
use Composer\Semver\Intervals;

final class IgnoreListPlatformRequirementFilter implements PlatformRequirementFilterInterface
{
    /**
     * @var non-empty-string
     */
    private $ignoreRegex;

    /**
     * @var non-empty-string
     */
    private $ignoreUpperBoundRegex;

    /**
     * @param string[] $reqList
     */
    public function __construct(array $reqList)
    {
        $ignoreAll = $ignoreUpperBound = [];
        foreach ($reqList as $req) {
            if (substr($req, -1) === '+') {
                $ignoreUpperBound[] = substr($req, 0, -1);
            } else {
                $ignoreAll[] = $req;
            }
        }
        $this->ignoreRegex = BasePackage::packageNamesToRegexp($ignoreAll);
        $this->ignoreUpperBoundRegex = BasePackage::packageNamesToRegexp($ignoreUpperBound);
    }

    public function isIgnored(string $req): bool
    {
        if (!PlatformRepository::isPlatformPackage($req)) {
            return false;
        }

        return Preg::isMatch($this->ignoreRegex, $req);
    }

    public function filterConstraint(string $req, ConstraintInterface $constraint): ConstraintInterface
    {
        if (!PlatformRepository::isPlatformPackage($req)) {
            return $constraint;
        }

        if (!Preg::isMatch($this->ignoreUpperBoundRegex, $req)) {
            return $constraint;
        }

        if (Preg::isMatch($this->ignoreRegex, $req)) {
            return new MatchAllConstraint;
        }

        $intervals = Intervals::get($constraint);
        $last = end($intervals['numeric']);
        if ($last !== false && (string) $last->getEnd() !== (string) Interval::untilPositiveInfinity()) {
            $constraint = new MultiConstraint([$constraint, new Constraint('>=', $last->getEnd()->getVersion())], false);
        }

        return $constraint;
    }
}
