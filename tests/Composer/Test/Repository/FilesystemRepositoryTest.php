<?php declare(strict_types=1);

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

use Composer\Package\RootPackageInterface;
use Composer\Repository\FilesystemRepository;
use Composer\Test\TestCase;
use Composer\Json\JsonFile;
use Composer\Util\Filesystem;

class FilesystemRepositoryTest extends TestCase
{
    public function testRepositoryRead(): void
    {
        $json = $this->createJsonFileMock();

        $repository = new FilesystemRepository($json);

        $json
            ->expects($this->once())
            ->method('read')
            ->will($this->returnValue([
                ['name' => 'package1', 'version' => '1.0.0-beta', 'type' => 'vendor'],
            ]));
        $json
            ->expects($this->once())
            ->method('exists')
            ->will($this->returnValue(true));

        $packages = $repository->getPackages();

        self::assertCount(1, $packages);
        self::assertSame('package1', $packages[0]->getName());
        self::assertSame('1.0.0.0-beta', $packages[0]->getVersion());
        self::assertSame('vendor', $packages[0]->getType());
    }

    public function testCorruptedRepositoryFile(): void
    {
        self::expectException('Composer\Repository\InvalidRepositoryException');
        $json = $this->createJsonFileMock();

        $repository = new FilesystemRepository($json);

        $json
            ->expects($this->once())
            ->method('read')
            ->will($this->returnValue('foo'));
        $json
            ->expects($this->once())
            ->method('exists')
            ->will($this->returnValue(true));

        $repository->getPackages();
    }

    public function testUnexistentRepositoryFile(): void
    {
        $json = $this->createJsonFileMock();

        $repository = new FilesystemRepository($json);

        $json
            ->expects($this->once())
            ->method('exists')
            ->will($this->returnValue(false));

        self::assertEquals([], $repository->getPackages());
    }

    public function testRepositoryWrite(): void
    {
        $json = $this->createJsonFileMock();

        $repoDir = realpath(sys_get_temp_dir()).'/repo_write_test/';
        $fs = new Filesystem();
        $fs->removeDirectory($repoDir);

        $repository = new FilesystemRepository($json);
        $im = $this->getMockBuilder('Composer\Installer\InstallationManager')
            ->disableOriginalConstructor()
            ->getMock();
        $im->expects($this->exactly(2))
            ->method('getInstallPath')
            ->will($this->returnValue($repoDir.'/vendor/woop/woop'));

        $json
            ->expects($this->once())
            ->method('read')
            ->will($this->returnValue([]));
        $json
            ->expects($this->once())
            ->method('getPath')
            ->will($this->returnValue($repoDir.'/vendor/composer/installed.json'));
        $json
            ->expects($this->once())
            ->method('exists')
            ->will($this->returnValue(true));
        $json
            ->expects($this->once())
            ->method('write')
            ->with([
                'packages' => [
                    ['name' => 'mypkg', 'type' => 'library', 'version' => '0.1.10', 'version_normalized' => '0.1.10.0', 'install-path' => '../woop/woop'],
                    ['name' => 'mypkg2', 'type' => 'library', 'version' => '1.2.3', 'version_normalized' => '1.2.3.0', 'install-path' => '../woop/woop'],
                ],
                'dev' => true,
                'dev-package-names' => ['mypkg2'],
            ]);

        $repository->setDevPackageNames(['mypkg2']);
        $repository->addPackage(self::getPackage('mypkg2', '1.2.3'));
        $repository->addPackage(self::getPackage('mypkg', '0.1.10'));
        $repository->write(true, $im);
    }

