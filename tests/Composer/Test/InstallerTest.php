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

use Composer\Installer;
use Composer\Repository\ArrayRepository;
use Composer\Repository\RepositoryManager;
use Composer\Repository\RepositoryInterface;
use Composer\Package\PackageInterface;
use Composer\Package\Link;
use Composer\Test\Mock\WritableRepositoryMock;
use Composer\Test\Mock\InstallationManagerMock;

class InstallerTest extends TestCase
{
    /**
     * @dataProvider provideInstaller
     */
    public function testInstaller(array $expectedInstalled, array $expectedUpdated, array $expectedUninstalled, PackageInterface $package, RepositoryInterface $repository)
    {
        $io = $this->getMock('Composer\IO\IOInterface');

        $package = $this->getPackage('A', '1.0.0');
        $package->setRequires(array(
            new Link('A', 'B', $this->getVersionConstraint('=', '1.0.0')),
        ));

        $downloadManager = $this->getMock('Composer\Downloader\DownloadManager');
        $config = $this->getMock('Composer\Config');

        $repositoryManager = new RepositoryManager($io, $config);
        $repositoryManager->setLocalRepository(new WritableRepositoryMock());
        $repositoryManager->setLocalDevRepository(new WritableRepositoryMock());
        $repositoryManager->addRepository($repository);

        $locker = $this->getMockBuilder('Composer\Package\Locker')->disableOriginalConstructor()->getMock();
        $installationManager = new InstallationManagerMock();
        $eventDispatcher = $this->getMockBuilder('Composer\Script\EventDispatcher')->disableOriginalConstructor()->getMock();
        $autoloadGenerator = $this->getMock('Composer\Autoload\AutoloadGenerator');

        $installer = new Installer($io, $package, $downloadManager, $repositoryManager, $locker, $installationManager, $eventDispatcher, $autoloadGenerator);
        $result = $installer->run();
        $this->assertTrue($result);

        $installed = $installationManager->getInstalledPackages();
        $this->assertSame($expectedInstalled, array_map(array($this, 'getPackageString'), $installed));

        $updated = $installationManager->getUpdatedPackages();
        $this->assertSame($expectedUpdated, array_map(array($this, 'getPackageString'), $updated));

        $uninstalled = $installationManager->getUninstalledPackages();
        $this->assertSame($expectedUninstalled, array_map(array($this, 'getPackageString'), $uninstalled));
    }

    public function provideInstaller()
    {
        $cases = array();

        // when A requires B and B requires A, and A is a non-published root package
        // the install of B should succeed

        $a = $this->getPackage('A', '1.0.0');
        $a->setRequires(array(
            new Link('A', 'B', $this->getVersionConstraint('=', '1.0.0')),
        ));
        $b = $this->getPackage('B', '1.0.0');
        $b->setRequires(array(
            new Link('B', 'A', $this->getVersionConstraint('=', '1.0.0')),
        ));

        $cases[] = array(
            array('b-1.0.0.0'),
            array(),
            array(),
            $a,
            new ArrayRepository(array($b)),
        );

        // #480: when A requires B and B requires A, and A is a published root package
        // only B should be installed, as A is the root

        $a = $this->getPackage('A', '1.0.0');
        $a->setRequires(array(
            new Link('A', 'B', $this->getVersionConstraint('=', '1.0.0')),
        ));
        $b = $this->getPackage('B', '1.0.0');
        $b->setRequires(array(
            new Link('B', 'A', $this->getVersionConstraint('=', '1.0.0')),
        ));

        $cases[] = array(
            array('b-1.0.0.0'),
            array(),
            array(),
            $a,
            new ArrayRepository(array($a, $b)),
        );

        return $cases;
    }

    public function getPackageString(PackageInterface $package)
    {
        return (string) $package;
    }
}
