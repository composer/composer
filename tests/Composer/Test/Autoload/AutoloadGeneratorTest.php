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
use Composer\Package\AliasPackage;
use Composer\Package\MemoryPackage;
use Composer\Test\TestCase;

class AutoloadGeneratorTest extends TestCase
{
    public $vendorDir;
    private $config;
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

        $this->config = $this->getMock('Composer\Config');
        $this->config->expects($this->any())
            ->method('get')
            ->with($this->equalTo('vendor-dir'))
            ->will($this->returnCallback(function () use ($that) {
                return $that->vendorDir;
            }));

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
        $this->repository = $this->getMock('Composer\Repository\RepositoryInterface');

        $this->generator = new AutoloadGenerator();
    }

    protected function tearDown()
    {
        if ($this->vendorDir === $this->workingDir) {
            if (is_dir($this->workingDir.'/composer')) {
                $this->fs->removeDirectory($this->workingDir.'/composer');
            }
        } elseif (is_dir($this->vendorDir)) {
            $this->fs->removeDirectory($this->vendorDir);
        }
        if (is_dir($this->workingDir.'/composersrc')) {
            $this->fs->removeDirectory($this->workingDir.'/composersrc');
        }

        chdir($this->dir);
    }

    public function testMainPackageAutoloading()
    {
        $package = new MemoryPackage('a', '1.0', '1.0');
        $package->setAutoload(array(
            'psr-0' => array('Main' => 'src/', 'Lala' => array('src/', 'lib/')),
            'classmap' => array('composersrc/'),
        ));

        $this->repository->expects($this->once())
            ->method('getPackages')
            ->will($this->returnValue(array()));

        if (!is_dir($this->vendorDir.'/composer')) {
            mkdir($this->vendorDir.'/composer');
        }

        $this->createClassFile($this->workingDir);

        $this->generator->dump($this->config, $this->repository, $package, $this->im, 'composer');
        $this->assertAutoloadFiles('main', $this->vendorDir.'/composer');
        $this->assertAutoloadFiles('classmap', $this->vendorDir.'/composer', 'classmap');
    }

    public function testVendorDirSameAsWorkingDir()
    {
        $this->vendorDir = $this->workingDir;

        $package = new MemoryPackage('a', '1.0', '1.0');
        $package->setAutoload(array(
            'psr-0' => array('Main' => 'src/', 'Lala' => 'src/'),
            'classmap' => array('composersrc/'),
        ));

        $this->repository->expects($this->once())
            ->method('getPackages')
            ->will($this->returnValue(array()));

        if (!is_dir($this->vendorDir.'/composer')) {
            mkdir($this->vendorDir.'/composer', 0777, true);
        }

        $this->createClassFile($this->vendorDir);

        $this->generator->dump($this->config, $this->repository, $package, $this->im, 'composer');
        $this->assertAutoloadFiles('main3', $this->vendorDir.'/composer');
        $this->assertAutoloadFiles('classmap3', $this->vendorDir.'/composer', 'classmap');
    }

    public function testMainPackageAutoloadingAlternativeVendorDir()
    {
        $package = new MemoryPackage('a', '1.0', '1.0');
        $package->setAutoload(array(
            'psr-0' => array('Main' => 'src/', 'Lala' => 'src/'),
            'classmap' => array('composersrc/'),
        ));

        $this->repository->expects($this->once())
            ->method('getPackages')
            ->will($this->returnValue(array()));

        $this->vendorDir .= '/subdir';
        mkdir($this->vendorDir.'/composer', 0777, true);
        $this->createClassFile($this->workingDir);
        $this->generator->dump($this->config, $this->repository, $package, $this->im, 'composer');
        $this->assertAutoloadFiles('main2', $this->vendorDir.'/composer');
        $this->assertAutoloadFiles('classmap2', $this->vendorDir.'/composer', 'classmap');
    }

    public function testMainPackageAutoloadingWithTargetDir()
    {
        $package = new MemoryPackage('a', '1.0', '1.0');
        $package->setAutoload(array(
            'psr-0' => array('Main\\Foo' => '', 'Main\\Bar' => ''),
        ));
        $package->setTargetDir('Main/Foo/');

        $this->repository->expects($this->once())
            ->method('getPackages')
            ->will($this->returnValue(array()));

        $this->generator->dump($this->config, $this->repository, $package, $this->im, 'composer');
        $this->assertFileEquals(__DIR__.'/Fixtures/autoload_target_dir.php', $this->vendorDir.'/autoload.php');
    }

    public function testVendorsAutoloading()
    {
        $package = new MemoryPackage('a', '1.0', '1.0');

        $packages = array();
        $packages[] = $a = new MemoryPackage('a/a', '1.0', '1.0');
        $packages[] = $b = new MemoryPackage('b/b', '1.0', '1.0');
        $packages[] = $c = new AliasPackage($b, '1.2', '1.2');
        $a->setAutoload(array('psr-0' => array('A' => 'src/', 'A\\B' => 'lib/')));
        $b->setAutoload(array('psr-0' => array('B\\Sub\\Name' => 'src/')));

        $this->repository->expects($this->once())
            ->method('getPackages')
            ->will($this->returnValue($packages));

        mkdir($this->vendorDir.'/composer', 0777, true);
        $this->generator->dump($this->config, $this->repository, $package, $this->im, 'composer');
        $this->assertAutoloadFiles('vendors', $this->vendorDir.'/composer');
        $this->assertTrue(file_exists($this->vendorDir.'/composer/autoload_classmap.php'), "ClassMap file needs to be generated, even if empty.");
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

        @mkdir($this->vendorDir.'/composer', 0777, true);
        mkdir($this->vendorDir.'/a/a/src', 0777, true);
        mkdir($this->vendorDir.'/b/b/src', 0777, true);
        mkdir($this->vendorDir.'/b/b/lib', 0777, true);
        file_put_contents($this->vendorDir.'/a/a/src/a.php', '<?php class ClassMapFoo {}');
        file_put_contents($this->vendorDir.'/b/b/src/b.php', '<?php class ClassMapBar {}');
        file_put_contents($this->vendorDir.'/b/b/lib/c.php', '<?php class ClassMapBaz {}');

        $this->generator->dump($this->config, $this->repository, $package, $this->im, 'composer');
        $this->assertTrue(file_exists($this->vendorDir.'/composer/autoload_classmap.php'), "ClassMap file needs to be generated.");
        $this->assertEquals(
            array(
                'ClassMapFoo' => $this->workingDir.'/composer-test-autoload/a/a/src/a.php',
                'ClassMapBar' => $this->workingDir.'/composer-test-autoload/b/b/src/b.php',
                'ClassMapBaz' => $this->workingDir.'/composer-test-autoload/b/b/lib/c.php',
            ),
            include ($this->vendorDir.'/composer/autoload_classmap.php')
        );
        $this->assertAutoloadFiles('classmap4', $this->vendorDir.'/composer', 'classmap');
    }

    public function testClassMapAutoloadingEmptyDirAndExactFile()
    {
        $package = new MemoryPackage('a', '1.0', '1.0');

        $packages = array();
        $packages[] = $a = new MemoryPackage('a/a', '1.0', '1.0');
        $packages[] = $b = new MemoryPackage('b/b', '1.0', '1.0');
        $packages[] = $c = new MemoryPackage('c/c', '1.0', '1.0');
        $a->setAutoload(array('classmap' => array('')));
        $b->setAutoload(array('classmap' => array('test.php')));
        $c->setAutoload(array('classmap' => array('./')));

        $this->repository->expects($this->once())
            ->method('getPackages')
            ->will($this->returnValue($packages));

        @mkdir($this->vendorDir.'/composer', 0777, true);
        mkdir($this->vendorDir.'/a/a/src', 0777, true);
        mkdir($this->vendorDir.'/b/b', 0777, true);
        mkdir($this->vendorDir.'/c/c/foo', 0777, true);
        file_put_contents($this->vendorDir.'/a/a/src/a.php', '<?php class ClassMapFoo {}');
        file_put_contents($this->vendorDir.'/b/b/test.php', '<?php class ClassMapBar {}');
        file_put_contents($this->vendorDir.'/c/c/foo/test.php', '<?php class ClassMapBaz {}');

        $this->generator->dump($this->config, $this->repository, $package, $this->im, 'composer');
        $this->assertTrue(file_exists($this->vendorDir.'/composer/autoload_classmap.php'), "ClassMap file needs to be generated.");
        $this->assertEquals(
            array(
                'ClassMapFoo' => $this->workingDir.'/composer-test-autoload/a/a/src/a.php',
                'ClassMapBar' => $this->workingDir.'/composer-test-autoload/b/b/test.php',
                'ClassMapBaz' => $this->workingDir.'/composer-test-autoload/c/c/foo/test.php',
            ),
            include ($this->vendorDir.'/composer/autoload_classmap.php')
        );
        $this->assertAutoloadFiles('classmap5', $this->vendorDir.'/composer', 'classmap');
    }

    public function testFilesAutoloadGeneration()
    {
        $package = new MemoryPackage('a', '1.0', '1.0');

        $packages = array();
        $packages[] = $a = new MemoryPackage('a/a', '1.0', '1.0');
        $packages[] = $b = new MemoryPackage('b/b', '1.0', '1.0');
        $a->setAutoload(array('files' => array('test.php')));
        $b->setAutoload(array('files' => array('test2.php')));

        $this->repository->expects($this->once())
            ->method('getPackages')
            ->will($this->returnValue($packages));

        mkdir($this->vendorDir.'/a/a', 0777, true);
        mkdir($this->vendorDir.'/b/b', 0777, true);
        file_put_contents($this->vendorDir.'/a/a/test.php', '<?php function testFilesAutoloadGeneration1() {}');
        file_put_contents($this->vendorDir.'/b/b/test2.php', '<?php function testFilesAutoloadGeneration2() {}');

        $this->generator->dump($this->config, $this->repository, $package, $this->im, 'composer');
        $this->assertFileEquals(__DIR__.'/Fixtures/autoload_functions.php', $this->vendorDir.'/autoload.php');

        include $this->vendorDir . '/autoload.php';
        $this->assertTrue(function_exists('testFilesAutoloadGeneration1'));
        $this->assertTrue(function_exists('testFilesAutoloadGeneration2'));
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

        mkdir($this->vendorDir.'/composer', 0777, true);
        $this->generator->dump($this->config, $this->repository, $package, $this->im, 'composer');
        $this->assertAutoloadFiles('override_vendors', $this->vendorDir.'/composer');
    }

    public function testIncludePathFileGeneration()
    {
        $package = new MemoryPackage('a', '1.0', '1.0');
        $packages = array();

        $a = new MemoryPackage("a/a", "1.0", "1.0");
        $a->setIncludePaths(array("lib/"));

        $b = new MemoryPackage("b/b", "1.0", "1.0");
        $b->setIncludePaths(array("library"));

        $c = new MemoryPackage("c", "1.0", "1.0");
        $c->setIncludePaths(array("library"));

        $packages[] = $a;
        $packages[] = $b;
        $packages[] = $c;

        $this->repository->expects($this->once())
            ->method("getPackages")
            ->will($this->returnValue($packages));

        mkdir($this->vendorDir."/composer", 0777, true);

        $this->generator->dump($this->config, $this->repository, $package, $this->im, "composer");

        $this->assertFileEquals(__DIR__.'/Fixtures/include_paths.php', $this->vendorDir.'/composer/include_paths.php');
        $this->assertEquals(
            array(
                $this->vendorDir."/a/a/lib",
                $this->vendorDir."/b/b/library",
                $this->vendorDir."/c/library",
            ),
            require($this->vendorDir."/composer/include_paths.php")
        );
    }

    public function testIncludePathsArePrependedInAutoloadFile()
    {
        $package = new MemoryPackage('a', '1.0', '1.0');
        $packages = array();

        $a = new MemoryPackage("a/a", "1.0", "1.0");
        $a->setIncludePaths(array("lib/"));

        $packages[] = $a;

        $this->repository->expects($this->once())
            ->method("getPackages")
            ->will($this->returnValue($packages));

        mkdir($this->vendorDir."/composer", 0777, true);

        $this->generator->dump($this->config, $this->repository, $package, $this->im, "composer");

        $oldIncludePath = get_include_path();

        require($this->vendorDir."/autoload.php");

        $this->assertEquals(
            $this->vendorDir."/a/a/lib".PATH_SEPARATOR.$oldIncludePath,
            get_include_path()
        );

        set_include_path($oldIncludePath);
    }

    public function testIncludePathFileWithoutPathsIsSkipped()
    {
        $package = new MemoryPackage('a', '1.0', '1.0');
        $packages = array();

        $a = new MemoryPackage("a/a", "1.0", "1.0");
        $packages[] = $a;

        $this->repository->expects($this->once())
            ->method("getPackages")
            ->will($this->returnValue($packages));

        mkdir($this->vendorDir."/composer", 0777, true);

        $this->generator->dump($this->config, $this->repository, $package, $this->im, "composer");

        $this->assertFalse(file_exists($this->vendorDir."/composer/include_paths.php"));
    }

    private function createClassFile($basedir)
    {
        if (!is_dir($basedir.'/composersrc')) {
            mkdir($basedir.'/composersrc', 0777, true);
        }

        file_put_contents($basedir.'/composersrc/foo.php', '<?php class ClassMapFoo {}');
    }

    private function assertAutoloadFiles($name, $dir, $type = 'namespaces')
    {
        $this->assertFileEquals(__DIR__.'/Fixtures/autoload_'.$name.'.php', $dir.'/autoload_'.$type.'.php');
    }
}
