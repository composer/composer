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

namespace Composer\Package\Version;

use Composer\Package\LinkConstraint\MultiConstraint;
use Composer\Package\LinkConstraint\VersionConstraint;

/**
 * Version parser
 *
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class VersionParser
{
    private $modifierRegex = '[.-]?(?:(beta|RC|alpha|patch|pl|p)(?:[.-]?(\d+))?)?([.-]?dev)?';

    /**
     * Normalizes a version string to be able to perform comparisons on it
     *
     * @param string $version
     * @return array
     */
    public function normalize($version)
    {
        $version = trim($version);

        if (in_array($version, array('master', 'trunk'))) {
            return '9999999-dev';
        }

        // match classical versioning
        if (preg_match('{^v?(\d{1,3})(\.\d+)?(\.\d+)?(\.\d+)?'.$this->modifierRegex.'$}i', $version, $matches)) {
            $version = $matches[1]
                .(!empty($matches[2]) ? $matches[2] : '.0')
                .(!empty($matches[3]) ? $matches[3] : '.0')
                .(!empty($matches[4]) ? $matches[4] : '.0');
            $index = 5;
        } elseif (preg_match('{^v?(\d{4}(?:[.:-]?\d{2}){1,6}(?:[.:-]?\d{1,3})?)'.$this->modifierRegex.'$}i', $version, $matches)) { // match date-based versioning
            $version = preg_replace('{\D}', '-', $matches[1]);
            $index = 2;
        }

        // add version modifiers if a version was matched
        if (isset($index)) {
            if (!empty($matches[$index])) {
                $mod = array('{^pl?$}', '{^rc$}');
                $modNormalized = array('patch', 'RC');
                $version .= '-'.preg_replace($mod, $modNormalized, strtolower($matches[$index]))
                    . (!empty($matches[$index+1]) ? $matches[$index+1] : '');
            }

            if (!empty($matches[$index+2])) {
                $version .= '-dev';
            }

            return $version;
        }

        throw new \UnexpectedValueException('Invalid version string '.$version);
    }

    /**
     * Normalizes a branch name to be able to perform comparisons on it
     *
     * @param string $version
     * @return array
     */
    public function normalizeBranch($name)
    {
        $name = trim($name);

        if (in_array($name, array('master', 'trunk'))) {
            return $this->normalize($name);
        }

        if (preg_match('#^v?(\d+)(\.(?:\d+|[x*]))?(\.(?:\d+|[x*]))?(\.(?:\d+|[x*]))?$#i', $name, $matches)) {
            $version = '';
            for ($i = 1; $i < 5; $i++) {
                $version .= isset($matches[$i]) ? str_replace('*', 'x', $matches[$i]) : '.x';
            }
            return str_replace('x', '9999999', $version).'-dev';
        }

        throw new \UnexpectedValueException('Invalid branch name '.$branch);
    }

    /**
     * Parses as constraint string into LinkConstraint objects
     *
     * @param string $constraints
     * @return \Composer\Package\LinkConstraint\LinkConstraintInterface
     */
    public function parseConstraints($constraints)
    {
        $constraints = preg_split('{\s*,\s*}', trim($constraints));

        if (count($constraints) > 1) {
            $constraintObjects = array();
            foreach ($constraints as $key => $constraint) {
                $constraintObjects = array_merge($constraintObjects, $this->parseConstraint($constraint));
            }
        } else {
            $constraintObjects = $this->parseConstraint($constraints[0]);
        }

        if (1 === count($constraintObjects)) {
            return $constraintObjects[0];
        }

        return new MultiConstraint($constraintObjects);
    }

    private function parseConstraint($constraint)
    {
        if ('*' === $constraint || '*.*' === $constraint || '*.*.*' === $constraint || '*.*.*.*' === $constraint) {
            return array();
        }

        // match wildcard constraints
        if (preg_match('{^(\d+)(?:\.(\d+))?(?:\.(\d+))?\.\*$}', $constraint, $matches)) {
            if (isset($matches[3])) {
                $lowVersion = $matches[1] . '.' . $matches[2] . '.' . $matches[3] . '.0';
                $highVersion = $matches[1] . '.' . $matches[2] . '.' . $matches[3] . '.9999999';
            } elseif (isset($matches[2])) {
                $lowVersion = $matches[1] . '.' . $matches[2] . '.0.0';
                $highVersion = $matches[1] . '.' . $matches[2] . '.9999999.9999999';
            } else {
                $lowVersion = $matches[1] . '.0.0.0';
                $highVersion = $matches[1] . '.9999999.9999999.9999999';
            }

            return array(
                new VersionConstraint('>=', $lowVersion),
                new VersionConstraint('<', $highVersion),
            );
        }

        // match operators constraints
        if (preg_match('{^(>=?|<=?|==?)?\s*(\d+.*)}', $constraint, $matches)) {
            $version = $this->normalize($matches[2]);

            return array(new VersionConstraint($matches[1] ?: '=', $version));
        }

        throw new \UnexpectedValueException('Could not parse version constraint '.$constraint);
    }
}
