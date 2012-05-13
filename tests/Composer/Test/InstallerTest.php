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
use Composer\Test\Mock\ConsoleIOMock;

class InstallerTest extends TestCase
{
    public function testInstallerDryRunAndDev()
    {
        $rootPackage = $this->getPackage('A', '1.0.0');
        $rootPackage->setRequires(array(
            new Link('A', 'B', $this->getVersionConstraint('=', '1.0.0')),
        ));
        $rootPackage->setDevRequires(array(
            new Link('A', 'C', $this->getVersionConstraint('=', '1.0.0')),
        ));

        $b = $this->getPackage('B', '1.0.0');
        $c = $this->getPackage('C', '1.0.0');

        $installer = $this->createInstaller($rootPackage, array(new ArrayRepository(array($b, $c))));
        $installer->setDevMode(true);
        $installer->setDryRun(true);
        $installer->run();

        $io = $this->getPropertyFromInstaller($installer, 'io');
        $this->assertSame("<info>Installing dependencies</info>
Installing B (1.0.0)
<info>Installing dev dependencies</info>
Installing C (1.0.0)", (string) $io);
    }

    /**
     * @dataProvider provideInstaller
     */
    public function testInstaller(PackageInterface $rootPackage, $repositories, array $options)
    {
        $installer = $this->createInstaller($rootPackage, $repositories);
        $result = $installer->run();
        $this->assertTrue($result);

        $expectedInstalled   = isset($options['install']) ? $options['install'] : array();
        $expectedUpdated     = isset($options['update']) ? $options['update'] : array();
        $expectedUninstalled = isset($options['uninstall']) ? $options['uninstall'] : array();

        $installationManager = $this->getPropertyFromInstaller($installer, 'installationManager');

        $installed = $installationManager->getInstalledPackages();
        $this->assertSame($expectedInstalled, $installed);

        $updated = $installationManager->getUpdatedPackages();
        $this->assertSame($expectedUpdated, $updated);

        $uninstalled = $installationManager->getUninstalledPackages();
        $this->assertSame($expectedUninstalled, $uninstalled);
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
            $a,
            new ArrayRepository(array($b)),
            array(
                'install' => array($b)
            ),
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
            $a,
            new ArrayRepository(array($a, $b)),
            array(
                'install' => array($b)
            ),
        );

        return $cases;
    }

    protected function createInstaller(PackageInterface $rootPackage, $repositories = array())
    {
        $io = new ConsoleIOMock();

        $downloadManager = $this->getMock('Composer\Downloader\DownloadManager');
        $config = $this->getMock('Composer\Config');

        $repositoryManager = new RepositoryManager($io, $config);
        $repositoryManager->setLocalRepository(new WritableRepositoryMock());
        $repositoryManager->setLocalDevRepository(new WritableRepositoryMock());

        if (!is_array($repositories)) {
            $repositories = array($repositories);
        }
        foreach ($repositories as $repository) {
            $repositoryManager->addRepository($repository);
        }

        $locker = $this->getMockBuilder('Composer\Package\Locker')->disableOriginalConstructor()->getMock();
        $installationManager = new InstallationManagerMock();
        $eventDispatcher = $this->getMockBuilder('Composer\Script\EventDispatcher')->disableOriginalConstructor()->getMock();
        $autoloadGenerator = $this->getMock('Composer\Autoload\AutoloadGenerator');

        return new Installer($io, clone $rootPackage, $downloadManager, $repositoryManager, $locker, $installationManager, $eventDispatcher, $autoloadGenerator);
    }

    protected function getPropertyFromInstaller(Installer $installer, $property)
    {
        $reflection = new \ReflectionProperty($installer, $property);
        $reflection->setAccessible(true);

        return  $reflection->getValue($installer);
    }
}
