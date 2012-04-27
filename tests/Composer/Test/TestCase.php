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
    protected function getVersionConstraint($operator, $version)
    {
        $versionParser = new VersionParser();
        return new VersionConstraint(
            $operator,
            $versionParser->normalize($version)
        );
    }

    protected function getPackage($name, $version)
    {
        $versionParser = new VersionParser();
        $normVersion = $versionParser->normalize($version);
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
