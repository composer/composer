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

namespace Composer\Test\Autoload;

use Composer\Autoload\AutoloadGenerator;
use Composer\Util\Filesystem;
use Composer\Package\MemoryPackage;
use Composer\Test\TestCase;

class AutoloadGeneratorTest extends TestCase
{
    public $vendorDir;
    private $workingDir;
    private $im;
    private $repository;
    private $generator;
    private $fs;

    protected function setUp()
    {
        $this->fs = new Filesystem;
        $that = $this;

        $this->workingDir = realpath(sys_get_temp_dir());
        $this->vendorDir = $this->workingDir.DIRECTORY_SEPARATOR.'composer-test-autoload';
        $this->ensureDirectoryExistsAndClear($this->vendorDir);

        $this->dir = getcwd();
        chdir($this->workingDir);

        $this->im = $this->getMockBuilder('Composer\Installer\InstallationManager')
            ->disableOriginalConstructor()
            ->getMock();
        $this->im->expects($this->any())
            ->method('getInstallPath')
            ->will($this->returnCallback(function ($package) use ($that) {
                return $that->vendorDir.'/'.$package->getName();
            }));
        $this->im->expects($this->any())
            ->method('getVendorPath')
            ->will($this->returnCallback(function () use ($that) {
                return $that->vendorDir;
            }));

        $this->repository = $this->getMock('Composer\Repository\RepositoryInterface');

        $this->generator = new AutoloadGenerator();
    }

    protected function tearDown()
    {
        if ($this->vendorDir === $this->workingDir) {
            if (is_dir($this->workingDir.'/.composer')) {
                $this->fs->removeDirectory($this->workingDir.'/.composer');
            }
        } elseif (is_dir($this->vendorDir)) {
            $this->fs->removeDirectory($this->vendorDir);
        }
        chdir($this->dir);
    }

    public function testMainPackageAutoloading()
    {
        $package = new MemoryPackage('a', '1.0', '1.0');
        $package->setAutoload(array('psr-0' => array('Main' => 'src/', 'Lala' => 'src/')));

        $this->repository->expects($this->once())
            ->method('getPackages')
            ->will($this->returnValue(array()));

        mkdir($this->vendorDir.'/.composer');
        $this->generator->dump($this->repository, $package, $this->im, $this->vendorDir.'/.composer');
        $this->assertAutoloadFiles('main', $this->vendorDir.'/.composer');
    }

    public function testVendorDirSameAsWorkingDir()
    {
        $this->vendorDir = $this->workingDir;

        $package = new MemoryPackage('a', '1.0', '1.0');
        $package->setAutoload(array('psr-0' => array('Main' => 'src/', 'Lala' => 'src/')));

        $this->repository->expects($this->once())
            ->method('getPackages')
            ->will($this->returnValue(array()));

        if (!is_dir($this->vendorDir.'/.composer')) {
            mkdir($this->vendorDir.'/.composer', 0777, true);
        }

        $this->generator->dump($this->repository, $package, $this->im, $this->vendorDir.'/.composer');
        $this->assertAutoloadFiles('main3', $this->vendorDir.'/.composer');
    }

    public function testMainPackageAutoloadingAlternativeVendorDir()
    {
        $package = new MemoryPackage('a', '1.0', '1.0');
        $package->setAutoload(array('psr-0' => array('Main' => 'src/', 'Lala' => 'src/')));

        $this->repository->expects($this->once())
            ->method('getPackages')
            ->will($this->returnValue(array()));

        $this->vendorDir .= '/subdir';
        mkdir($this->vendorDir.'/.composer', 0777, true);
        $this->generator->dump($this->repository, $package, $this->im, $this->vendorDir.'/.composer');
        $this->assertAutoloadFiles('main2', $this->vendorDir.'/.composer');
    }