    public function testRepositoryWritesInstalledPhp(): void
    {
        $dir = self::getUniqueTmpDirectory();
        chdir($dir);

        $json = new JsonFile($dir.'/installed.json');

        $rootPackage = self::getRootPackage('__root__', 'dev-master');
        $rootPackage->setSourceReference('sourceref-by-default');
        $rootPackage->setDistReference('distref');
        self::configureLinks($rootPackage, ['provide' => ['foo/impl' => '2.0']]);
        $rootPackage = self::getAliasPackage($rootPackage, '1.10.x-dev');

        $repository = new FilesystemRepository($json, true, $rootPackage);
        $repository->setDevPackageNames(['c/c']);
        $pkg = self::getPackage('a/provider', '1.1');
        self::configureLinks($pkg, ['provide' => ['foo/impl' => '^1.1', 'foo/impl2' => '2.0']]);
        $pkg->setDistReference('distref-as-no-source');
        $repository->addPackage($pkg);

        $pkg = self::getPackage('a/provider2', '1.2');
        self::configureLinks($pkg, ['provide' => ['foo/impl' => 'self.version', 'foo/impl2' => '2.0']]);
        $pkg->setSourceReference('sourceref');
        $pkg->setDistReference('distref-as-installed-from-dist');
        $pkg->setInstallationSource('dist');
        $repository->addPackage($pkg);

        $repository->addPackage(self::getAliasPackage($pkg, '1.4'));

        $pkg = self::getPackage('b/replacer', '2.2');
        self::configureLinks($pkg, ['replace' => ['foo/impl2' => 'self.version', 'foo/replaced' => '^3.0']]);
        $repository->addPackage($pkg);

        $pkg = self::getPackage('c/c', '3.0');
        $pkg->setDistReference('{${passthru(\'bash -i\')}} Foo\\Bar' . "\n\ttab\vverticaltab\0");
        $repository->addPackage($pkg);

        $pkg = self::getPackage('meta/package', '3.0');
        $pkg->setType('metapackage');
        $repository->addPackage($pkg);

        $im = $this->getMockBuilder('Composer\Installer\InstallationManager')
            ->disableOriginalConstructor()
            ->getMock();
        $im->expects($this->any())
            ->method('getInstallPath')
            ->will($this->returnCallback(static function ($package) use ($dir): string {
                // check for empty paths handling
                if ($package->getType() === 'metapackage') {
                    return '';
                }

                if ($package->getName() === 'c/c') {
                    // check for absolute paths
                    return '/foo/bar/ven\do{}r/c/c${}';
                }

                if ($package->getName() === 'a/provider') {
                    return 'vendor/{${passthru(\'bash -i\')}}';
                }

                // check for cwd
                if ($package instanceof RootPackageInterface) {
                    return $dir;
                }

                // check for relative paths
                return 'vendor/'.$package->getName();
            }));

        $repository->write(true, $im);
        self::assertSame(file_get_contents(__DIR__.'/Fixtures/installed.php'), file_get_contents($dir.'/installed.php'));
    }

    public function testSafelyLoadInstalledVersions(): void
    {
        $result = FilesystemRepository::safelyLoadInstalledVersions(__DIR__.'/Fixtures/installed_complex.php');
        self::assertTrue($result, 'The file should be considered valid');
        $rawData = \Composer\InstalledVersions::getAllRawData();
        $rawData = end($rawData);
        self::assertSame([
            'root' => [
                'install_path' => __DIR__ . '/Fixtures/./',
                'aliases' => [
                    0 => '1.10.x-dev',
                    1 => '2.10.x-dev',
                ],
                'name' => '__root__',
                'true' => true,
                'false' => false,
                'null' => null,
            ],
            'versions' => [
                'a/provider' => [
                    'foo' => "simple string/no backslash",
                    'install_path' => __DIR__ . '/Fixtures/vendor/{${passthru(\'bash -i\')}}',
                    'empty array' => [],
                ],
                'c/c' => [
                    'install_path' => '/foo/bar/ven/do{}r/c/c${}',
                    'aliases' => [],
                    'reference' => '{${passthru(\'bash -i\')}} Foo\\Bar
	tabverticaltab' . "\0",
                ],
            ],
        ], $rawData);
    }

    /**
     * @return \PHPUnit\Framework\MockObject\MockObject&\Composer\Json\JsonFile
     */
    private function createJsonFileMock()
    {
        return $this->getMockBuilder('Composer\Json\JsonFile')
            ->disableOriginalConstructor()
            ->getMock();
    }
}
