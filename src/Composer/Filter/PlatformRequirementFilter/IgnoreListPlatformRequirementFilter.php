<?php

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
        $ignoreAll = $ignoreUpperBound = array();
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

    /**
     * @param string $req
     * @return bool
     */
    public function isIgnored($req)
    {
        if (!PlatformRepository::isPlatformPackage($req)) {
            return false;
        }

        return Preg::isMatch($this->ignoreRegex, $req);
    }

    /**
     * @param string $req
     * @return ConstraintInterface
     * @param bool $allowUpperBoundOverride For conflicts we do not want the upper bound to be skipped
     */
    public function filterConstraint($req, ConstraintInterface $constraint, $allowUpperBoundOverride = true)
    {
        if (!PlatformRepository::isPlatformPackage($req)) {
            return $constraint;
        }

        if (!$allowUpperBoundOverride || !Preg::isMatch($this->ignoreUpperBoundRegex, $req)) {
            return $constraint;
        }

        if (Preg::isMatch($this->ignoreRegex, $req)) {
            return new MatchAllConstraint;
        }

        $intervals = Intervals::get($constraint);
        $last = end($intervals['numeric']);
        if ($last !== false && (string) $last->getEnd() !== (string) Interval::untilPositiveInfinity()) {
            $constraint = new MultiConstraint(array($constraint, new Constraint('>=', $last->getEnd()->getVersion())), false);
        }

        return $constraint;
    }
}
