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

namespace Composer\Test;

use Composer\Package\Version\VersionParser;
use Composer\Package\Package;
use Composer\Package\AliasPackage;
use Composer\Package\LinkConstraint\VersionConstraint;
use Composer\Util\Filesystem;

abstract class TestCase extends \PHPUnit_Framework_TestCase
{
    private static $parser;

    protected $testDir;

    /**
     * Init test directory if needed
     */
    protected function setUp()
    {
        if ($this->testDir) {
            $this->ensureDirectoryExistsAndClear($this->testDir);
        }
    }

    /**
     * Clean test directory if it was used
     */
    protected function tearDown()
    {
        if ($this->testDir) {
            $this->clearDirectory($this->testDir);
        }
    }

    protected static function getVersionParser()
    {
        if (!self::$parser) {
            self::$parser = new VersionParser();
        }

        return self::$parser;
    }

    protected function getVersionConstraint($operator, $version)
    {
        $constraint = new VersionConstraint(
            $operator,
            self::getVersionParser()->normalize($version)
        );

        $constraint->setPrettyString($operator.' '.$version);

        return $constraint;
    }

    protected function getPackage($name, $version, $class = 'Composer\Package\Package')
    {
        $normVersion = self::getVersionParser()->normalize($version);

        return new $class($name, $normVersion, $version);
    }

    protected function getAliasPackage($package, $version)
    {
        $normVersion = self::getVersionParser()->normalize($version);

        return new AliasPackage($package, $normVersion, $version);
    }

    protected function ensureDirectoryExistsAndClear($directory)
    {
        $this->clearDirectory($directory);
        mkdir($directory, 0777, true);
    }

    protected function clearDirectory($directory)
    {
        $fs = new Filesystem();
        if (is_dir($directory)) {
            $fs->removeDirectory($directory);
        }
    }
}
