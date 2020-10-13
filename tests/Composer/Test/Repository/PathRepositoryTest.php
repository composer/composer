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

namespace Composer\Test\Repository;

use Composer\Package\Loader\ArrayLoader;
use Composer\Repository\PathRepository;
use Composer\Test\TestCase;
use Composer\Package\Version\VersionParser;

class PathRepositoryTest extends TestCase
{
    public function testLoadPackageFromFileSystemWithIncorrectPath()
    {
        $this->setExpectedException('RuntimeException');
        $ioInterface = $this->getMockBuilder('Composer\IO\IOInterface')
            ->getMock();

        $config = new \Composer\Config();

        $repositoryUrl = implode(DIRECTORY_SEPARATOR, array(__DIR__, 'Fixtures', 'path', 'missing'));
        $repository = new PathRepository(array('url' => $repositoryUrl), $ioInterface, $config);
        $repository->getPackages();
    }

    public function testLoadPackageFromFileSystemWithVersion()
    {
        $ioInterface = $this->getMockBuilder('Composer\IO\IOInterface')
            ->getMock();

        $config = new \Composer\Config();
        $versionGuesser = null;

        $repositoryUrl = implode(DIRECTORY_SEPARATOR, array(__DIR__, 'Fixtures', 'path', 'with-version'));
        $repository = new PathRepository(array('url' => $repositoryUrl), $ioInterface, $config);
        $repository->getPackages();

        $this->assertSame(1, $repository->count());
        $this->assertTrue($repository->hasPackage($this->getPackage('test/path-versioned', '0.0.2')));
    }

    public function testLoadPackageFromFileSystemWithoutVersion()
    {
        $ioInterface = $this->getMockBuilder('Composer\IO\IOInterface')
            ->getMock();

        $config = new \Composer\Config();
        $versionGuesser = null;

        $repositoryUrl = implode(DIRECTORY_SEPARATOR, array(__DIR__, 'Fixtures', 'path', 'without-version'));
        $repository = new PathRepository(array('url' => $repositoryUrl), $ioInterface, $config);
        $packages = $repository->getPackages();

        $this->assertGreaterThanOrEqual(1, $repository->count());

        $package = $packages[0];
        $this->assertSame('test/path-unversioned', $package->getName());

        $packageVersion = $package->getVersion();
        $this->assertNotEmpty($packageVersion);
    }

    public function testLoadPackageFromFileSystemWithExtraBranchVersion()
    {
        $ioInterface = $this->getMockBuilder('Composer\IO\IOInterface')
            ->getMock();

        $config = new \Composer\Config();
        $versionGuesser = null;

        $repositoryUrl = implode(DIRECTORY_SEPARATOR, array(__DIR__, 'Fixtures', 'path', 'with-branch-version'));
        $repository = new PathRepository(array('url' => $repositoryUrl), $ioInterface, $config);
        $packages = $repository->getPackages();

        $this->assertEquals(1, $repository->count());

        $this->assertTrue($repository->hasPackage($this->getPackage('test/path-branch-versioned', '1.2.x-dev')));
    }

    public function testLoadPackageFromFileSystemWithWildcard()
    {
        $ioInterface = $this->getMockBuilder('Composer\IO\IOInterface')
            ->getMock();

        $config = new \Composer\Config();
        $versionGuesser = null;

        $repositoryUrl = implode(DIRECTORY_SEPARATOR, array(__DIR__, 'Fixtures', 'path', '*'));
        $repository = new PathRepository(array('url' => $repositoryUrl), $ioInterface, $config);
        $packages = $repository->getPackages();
        $result = array();

        $this->assertGreaterThanOrEqual(3, $repository->count());

        foreach ($packages as $package) {
            $result[$package->getName()] = $package->getPrettyVersion();
        }

        ksort($result);
        $this->assertSame(array('test/path-branch-versioned' => '1.2.x-dev', 'test/path-unversioned' => $result['test/path-unversioned'], 'test/path-versioned' => '0.0.2'), $result);
    }

    /**
     * Verify relative repository URLs remain relative, see #4439
     */
    public function testUrlRemainsRelative()
    {
        $ioInterface = $this->getMockBuilder('Composer\IO\IOInterface')
            ->getMock();

        $config = new \Composer\Config();
        $versionGuesser = null;

        // realpath() does not fully expand the paths
        // PHP Bug https://bugs.php.net/bug.php?id=72642
        $repositoryUrl = implode(DIRECTORY_SEPARATOR, array(realpath(realpath(__DIR__)), 'Fixtures', 'path', 'with-version'));
        // getcwd() not necessarily match __DIR__
        // PHP Bug https://bugs.php.net/bug.php?id=73797
        $relativeUrl = ltrim(substr($repositoryUrl, strlen(realpath(realpath(getcwd())))), DIRECTORY_SEPARATOR);

        $repository = new PathRepository(array('url' => $relativeUrl), $ioInterface, $config);
        $packages = $repository->getPackages();

        $this->assertSame(1, $repository->count());

        $package = $packages[0];
        $this->assertSame('test/path-versioned', $package->getName());

        // Convert platform specific separators back to generic URL slashes
        $relativeUrl = str_replace(DIRECTORY_SEPARATOR, '/', $relativeUrl);
        $this->assertSame($relativeUrl, $package->getDistUrl());
    }
}
