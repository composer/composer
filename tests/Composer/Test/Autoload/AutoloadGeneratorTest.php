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

namespace Composer\Test\Installer;

use Composer\Autoload\AutoloadGenerator;
use Composer\Downloader\Util\Filesystem;
use Composer\Package\MemoryPackage;

class AutoloadGeneratorTest extends \PHPUnit_Framework_TestCase
{
    public $vendorDir;
    private $workingDir;
    private $im;
    private $repository;
    private $generator;

    protected function setUp()
    {
        $fs = new Filesystem;
        $that = $this;

        $this->workingDir = realpath(sys_get_temp_dir());
        $this->vendorDir = $this->workingDir.DIRECTORY_SEPARATOR.'composer-test-autoload';
        if (is_dir($this->vendorDir)) {
            $fs->removeDirectory($this->vendorDir);
        }
        mkdir($this->vendorDir);

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

        $this->repo = $this->getMock('Composer\Repository\RepositoryInterface');

        $this->generator = new AutoloadGenerator();
    }

    protected function tearDown()
    {
        chdir($this->dir);
    }

    public function testMainPackageAutoloading()
    {
        $package = new MemoryPackage('a', '1.0', '1.0');
        $package->setAutoload(array('psr-0' => array('Main' => 'src/', 'Lala' => 'src/')));

        $this->repo->expects($this->once())
            ->method('getPackages')
            ->will($this->returnValue(array()));

        mkdir($this->vendorDir.'/.composer');
        $this->generator->dump($this->repo, $package, $this->im, $this->vendorDir.'/.composer');
        $this->assertAutoloadFiles('main', $this->vendorDir.'/.composer');
    }

    public function testVendorDirSameAsWorkingDir()
    {
        $this->vendorDir = $this->workingDir;

        $package = new MemoryPackage('a', '1.0', '1.0');
        $package->setAutoload(array('psr-0' => array('Main' => 'src/', 'Lala' => 'src/')));

        $this->repo->expects($this->once())
            ->method('getPackages')
            ->will($this->returnValue(array()));

        if (!is_dir($this->vendorDir.'/.composer')) {
            mkdir($this->vendorDir.'/.composer', 0777, true);
        }
        $this->generator->dump($this->repo, $package, $this->im, $this->vendorDir.'/.composer');
        $this->assertAutoloadFiles('main3', $this->vendorDir.'/.composer');
    }

    public function testMainPackageAutoloadingAlternativeVendorDir()
    {
        $package = new MemoryPackage('a', '1.0', '1.0');
        $package->setAutoload(array('psr-0' => array('Main' => 'src/', 'Lala' => 'src/')));

        $this->repo->expects($this->once())
            ->method('getPackages')
            ->will($this->returnValue(array()));

        $this->vendorDir .= '/subdir';
        mkdir($this->vendorDir.'/.composer', 0777, true);
        $this->generator->dump($this->repo, $package, $this->im, $this->vendorDir.'/.composer');
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

        $this->repo->expects($this->once())
            ->method('getPackages')
            ->will($this->returnValue($packages));

        mkdir($this->vendorDir.'/.composer', 0777, true);
        $this->generator->dump($this->repo, $package, $this->im, $this->vendorDir.'/.composer');
        $this->assertAutoloadFiles('vendors', $this->vendorDir.'/.composer');
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

        $this->repo->expects($this->once())
            ->method('getPackages')
            ->will($this->returnValue($packages));

        mkdir($this->vendorDir.'/.composer', 0777, true);
        $this->generator->dump($this->repo, $package, $this->im, $this->vendorDir.'/.composer');
        $this->assertAutoloadFiles('override_vendors', $this->vendorDir.'/.composer');
    }

    private function assertAutoloadFiles($name, $dir)
    {
        $this->assertFileEquals(__DIR__.'/Fixtures/autoload_'.$name.'.php', $dir.'/autoload_namespaces.php');
    }
}
