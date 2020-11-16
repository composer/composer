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
use Composer\Package\Version\VersionParser;

/**
 * Complete package loaded from local path.
 *
 * @author Giuseppe Mazzapica <giuseppe.mazzapica@gmail.com>
 */
class LinkPackage extends CompletePackage implements CompletePackageInterface
{
    /** @var string */
    private $repoPath;

    /** @var ConstraintInterface[] */
    private $constraints;

    /**
     * Link packages bypass Solver, but for each requirement of a package this method is called
     * to ensure the local package is installed only if required using a compatible set of
     * requirements.
     *
     * @param ConstraintInterface $constraint
     * @param string|null $branchAlias
     * @return void
     *
     * TODO: Unit tests...
     */
    public function checkConstraint(ConstraintInterface $constraint, $branchAlias = null)
    {
        $verifier = $this->constraints ? MultiConstraint::create($this->constraints) : null;
        // No previous requirements or the current requirement is compatible with the previous: OK
        if (!$verifier || $verifier->matches($constraint)) {
            // Push current requirement to stack
            $this->constraints[] = $constraint;

            return;
        }

        $constraintString = $constraint->getPrettyString();
        // If something requires explicitly 'dev-local' we just accept it
        if ('dev-local' === $constraintString) {
            return;
        }

        if ('dev' === VersionParser::parseStability($constraintString)) {
            $versionParser = $this->getVersionParser();
            $alias = $this->getBranchAlias($constraintString);
            if ($alias) {
                // Normalize aliased version and re-run constraint check, using non-dev version
                // or this will be an endless recursive loop.
                $normalized = $versionParser->normalizeBranch(substr($alias, 0, strlen($alias)-4));
                preg_match('{(?:^dev-)?(.+?)(?:-dev)?$}', $normalized, $matches);
                $this->checkConstraint($versionParser->parseConstraints($matches[1]), $constraintString);
                return;
            }

            // Being pragmatical here: if the branch is not aliased but it is the default branch,
            // we accept it anyway to ensure requirements like "dev-master" are always "linkable".
            $mainBranch = $versionParser->normalizeDefaultBranch($constraintString);
            // TODO: Should 'dev-main' be part of `VersionParser::normalizeDefaultBranch()`?
            if ('9999999-dev' === $mainBranch || 'dev-main' === $mainBranch) {
                return;
            }
        }

        throw new LinkPackageConstraintException($this, $constraint, $verifier, $branchAlias);
    }

    /**
     * @param $repoPath
     */
    public function setLinkRepoPath($repoPath)
    {
        $this->repoPath = $repoPath;
    }

    /**
     * @return string
     */
    public function getLinkRepoPath()
    {
        return $this->repoPath;
    }

    /**
     * @return string
     */
    public function getConstraintsPrettyString()
    {
        if ($this->constraints) {
            return MultiConstraint::create($this->constraints)->getPrettyString();
        }

        return 'dev-local';
    }

    /**
     * @param string $branch
     * @return string|null
     */
    private function getBranchAlias($branch)
    {
        $extra = $this->getExtra();
        $branchAlias = isset($extra['branch-alias']) ? $extra['branch-alias'] : array();

        return empty($branchAlias[$branch]) ? null : (string) $branchAlias[$branch];
    }

    /**
     * @return VersionParser
     */
    private function getVersionParser()
    {
        static $versionParser;
        $versionParser or $versionParser = new VersionParser();

        return $versionParser;
    }
}
