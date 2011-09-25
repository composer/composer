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
    /**
     * Normalizes a version string to be able to perform comparisons on it
     *
     * @param string $version
     * @return array
     */
    public function normalize($version)
    {
        $version = trim($version);

        // match classical versioning
        if (preg_match('{^v?(\d{1,3})(\.\d+)?(\.\d+)?-?((?:beta|RC|alpha)\d*)?(-?dev)?$}i', $version, $matches)) {
            return $matches[1]
                .(!empty($matches[2]) ? $matches[2] : '.0')
                .(!empty($matches[3]) ? $matches[3] : '.0')
                .(!empty($matches[4]) ? '-'.strtolower($matches[4]) : '')
                .(!empty($matches[5]) ? '-dev' : '');
        }

        // match date-based versioning
        if (preg_match('{^v?(\d{4}(?:[.:-]?\d{2}){1,6}(?:[.:-]?\d{1})?)((?:beta|RC|alpha)\d*)?(-?dev)?$}i', $version, $matches)) {
            return preg_replace('{\D}', '-', $matches[1])
                .(!empty($matches[2]) ? '-'.strtolower($matches[2]) : '')
                .(!empty($matches[3]) ? '-dev' : '');
        }

        throw new \UnexpectedValueException('Invalid version string '.$version);
    }

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
        if ('*' === $constraint || '*.*' === $constraint || '*.*.*' === $constraint) {
            return array();
        }

        // match wildcard constraints
        if (preg_match('{^(\d+)(?:\.(\d+))?\.\*$}', $constraint, $matches)) {
            $lowVersion = $matches[1] . '.' . (isset($matches[2]) ? $matches[2] : '0') . '.0';
            $highVersion = (isset($matches[2])
                ? $matches[1] . '.' . ($matches[2]+1)
                : ($matches[1]+1) . '.0')
                . '.0';

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
