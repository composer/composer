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
use Composer\Package\MemoryPackage;
use Composer\Package\LinkConstraint\VersionConstraint;
use Composer\Util\Filesystem;

abstract class TestCase extends \PHPUnit_Framework_TestCase
{
    private static $versionParser;

    public static function setUpBeforeClass()
    {
        if (!self::$versionParser) {
            self::$versionParser = new VersionParser();
        }
    }

    protected function getVersionConstraint($operator, $version)
    {
        return new VersionConstraint(
            $operator,
            self::$versionParser->normalize($version)
        );
    }

    protected function getPackage($name, $version)
    {
        $normVersion = self::$versionParser->normalize($version);
        return new MemoryPackage($name, $normVersion, $version);
    }

    protected function ensureDirectoryExistsAndClear($directory)
    {
        $fs = new Filesystem();
        if (is_dir($directory)) {
            $fs->removeDirectory($directory);
        }
        mkdir($directory, 0777, true);
    }
}
