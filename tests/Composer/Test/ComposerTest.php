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

use Composer\Composer;
use Composer\Test\TestCase;

class ComposerTest extends TestCase
{
    public function testSetGetPackage()
    {
        $composer = new Composer();
        $package = $this->getMockBuilder('Composer\Package\RootPackageInterface')->getMock();
        $composer->setPackage($package);

        $this->assertSame($package, $composer->getPackage());
    }

    public function testSetGetLocker()
    {
        $composer = new Composer();
        $locker = $this->getMockBuilder('Composer\Package\Locker')->disableOriginalConstructor()->getMock();
        $composer->setLocker($locker);

        $this->assertSame($locker, $composer->getLocker());
    }

    public function testSetGetRepositoryManager()
    {
        $composer = new Composer();
        $manager = $this->getMockBuilder('Composer\Repository\RepositoryManager')->disableOriginalConstructor()->getMock();
        $composer->setRepositoryManager($manager);

        $this->assertSame($manager, $composer->getRepositoryManager());
    }

    public function testSetGetDownloadManager()
    {
        $composer = new Composer();
        $io = $this->getMockBuilder('Composer\IO\IOInterface')->getMock();
        $manager = $this->getMockBuilder('Composer\Downloader\DownloadManager')->setConstructorArgs(array($io))->getMock();
        $composer->setDownloadManager($manager);

        $this->assertSame($manager, $composer->getDownloadManager());
    }

    public function testSetGetInstallationManager()
    {
        $composer = new Composer();
        $manager = $this->getMockBuilder('Composer\Installer\InstallationManager')->getMock();
        $composer->setInstallationManager($manager);

        $this->assertSame($manager, $composer->getInstallationManager());
    }
}
