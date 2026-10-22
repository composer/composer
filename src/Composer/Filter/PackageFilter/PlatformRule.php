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

namespace Composer\Filter\PackageFilter;

use Composer\Filter\PlatformRequirementFilter\IgnoreAllPlatformRequirementFilter;
use Composer\Filter\PlatformRequirementFilter\IgnoreListPlatformRequirementFilter;
use Composer\Filter\PlatformRequirementFilter\PlatformRequirementFilterInterface;
use Composer\Repository\PlatformRepository;
use Composer\Semver\Constraint\Constraint;
use Composer\Semver\Constraint\ConstraintInterface;

/**
 * Rejects versions whose platform requirements the current platform does not satisfy
 *
 * @internal
 * @final
 */
class PlatformRule implements RejectionRuleInterface
{
    /** @var array<string, ConstraintInterface[]> */
    private $platformConstraints = [];
    /** @var PlatformRequirementFilterInterface */
    private $platformRequirementFilter;

    public function __construct(PlatformRepository $platformRepo, PlatformRequirementFilterInterface $platformRequirementFilter)
    {
        foreach ($platformRepo->getPackages() as $package) {
            $this->platformConstraints[$package->getName()][] = new Constraint('==', $package->getVersion());
        }
        $this->platformRequirementFilter = $platformRequirementFilter;
    }

    public function getRejections(array $packages): array
    {
        if (\count($this->platformConstraints) === 0 || $this->platformRequirementFilter instanceof IgnoreAllPlatformRequirementFilter) {
            return [];
        }

        $rejections = [];
        foreach ($packages as $key => $package) {
            $unsatisfied = [];
            foreach ($package->getRequires() as $name => $link) {
                if (!PlatformRepository::isPlatformPackage($name) || $this->platformRequirementFilter->isIgnored($name)) {
                    continue;
                }

                if (!isset($this->platformConstraints[$name])) {
                    // the platform cannot validate a requirement on an unknown platform package, so it is not installable
                    $unsatisfied[] = ['link' => $link, 'problem' => 'is missing from your platform'];
                    continue;
                }

                foreach ($this->platformConstraints[$name] as $providedConstraint) {
                    if ($link->getConstraint()->matches($providedConstraint)) {
                        continue 2;
                    }
                    if ($this->platformRequirementFilter instanceof IgnoreListPlatformRequirementFilter && $this->platformRequirementFilter->isUpperBoundIgnored($name)) {
                        $filteredConstraint = $this->platformRequirementFilter->filterConstraint($name, $link->getConstraint());
                        if ($filteredConstraint->matches($providedConstraint)) {
                            continue 2;
                        }
                    }
                }

                $unsatisfied[] = ['link' => $link, 'problem' => 'is not satisfied by your platform'];
            }

            if (\count($unsatisfied) > 0) {
                $rejections[$key] = RejectionReason::platform($package->getName(), $package->getPrettyVersion(), $unsatisfied);
            }
        }

        return $rejections;
    }
}