    public function testVendorsAutoloading()
    {
        $package = new MemoryPackage('a', '1.0', '1.0');

        $packages = array();
        $packages[] = $a = new MemoryPackage('a/a', '1.0', '1.0');
        $packages[] = $b = new MemoryPackage('b/b', '1.0', '1.0');
        $a->setAutoload(array('psr-0' => array('A' => 'src/', 'A\\B' => 'lib/')));
        $b->setAutoload(array('psr-0' => array('B\\Sub\\Name' => 'src/')));

        $this->repository->expects($this->once())
            ->method('getPackages')
            ->will($this->returnValue($packages));

        mkdir($this->vendorDir.'/.composer', 0777, true);
        $this->generator->dump($this->repository, $package, $this->im, $this->vendorDir.'/.composer');
        $this->assertAutoloadFiles('vendors', $this->vendorDir.'/.composer');
        $this->assertTrue(file_exists($this->vendorDir.'/.composer/autoload_classmap.php'), "ClassMap file needs to be generated, even if empty.");
    }

    public function testVendorsClassMapAutoloading()
    {
        $package = new MemoryPackage('a', '1.0', '1.0');

        $packages = array();
        $packages[] = $a = new MemoryPackage('a/a', '1.0', '1.0');
        $packages[] = $b = new MemoryPackage('b/b', '1.0', '1.0');
        $a->setAutoload(array('classmap' => array('src/')));
        $b->setAutoload(array('classmap' => array('src/', 'lib/')));

        $this->repository->expects($this->once())
            ->method('getPackages')
            ->will($this->returnValue($packages));

        @mkdir($this->vendorDir.'/.composer', 0777, true);
        mkdir($this->vendorDir.'/a/a/src', 0777, true);
        mkdir($this->vendorDir.'/b/b/src', 0777, true);
        mkdir($this->vendorDir.'/b/b/lib', 0777, true);
        file_put_contents($this->vendorDir.'/a/a/src/a.php', '<?php class ClassMapFoo {}');
        file_put_contents($this->vendorDir.'/b/b/src/b.php', '<?php class ClassMapBar {}');
        file_put_contents($this->vendorDir.'/b/b/lib/c.php', '<?php class ClassMapBaz {}');

        $this->generator->dump($this->repository, $package, $this->im, $this->vendorDir.'/.composer');
        $this->assertTrue(file_exists($this->vendorDir.'/.composer/autoload_classmap.php'), "ClassMap file needs to be generated, even if empty.");
        $this->assertEquals(array(
                'ClassMapFoo' => $this->vendorDir.'/a/a/src/a.php',
                'ClassMapBar' => $this->vendorDir.'/b/b/src/b.php',
                'ClassMapBaz' => $this->vendorDir.'/b/b/lib/c.php',
            ),
            include ($this->vendorDir.'/.composer/autoload_classmap.php')
        );
    }

    public function testOverrideVendorsAutoloading()
    {
        $package = new MemoryPackage('a', '1.0', '1.0');
        $package->setAutoload(array('psr-0' => array('A\\B' => '/home/deveuser/local-packages/a-a/lib')));

        $packages = array();
        $packages[] = $a = new MemoryPackage('a/a', '1.0', '1.0');
        $packages[] = $b = new MemoryPackage('b/b', '1.0', '1.0');
        $a->setAutoload(array('psr-0' => array('A' => 'src/', 'A\\B' => 'lib/')));
        $b->setAutoload(array('psr-0' => array('B\\Sub\\Name' => 'src/')));

        $this->repository->expects($this->once())
            ->method('getPackages')
            ->will($this->returnValue($packages));

        mkdir($this->vendorDir.'/.composer', 0777, true);
        $this->generator->dump($this->repository, $package, $this->im, $this->vendorDir.'/.composer');
        $this->assertAutoloadFiles('override_vendors', $this->vendorDir.'/.composer');
    }

    private function assertAutoloadFiles($name, $dir)
    {
        $this->assertFileEquals(__DIR__.'/Fixtures/autoload_'.$name.'.php', $dir.'/autoload_namespaces.php');
    }
}
