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

namespace Composer\Test\Autoload;

use Composer\Autoload\AutoloadGenerator;
use Composer\Filter\PlatformRequirementFilter\PlatformRequirementFilterFactory;
use Composer\IO\BufferIO;
use Composer\IO\IOInterface;
use Composer\Package\CompletePackage;
use Composer\Package\Link;
use Composer\Package\Version\VersionParser;
use Composer\Semver\Constraint\Constraint;
use Composer\Semver\Constraint\MatchAllConstraint;
use Composer\Util\Filesystem;
use Composer\Package\AliasPackage;
use Composer\Package\Package;
use Composer\Package\RootPackage;
use Composer\Test\TestCase;
use Composer\Script\ScriptEvents;
use Composer\Repository\InstalledRepositoryInterface;
use Composer\Installer\InstallationManager;
use Composer\Config;
use Composer\EventDispatcher\EventDispatcher;
use Composer\Util\Platform;
use PHPUnit\Framework\MockObject\MockObject;

class AutoloadGeneratorTest extends TestCase
{
    /**
     * @var string
     */
    public $vendorDir;

    /**
     * @var Config|MockObject
     */
    private $config;

    /**
     * @var string
     */
    private $workingDir;

    /**
     * @var string
     */
    private $origDir;

    /**
     * @var InstallationManager&MockObject
     */
    private $im;

    /**
     * @var InstalledRepositoryInterface&MockObject
     */
    private $repository;

    /**
     * @var AutoloadGenerator
     */
    private $generator;

    /**
     * @var Filesystem
     */
    private $fs;

    /**
     * @var BufferIO
     */
    private $io;

    /**
     * @var EventDispatcher&MockObject
     */
    private $eventDispatcher;

    /**
     * Map of setting name => return value configuration for the stub Config
     * object.
     *
     * @var array<string, callable|boolean>
     */
    private $configValueMap;

    protected function setUp(): void
    {
        $this->fs = new Filesystem;

        $this->workingDir = self::getUniqueTmpDirectory();
        $this->vendorDir = $this->workingDir.DIRECTORY_SEPARATOR.'composer-test-autoload';
        $this->ensureDirectoryExistsAndClear($this->vendorDir);

        $this->config = $this->getMockBuilder('Composer\Config')->getMock();

        $this->configValueMap = [
            'vendor-dir' => function (): string {
                return $this->vendorDir;
            },
            'platform-check' => static function (): bool {
                return true;
            },
            'use-include-path' => static function (): bool {
                return false;
            },
        ];

        $this->io = new BufferIO();

        $this->config->expects($this->atLeastOnce())
            ->method('get')
            ->will($this->returnCallback(function ($arg) {
                $ret = null;
                if (isset($this->configValueMap[$arg])) {
                    $ret = $this->configValueMap[$arg];
                    if (is_callable($ret)) {
                        $ret = $ret();
                    }
                }

                return $ret;
            }));

        $this->origDir = Platform::getCwd();
        chdir($this->workingDir);

        $this->im = $this->getMockBuilder('Composer\Installer\InstallationManager')
            ->disableOriginalConstructor()
            ->getMock();
        $this->im->expects($this->any())
            ->method('getInstallPath')
            ->will($this->returnCallback(function ($package): string {
                $targetDir = $package->getTargetDir();

                return $this->vendorDir.'/'.$package->getName() . ($targetDir ? '/'.$targetDir : '');
            }));
        $this->repository = $this->getMockBuilder('Composer\Repository\InstalledRepositoryInterface')->getMock();
        $this->repository->expects($this->any())
            ->method('getDevPackageNames')
            ->willReturn([]);

        $this->eventDispatcher = $this->getMockBuilder('Composer\EventDispatcher\EventDispatcher')
            ->disableOriginalConstructor()
            ->getMock();

        $this->generator = new AutoloadGenerator($this->eventDispatcher, $this->io);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        chdir($this->origDir);

        if (is_dir($this->workingDir)) {
            $this->fs->removeDirectory($this->workingDir);
        }

        if (is_dir($this->vendorDir)) {
            $this->fs->removeDirectory($this->vendorDir);
        }
    }

    public function testRootPackageAutoloading(): void
    {
        $package = new RootPackage('root/a', '1.0', '1.0');
        $package->setAutoload([
            'psr-0' => [
                'Main' => 'src/',
                'Lala' => ['src/', 'lib/'],
            ],
            'psr-4' => [
                'Acme\Fruit\\' => 'src-fruit/',
                'Acme\Cake\\' => ['src-cake/', 'lib-cake/'],
            ],
            'classmap' => ['composersrc/'],
        ]);

        $this->repository->expects($this->once())
            ->method('getCanonicalPackages')
            ->will($this->returnValue([]));

        $this->fs->ensureDirectoryExists($this->workingDir.'/composer');
        $this->fs->ensureDirectoryExists($this->workingDir.'/src/Lala/Test');
        $this->fs->ensureDirectoryExists($this->workingDir.'/lib');
        file_put_contents($this->workingDir.'/src/Lala/ClassMapMain.php', '<?php namespace Lala; class ClassMapMain {}');
        file_put_contents($this->workingDir.'/src/Lala/Test/ClassMapMainTest.php', '<?php namespace Lala\Test; class ClassMapMainTest {}');

        $this->fs->ensureDirectoryExists($this->workingDir.'/src-fruit');
        $this->fs->ensureDirectoryExists($this->workingDir.'/src-cake');
        $this->fs->ensureDirectoryExists($this->workingDir.'/lib-cake');
        file_put_contents($this->workingDir.'/src-cake/ClassMapBar.php', '<?php namespace Acme\Cake; class ClassMapBar {}');

        $this->fs->ensureDirectoryExists($this->workingDir.'/composersrc');
        file_put_contents($this->workingDir.'/composersrc/foo.php', '<?php class ClassMapFoo {}');

        $this->generator->dump($this->config, $this->repository, $package, $this->im, 'composer', true, '_1');

        // Assert that autoload_namespaces.php was correctly generated.
        $this->assertAutoloadFiles('main', $this->vendorDir.'/composer');

        // Assert that autoload_psr4.php was correctly generated.
        $this->assertAutoloadFiles('psr4', $this->vendorDir.'/composer', 'psr4');

        // Assert that autoload_classmap.php was correctly generated.
        $this->assertAutoloadFiles('classmap', $this->vendorDir.'/composer', 'classmap');
    }

    public function testRootPackageDevAutoloading(): void
    {
        $package = new RootPackage('root/a', '1.0', '1.0');
        $package->setAutoload([
            'psr-0' => [
                'Main' => 'src/',
            ],
        ]);
        $package->setDevAutoload([
            'files' => ['devfiles/foo.php'],
            'psr-0' => [
                'Main' => 'tests/',
            ],
        ]);

        $this->repository->expects($this->once())
            ->method('getCanonicalPackages')
            ->will($this->returnValue([]));

        $this->fs->ensureDirectoryExists($this->workingDir.'/composer');
        $this->fs->ensureDirectoryExists($this->workingDir.'/src/Main');
        file_put_contents($this->workingDir.'/src/Main/ClassMain.php', '<?php namespace Main; class ClassMain {}');

        $this->fs->ensureDirectoryExists($this->workingDir.'/devfiles');
        file_put_contents($this->workingDir.'/devfiles/foo.php', '<?php function foo() { echo "foo"; }');

        // generate autoload files with the dev mode set to true
        $this->generator->setDevMode(true);
        $this->generator->dump($this->config, $this->repository, $package, $this->im, 'composer', true, '_1');

        // check standard autoload
        $this->assertAutoloadFiles('main5', $this->vendorDir.'/composer');
        $this->assertAutoloadFiles('classmap7', $this->vendorDir.'/composer', 'classmap');

        // make sure dev autoload is correctly dumped
        $this->assertAutoloadFiles('files2', $this->vendorDir.'/composer', 'files');
    }

    public function testRootPackageDevAutoloadingDisabledByDefault(): void
    {
        $package = new RootPackage('root/a', '1.0', '1.0');
        $package->setAutoload([
            'psr-0' => [
                'Main' => 'src/',
            ],
        ]);
        $package->setDevAutoload([
            'files' => ['devfiles/foo.php'],
        ]);

        $this->repository->expects($this->once())
            ->method('getCanonicalPackages')
            ->will($this->returnValue([]));

        $this->fs->ensureDirectoryExists($this->workingDir.'/composer');
        $this->fs->ensureDirectoryExists($this->workingDir.'/src/Main');
        file_put_contents($this->workingDir.'/src/Main/ClassMain.php', '<?php namespace Main; class ClassMain {}');

        $this->fs->ensureDirectoryExists($this->workingDir.'/devfiles');
        file_put_contents($this->workingDir.'/devfiles/foo.php', '<?php function foo() { echo "foo"; }');

        $this->generator->dump($this->config, $this->repository, $package, $this->im, 'composer', true, '_1');

        // check standard autoload
        $this->assertAutoloadFiles('main4', $this->vendorDir.'/composer');
        $this->assertAutoloadFiles('classmap7', $this->vendorDir.'/composer', 'classmap');

        // make sure dev autoload is disabled when dev mode is set to false
        $this->assertFalse(is_file($this->vendorDir.'/composer/autoload_files.php'));
    }

    public function testVendorDirSameAsWorkingDir(): void
    {
        $this->vendorDir = $this->workingDir;

        $package = new RootPackage('root/a', '1.0', '1.0');
        $package->setAutoload([
            'psr-0' => ['Main' => 'src/', 'Lala' => 'src/'],
            'psr-4' => [
                'Acme\Fruit\\' => 'src-fruit/',
                'Acme\Cake\\' => ['src-cake/', 'lib-cake/'],
            ],
            'classmap' => ['composersrc/'],
        ]);

        $this->repository->expects($this->once())
            ->method('getCanonicalPackages')
            ->will($this->returnValue([]));

        $this->fs->ensureDirectoryExists($this->vendorDir.'/composer');
        $this->fs->ensureDirectoryExists($this->vendorDir.'/src/Main');
        file_put_contents($this->vendorDir.'/src/Main/Foo.php', '<?php namespace Main; class Foo {}');

        $this->fs->ensureDirectoryExists($this->vendorDir.'/composersrc');
        file_put_contents($this->vendorDir.'/composersrc/foo.php', '<?php class ClassMapFoo {}');

        $this->generator->dump($this->config, $this->repository, $package, $this->im, 'composer', true, '_2');
        $this->assertAutoloadFiles('main3', $this->vendorDir.'/composer');
        $this->assertAutoloadFiles('psr4_3', $this->vendorDir.'/composer', 'psr4');
        $this->assertAutoloadFiles('classmap3', $this->vendorDir.'/composer', 'classmap');
    }

    public function testRootPackageAutoloadingAlternativeVendorDir(): void
    {
        $package = new RootPackage('root/a', '1.0', '1.0');
        $package->setAutoload([
            'psr-0' => ['Main' => 'src/', 'Lala' => 'src/'],
            'psr-4' => [
                'Acme\Fruit\\' => 'src-fruit/',
                'Acme\Cake\\' => ['src-cake/', 'lib-cake/'],
            ],
            'classmap' => ['composersrc/'],
        ]);

        $this->repository->expects($this->once())
            ->method('getCanonicalPackages')
            ->will($this->returnValue([]));

        $this->vendorDir .= '/subdir';

        $this->fs->ensureDirectoryExists($this->vendorDir.'/composer');
        $this->fs->ensureDirectoryExists($this->workingDir.'/src');

        $this->fs->ensureDirectoryExists($this->workingDir.'/composersrc');
        file_put_contents($this->workingDir.'/composersrc/foo.php', '<?php class ClassMapFoo {}');
        $this->generator->dump($this->config, $this->repository, $package, $this->im, 'composer', false, '_3');
        $this->assertAutoloadFiles('main2', $this->vendorDir.'/composer');
        $this->assertAutoloadFiles('psr4_2', $this->vendorDir.'/composer', 'psr4');
        $this->assertAutoloadFiles('classmap2', $this->vendorDir.'/composer', 'classmap');
    }

    public function testRootPackageAutoloadingWithTargetDir(): void
    {
        $package = new RootPackage('root/a', '1.0', '1.0');
        $package->setAutoload([
            'psr-0' => ['Main\\Foo' => '', 'Main\\Bar' => ''],
            'classmap' => ['Main/Foo/src', 'lib'],
            'files' => ['foo.php', 'Main/Foo/bar.php'],
        ]);
        $package->setTargetDir('Main/Foo/');

        $this->repository->expects($this->once())
            ->method('getCanonicalPackages')
            ->will($this->returnValue([]));

        $this->fs->ensureDirectoryExists($this->vendorDir.'/a');
        $this->fs->ensureDirectoryExists($this->workingDir.'/src');
        $this->fs->ensureDirectoryExists($this->workingDir.'/lib');

        file_put_contents($this->workingDir.'/src/rootfoo.php', '<?php class ClassMapFoo {}');
        file_put_contents($this->workingDir.'/lib/rootbar.php', '<?php class ClassMapBar {}');
        file_put_contents($this->workingDir.'/foo.php', '<?php class FilesFoo {}');
        file_put_contents($this->workingDir.'/bar.php', '<?php class FilesBar {}');

        $this->generator->dump($this->config, $this->repository, $package, $this->im, 'composer', false, 'TargetDir');
        $this->assertFileContentEquals(__DIR__.'/Fixtures/autoload_target_dir.php', $this->vendorDir.'/autoload.php');
        $this->assertFileContentEquals(__DIR__.'/Fixtures/autoload_real_target_dir.php', $this->vendorDir.'/composer/autoload_real.php');
        $this->assertFileContentEquals(__DIR__.'/Fixtures/autoload_static_target_dir.php', $this->vendorDir.'/composer/autoload_static.php');
        $this->assertFileContentEquals(__DIR__.'/Fixtures/autoload_files_target_dir.php', $this->vendorDir.'/composer/autoload_files.php');
        $this->assertAutoloadFiles('classmap6', $this->vendorDir.'/composer', 'classmap');
    }

    public function testDuplicateFilesWarning(): void
    {
        $package = new RootPackage('root/a', '1.0', '1.0');
        $package->setAutoload([
            'files' => ['foo.php', 'bar.php', './foo.php', '././foo.php'],
        ]);

        $this->repository->expects($this->once())
            ->method('getCanonicalPackages')
            ->will($this->returnValue([]));

        $this->fs->ensureDirectoryExists($this->vendorDir.'/a');
        $this->fs->ensureDirectoryExists($this->workingDir.'/src');
        $this->fs->ensureDirectoryExists($this->workingDir.'/lib');

        file_put_contents($this->workingDir.'/foo.php', '<?php class FilesFoo {}');
        file_put_contents($this->workingDir.'/bar.php', '<?php class FilesBar {}');

        $this->generator->dump($this->config, $this->repository, $package, $this->im, 'composer', false, 'FilesWarning');
        self::assertFileContentEquals(__DIR__.'/Fixtures/autoload_files_duplicates.php', $this->vendorDir.'/composer/autoload_files.php');
        $expected = '<warning>The following "files" autoload rules are included multiple times, this may cause issues and should be resolved:</warning>'.PHP_EOL.
            '<warning> - $baseDir . \'/foo.php\'</warning>'.PHP_EOL;
        self::assertEquals($expected, $this->io->getOutput());;
    }

    public function testVendorsAutoloading(): void
    {
        $package = new RootPackage('root/a', '1.0', '1.0');
        $package->setRequires([
            'a/a' => new Link('a', 'a/a', new MatchAllConstraint()),
            'b/b' => new Link('a', 'b/b', new MatchAllConstraint()),
        ]);

        $packages = [];
        $packages[] = $a = new Package('a/a', '1.0', '1.0');
        $packages[] = $b = new Package('b/b', '1.0', '1.0');
        $packages[] = $c = new AliasPackage($b, '1.2', '1.2');
        $a->setAutoload(['psr-0' => ['A' => 'src/', 'A\\B' => 'lib/']]);
        $b->setAutoload(['psr-0' => ['B\\Sub\\Name' => 'src/']]);

        $this->repository->expects($this->once())
            ->method('getCanonicalPackages')
            ->will($this->returnValue($packages));

        $this->fs->ensureDirectoryExists($this->vendorDir.'/composer');
        $this->fs->ensureDirectoryExists($this->vendorDir.'/a/a/src');
        $this->fs->ensureDirectoryExists($this->vendorDir.'/a/a/lib');
        $this->fs->ensureDirectoryExists($this->vendorDir.'/b/b/src');

        $this->generator->dump($this->config, $this->repository, $package, $this->im, 'composer', false, '_5');
        $this->assertAutoloadFiles('vendors', $this->vendorDir.'/composer');
        $this->assertFileExists($this->vendorDir.'/composer/autoload_classmap.php', "ClassMap file needs to be generated, even if empty.");
    }

    public function testNonDevAutoloadExclusionWithRecursion(): void
    {
        $package = new RootPackage('root/a', '1.0', '1.0');
        $package->setRequires([
            'a/a' => new Link('a', 'a/a', new MatchAllConstraint()),
        ]);

        $packages = [];
        $packages[] = $a = new Package('a/a', '1.0', '1.0');
        $packages[] = $b = new Package('b/b', '1.0', '1.0');
        $a->setAutoload(['psr-0' => ['A' => 'src/', 'A\\B' => 'lib/']]);
        $a->setRequires([
            'b/b' => new Link('a/a', 'b/b', new MatchAllConstraint()),
        ]);
        $b->setAutoload(['psr-0' => ['B\\Sub\\Name' => 'src/']]);
        $b->setRequires([
            'a/a' => new Link('b/b', 'a/a', new MatchAllConstraint()),
        ]);

        $this->repository->expects($this->once())
            ->method('getCanonicalPackages')
            ->will($this->returnValue($packages));

        $this->fs->ensureDirectoryExists($this->vendorDir.'/composer');
        $this->fs->ensureDirectoryExists($this->vendorDir.'/a/a/src');
        $this->fs->ensureDirectoryExists($this->vendorDir.'/a/a/lib');
        $this->fs->ensureDirectoryExists($this->vendorDir.'/b/b/src');

        $this->generator->dump($this->config, $this->repository, $package, $this->im, 'composer', false, '_5');
        $this->assertAutoloadFiles('vendors', $this->vendorDir.'/composer');
        $this->assertFileExists($this->vendorDir.'/composer/autoload_classmap.php', "ClassMap file needs to be generated, even if empty.");
    }

    public function testNonDevAutoloadShouldIncludeReplacedPackages(): void
    {
        $package = new RootPackage('root/a', '1.0', '1.0');
        $package->setRequires(['a/a' => new Link('a', 'a/a', new MatchAllConstraint())]);

        $packages = [];
        $packages[] = $a = new Package('a/a', '1.0', '1.0');
        $packages[] = $b = new Package('b/b', '1.0', '1.0');

        $a->setRequires(['b/c' => new Link('a/a', 'b/c', new MatchAllConstraint())]);

        $b->setAutoload(['psr-4' => ['B\\' => 'src/']]);
        $b->setReplaces(
            ['b/c' => new Link('b/b', 'b/c', new Constraint('==', '1.0'), Link::TYPE_REPLACE)]
        );

        $this->repository->expects($this->once())
            ->method('getCanonicalPackages')
            ->will($this->returnValue($packages));

        $this->fs->ensureDirectoryExists($this->vendorDir.'/b/b/src/C');
        file_put_contents($this->vendorDir.'/b/b/src/C/C.php', '<?php namespace B\\C; class C {}');

        $this->generator->dump($this->config, $this->repository, $package, $this->im, 'composer', true, '_5');

        $this->assertEquals(
            [
                'B\\C\\C' => $this->vendorDir.'/b/b/src/C/C.php',
                'Composer\\InstalledVersions' => $this->vendorDir . '/composer/InstalledVersions.php',
            ],
            include $this->vendorDir.'/composer/autoload_classmap.php'
        );
    }

    public function testNonDevAutoloadExclusionWithRecursionReplace(): void
    {
        $package = new RootPackage('root/a', '1.0', '1.0');
        $package->setRequires([
            'a/a' => new Link('a', 'a/a', new MatchAllConstraint()),
        ]);

        $packages = [];
        $packages[] = $a = new Package('a/a', '1.0', '1.0');
        $packages[] = $b = new Package('b/b', '1.0', '1.0');
        $a->setAutoload(['psr-0' => ['A' => 'src/', 'A\\B' => 'lib/']]);
        $a->setRequires([
            'c/c' => new Link('a/a', 'c/c', new MatchAllConstraint()),
        ]);
        $b->setAutoload(['psr-0' => ['B\\Sub\\Name' => 'src/']]);
        $b->setReplaces([
            'c/c' => new Link('b/b', 'c/c', new MatchAllConstraint()),
        ]);

        $this->repository->expects($this->once())
            ->method('getCanonicalPackages')
            ->will($this->returnValue($packages));

        $this->fs->ensureDirectoryExists($this->vendorDir.'/composer');
        $this->fs->ensureDirectoryExists($this->vendorDir.'/a/a/src');
        $this->fs->ensureDirectoryExists($this->vendorDir.'/a/a/lib');
        $this->fs->ensureDirectoryExists($this->vendorDir.'/b/b/src');

        $this->generator->dump($this->config, $this->repository, $package, $this->im, 'composer', false, '_5');
        $this->assertAutoloadFiles('vendors', $this->vendorDir.'/composer');
        $this->assertFileExists($this->vendorDir.'/composer/autoload_classmap.php', "ClassMap file needs to be generated, even if empty.");
    }

    public function testNonDevAutoloadReplacesNestedRequirements(): void
    {
        $package = new RootPackage('root/a', '1.0', '1.0');
        $package->setRequires([
            'a/a' => new Link('a', 'a/a', new MatchAllConstraint()),
        ]);

        $packages = [];
        $packages[] = $a = new Package('a/a', '1.0', '1.0');
        $packages[] = $b = new Package('b/b', '1.0', '1.0');
        $packages[] = $c = new Package('c/c', '1.0', '1.0');
        $packages[] = $d = new Package('d/d', '1.0', '1.0');
        $packages[] = $e = new Package('e/e', '1.0', '1.0');
        $a->setAutoload(['classmap' => ['src/A.php']]);
        $a->setRequires([
            'b/b' => new Link('a/a', 'b/b', new MatchAllConstraint()),
        ]);
        $b->setAutoload(['classmap' => ['src/B.php']]);
        $b->setRequires([
            'e/e' => new Link('b/b', 'e/e', new MatchAllConstraint()),
        ]);
        $c->setAutoload(['classmap' => ['src/C.php']]);
        $c->setReplaces([
            'b/b' => new Link('c/c', 'b/b', new MatchAllConstraint()),
        ]);
        $c->setRequires([
            'd/d' => new Link('c/c', 'd/d', new MatchAllConstraint()),
        ]);
        $d->setAutoload(['classmap' => ['src/D.php']]);
        $e->setAutoload(['classmap' => ['src/E.php']]);

        $this->repository->expects($this->once())
            ->method('getCanonicalPackages')
            ->will($this->returnValue($packages));

        $this->fs->ensureDirectoryExists($this->vendorDir.'/a/a/src');
        $this->fs->ensureDirectoryExists($this->vendorDir.'/b/b/src');
        $this->fs->ensureDirectoryExists($this->vendorDir.'/c/c/src');
        $this->fs->ensureDirectoryExists($this->vendorDir.'/d/d/src');
        $this->fs->ensureDirectoryExists($this->vendorDir.'/e/e/src');

        file_put_contents($this->vendorDir.'/a/a/src/A.php', '<?php class A {}');
        file_put_contents($this->vendorDir.'/b/b/src/B.php', '<?php class B {}');
        file_put_contents($this->vendorDir.'/c/c/src/C.php', '<?php class C {}');
        file_put_contents($this->vendorDir.'/d/d/src/D.php', '<?php class D {}');
        file_put_contents($this->vendorDir.'/e/e/src/E.php', '<?php class E {}');

        $this->generator->dump($this->config, $this->repository, $package, $this->im, 'composer', false, '_5');

        $this->assertAutoloadFiles('classmap9', $this->vendorDir.'/composer', 'classmap');
    }

    public function testPharAutoload(): void
    {
        $package = new RootPackage('root/a', '1.0', '1.0');
        $package->setRequires([
            'a/a' => new Link('a', 'a/a', new MatchAllConstraint()),
        ]);

        $package->setAutoload([
            'psr-0' => [
                'Foo' => 'foo.phar',
                'Bar' => 'dir/bar.phar/src',
            ],
            'psr-4' => [
                'Baz\\' => 'baz.phar',
                'Qux\\' => 'dir/qux.phar/src',
            ],
        ]);

        $vendorPackage = new Package('a/a', '1.0', '1.0');
        $vendorPackage->setAutoload([
            'psr-0' => [
                'Lorem' => 'lorem.phar',
                'Ipsum' => 'dir/ipsum.phar/src',
            ],
            'psr-4' => [
                'Dolor\\' => 'dolor.phar',
                'Sit\\' => 'dir/sit.phar/src',
            ],
        ]);

        $this->repository->expects($this->once())
            ->method('getCanonicalPackages')
            ->will($this->returnValue([$vendorPackage]));

        $this->generator->dump($this->config, $this->repository, $package, $this->im, 'composer', true, 'Phar');

        $this->assertAutoloadFiles('phar', $this->vendorDir . '/composer');
        $this->assertAutoloadFiles('phar_psr4', $this->vendorDir . '/composer', 'psr4');
        $this->assertAutoloadFiles('phar_static', $this->vendorDir . '/composer', 'static');
    }

    public function testPSRToClassMapIgnoresNonExistingDir(): void
    {
        $package = new RootPackage('root/a', '1.0', '1.0');

        $package->setAutoload([
            'psr-0' => ['Prefix' => 'foo/bar/non/existing/'],
            'psr-4' => ['Prefix\\' => 'foo/bar/non/existing2/'],
        ]);

        $this->repository->expects($this->once())
            ->method('getCanonicalPackages')
            ->will($this->returnValue([]));

        $this->generator->dump($this->config, $this->repository, $package, $this->im, 'composer', true, '_8');
        $this->assertFileExists($this->vendorDir.'/composer/autoload_classmap.php', "ClassMap file needs to be generated.");
        $this->assertEquals(
            [
                'Composer\\InstalledVersions' => $this->vendorDir.'/composer/InstalledVersions.php',
            ],
            include $this->vendorDir.'/composer/autoload_classmap.php'
        );
    }

    public function testPSRToClassMapIgnoresNonPSRClasses(): void
    {
        $package = new RootPackage('root/a', '1.0', '1.0');

        $package->setAutoload([
            'psr-0' => ['psr0_' => 'psr0/'],
            'psr-4' => ['psr4\\' => 'psr4/'],
        ]);

        $this->repository->expects($this->once())
            ->method('getCanonicalPackages')
            ->will($this->returnValue([]));

        $this->fs->ensureDirectoryExists($this->workingDir.'/psr0/psr0');
        $this->fs->ensureDirectoryExists($this->workingDir.'/psr4');
        file_put_contents($this->workingDir.'/psr0/psr0/match.php', '<?php class psr0_match {}');
        file_put_contents($this->workingDir.'/psr0/psr0/badfile.php', '<?php class psr0_badclass {}');
        file_put_contents($this->workingDir.'/psr4/match.php', '<?php namespace psr4; class match {}');
        file_put_contents($this->workingDir.'/psr4/badfile.php', '<?php namespace psr4; class badclass {}');

        $this->generator->dump($this->config, $this->repository, $package, $this->im, 'composer', true, '_1');
        $this->assertFileExists($this->vendorDir.'/composer/autoload_classmap.php', "ClassMap file needs to be generated.");

        $expectedClassmap = <<<EOF
<?php

// autoload_classmap.php @generated by Composer

\$vendorDir = dirname(__DIR__);
\$baseDir = dirname(\$vendorDir);

return array(
    'Composer\\\\InstalledVersions' => \$vendorDir . '/composer/InstalledVersions.php',
    'psr0_match' => \$baseDir . '/psr0/psr0/match.php',
    'psr4\\\\match' => \$baseDir . '/psr4/match.php',
);

EOF;
        $this->assertStringEqualsFile($this->vendorDir.'/composer/autoload_classmap.php', $expectedClassmap);
    }

    public function testVendorsClassMapAutoloading(): void
    {
        $package = new RootPackage('root/a', '1.0', '1.0');
        $package->setRequires([
            'a/a' => new Link('a', 'a/a', new MatchAllConstraint()),
            'b/b' => new Link('a', 'b/b', new MatchAllConstraint()),
        ]);

        $packages = [];
        $packages[] = $a = new Package('a/a', '1.0', '1.0');
        $packages[] = $b = new Package('b/b', '1.0', '1.0');
        $a->setAutoload(['classmap' => ['src/']]);
        $b->setAutoload(['classmap' => ['src/', 'lib/']]);

        $this->repository->expects($this->once())
            ->method('getCanonicalPackages')
            ->will($this->returnValue($packages));

        $this->fs->ensureDirectoryExists($this->vendorDir.'/composer');
        $this->fs->ensureDirectoryExists($this->vendorDir.'/a/a/src');
        $this->fs->ensureDirectoryExists($this->vendorDir.'/b/b/src');
        $this->fs->ensureDirectoryExists($this->vendorDir.'/b/b/lib');
        file_put_contents($this->vendorDir.'/a/a/src/a.php', '<?php class ClassMapFoo {}');
        file_put_contents($this->vendorDir.'/b/b/src/b.php', '<?php class ClassMapBar {}');
        file_put_contents($this->vendorDir.'/b/b/lib/c.php', '<?php class ClassMapBaz {}');

        $this->generator->dump($this->config, $this->repository, $package, $this->im, 'composer', false, '_6');
        $this->assertFileExists($this->vendorDir.'/composer/autoload_classmap.php', "ClassMap file needs to be generated.");
        $this->assertEquals(
            [
                'ClassMapBar' => $this->vendorDir.'/b/b/src/b.php',
                'ClassMapBaz' => $this->vendorDir.'/b/b/lib/c.php',
                'ClassMapFoo' => $this->vendorDir.'/a/a/src/a.php',
                'Composer\\InstalledVersions' => $this->vendorDir.'/composer/InstalledVersions.php',
            ],
            include $this->vendorDir.'/composer/autoload_classmap.php'
        );
        $this->assertAutoloadFiles('classmap4', $this->vendorDir.'/composer', 'classmap');
    }

    public function testVendorsClassMapAutoloadingWithTargetDir(): void
    {
        $package = new RootPackage('root/a', '1.0', '1.0');
        $package->setRequires([
            'a/a' => new Link('a', 'a/a', new MatchAllConstraint()),
            'b/b' => new Link('a', 'b/b', new MatchAllConstraint()),
        ]);

        $packages = [];
        $packages[] = $a = new Package('a/a', '1.0', '1.0');
        $packages[] = $b = new Package('b/b', '1.0', '1.0');
        $a->setAutoload(['classmap' => ['target/src/', 'lib/']]);
        $a->setTargetDir('target');
        $b->setAutoload(['classmap' => ['src/']]);

        $this->repository->expects($this->once())
            ->method('getCanonicalPackages')
            ->will($this->returnValue($packages));

        $this->fs->ensureDirectoryExists($this->vendorDir.'/composer');
        $this->fs->ensureDirectoryExists($this->vendorDir.'/a/a/target/src');
        $this->fs->ensureDirectoryExists($this->vendorDir.'/a/a/target/lib');
        $this->fs->ensureDirectoryExists($this->vendorDir.'/b/b/src');
        file_put_contents($this->vendorDir.'/a/a/target/src/a.php', '<?php class ClassMapFoo {}');
        file_put_contents($this->vendorDir.'/a/a/target/lib/b.php', '<?php class ClassMapBar {}');
        file_put_contents($this->vendorDir.'/b/b/src/c.php', '<?php class ClassMapBaz {}');

        $this->generator->dump($this->config, $this->repository, $package, $this->im, 'composer', false, '_6');
        $this->assertFileExists($this->vendorDir.'/composer/autoload_classmap.php', "ClassMap file needs to be generated.");
        $this->assertEquals(
            [
                'ClassMapBar' => $this->vendorDir.'/a/a/target/lib/b.php',
                'ClassMapBaz' => $this->vendorDir.'/b/b/src/c.php',
                'ClassMapFoo' => $this->vendorDir.'/a/a/target/src/a.php',
                'Composer\\InstalledVersions' => $this->vendorDir.'/composer/InstalledVersions.php',
            ],
            include $this->vendorDir.'/composer/autoload_classmap.php'
        );
    }

    public function testClassMapAutoloadingEmptyDirAndExactFile(): void
    {
        $package = new RootPackage('root/a', '1.0', '1.0');
        $package->setRequires([
            'a/a' => new Link('a', 'a/a', new MatchAllConstraint()),
            'b/b' => new Link('a', 'b/b', new MatchAllConstraint()),
            'c/c' => new Link('a', 'c/c', new MatchAllConstraint()),
        ]);

        $packages = [];
        $packages[] = $a = new Package('a/a', '1.0', '1.0');
        $packages[] = $b = new Package('b/b', '1.0', '1.0');
        $packages[] = $c = new Package('c/c', '1.0', '1.0');
        $a->setAutoload(['classmap' => ['']]);
        $b->setAutoload(['classmap' => ['test.php']]);
        $c->setAutoload(['classmap' => ['./']]);

        $this->repository->expects($this->once())
            ->method('getCanonicalPackages')
            ->will($this->returnValue($packages));

        $this->fs->ensureDirectoryExists($this->vendorDir.'/composer');
        $this->fs->ensureDirectoryExists($this->vendorDir.'/a/a/src');
        $this->fs->ensureDirectoryExists($this->vendorDir.'/b/b');
        $this->fs->ensureDirectoryExists($this->vendorDir.'/c/c/foo');
        file_put_contents($this->vendorDir.'/a/a/src/a.php', '<?php class ClassMapFoo {}');
        file_put_contents($this->vendorDir.'/b/b/test.php', '<?php class ClassMapBar {}');
        file_put_contents($this->vendorDir.'/c/c/foo/test.php', '<?php class ClassMapBaz {}');

        $this->generator->dump($this->config, $this->repository, $package, $this->im, 'composer', false, '_7');
        $this->assertFileExists($this->vendorDir.'/composer/autoload_classmap.php', "ClassMap file needs to be generated.");
        $this->assertEquals(
            [
                'ClassMapBar' => $this->vendorDir.'/b/b/test.php',
                'ClassMapBaz' => $this->vendorDir.'/c/c/foo/test.php',
                'ClassMapFoo' => $this->vendorDir.'/a/a/src/a.php',
                'Composer\\InstalledVersions' => $this->vendorDir.'/composer/InstalledVersions.php',
            ],
            include $this->vendorDir.'/composer/autoload_classmap.php'
        );
        $this->assertAutoloadFiles('classmap5', $this->vendorDir.'/composer', 'classmap');
        $this->assertStringNotContainsString('$loader->setClassMapAuthoritative(true);', (string) file_get_contents($this->vendorDir.'/composer/autoload_real.php'));
        $this->assertStringNotContainsString('$loader->setApcuPrefix(', (string) file_get_contents($this->vendorDir.'/composer/autoload_real.php'));
    }

    public function testClassMapAutoloadingAuthoritativeAndApcu(): void
    {
        $package = new RootPackage('root/a', '1.0', '1.0');
        $package->setRequires([
            'a/a' => new Link('a', 'a/a', new MatchAllConstraint()),
            'b/b' => new Link('a', 'b/b', new MatchAllConstraint()),
            'c/c' => new Link('a', 'c/c', new MatchAllConstraint()),
        ]);

        $packages = [];
        $packages[] = $a = new Package('a/a', '1.0', '1.0');
        $packages[] = $b = new Package('b/b', '1.0', '1.0');
        $packages[] = $c = new Package('c/c', '1.0', '1.0');
        $a->setAutoload(['psr-4' => ['' => 'src/']]);
        $b->setAutoload(['psr-4' => ['' => './']]);
        $c->setAutoload(['psr-4' => ['' => 'foo/']]);

        $this->repository->expects($this->once())
            ->method('getCanonicalPackages')
            ->will($this->returnValue($packages));

        $this->fs->ensureDirectoryExists($this->vendorDir.'/composer');
        $this->fs->ensureDirectoryExists($this->vendorDir.'/a/a/src');
        $this->fs->ensureDirectoryExists($this->vendorDir.'/b/b');
        $this->fs->ensureDirectoryExists($this->vendorDir.'/c/c/foo');
        file_put_contents($this->vendorDir.'/a/a/src/ClassMapFoo.php', '<?php class ClassMapFoo {}');
        file_put_contents($this->vendorDir.'/b/b/ClassMapBar.php', '<?php class ClassMapBar {}');
        file_put_contents($this->vendorDir.'/c/c/foo/ClassMapBaz.php', '<?php class ClassMapBaz {}');

        $this->generator->setClassMapAuthoritative(true);
        $this->generator->setApcu(true);
        $this->generator->dump($this->config, $this->repository, $package, $this->im, 'composer', false, '_7');

        $this->assertFileExists($this->vendorDir.'/composer/autoload_classmap.php', "ClassMap file needs to be generated.");
        $this->assertEquals(
            [
                'ClassMapBar' => $this->vendorDir.'/b/b/ClassMapBar.php',
                'ClassMapBaz' => $this->vendorDir.'/c/c/foo/ClassMapBaz.php',
                'ClassMapFoo' => $this->vendorDir.'/a/a/src/ClassMapFoo.php',
                'Composer\\InstalledVersions' => $this->vendorDir.'/composer/InstalledVersions.php',
            ],
            include $this->vendorDir.'/composer/autoload_classmap.php'
        );
        $this->assertAutoloadFiles('classmap8', $this->vendorDir.'/composer', 'classmap');

        $this->assertStringContainsString('$loader->setClassMapAuthoritative(true);', (string) file_get_contents($this->vendorDir.'/composer/autoload_real.php'));
        $this->assertStringContainsString('$loader->setApcuPrefix(', (string) file_get_contents($this->vendorDir.'/composer/autoload_real.php'));
    }

    public function testClassMapAutoloadingAuthoritativeAndApcuPrefix(): void
    {
        $package = new RootPackage('root/a', '1.0', '1.0');
        $package->setRequires([
            'a/a' => new Link('a', 'a/a', new MatchAllConstraint()),
            'b/b' => new Link('a', 'b/b', new MatchAllConstraint()),
            'c/c' => new Link('a', 'c/c', new MatchAllConstraint()),
        ]);

        $packages = [];
        $packages[] = $a = new Package('a/a', '1.0', '1.0');
        $packages[] = $b = new Package('b/b', '1.0', '1.0');
        $packages[] = $c = new Package('c/c', '1.0', '1.0');
        $a->setAutoload(['psr-4' => ['' => 'src/']]);
        $b->setAutoload(['psr-4' => ['' => './']]);
        $c->setAutoload(['psr-4' => ['' => 'foo/']]);

        $this->repository->expects($this->once())
            ->method('getCanonicalPackages')
            ->will($this->returnValue($packages));

        $this->fs->ensureDirectoryExists($this->vendorDir.'/composer');
        $this->fs->ensureDirectoryExists($this->vendorDir.'/a/a/src');
        $this->fs->ensureDirectoryExists($this->vendorDir.'/b/b');
        $this->fs->ensureDirectoryExists($this->vendorDir.'/c/c/foo');
        file_put_contents($this->vendorDir.'/a/a/src/ClassMapFoo.php', '<?php class ClassMapFoo {}');
        file_put_contents($this->vendorDir.'/b/b/ClassMapBar.php', '<?php class ClassMapBar {}');
        file_put_contents($this->vendorDir.'/c/c/foo/ClassMapBaz.php', '<?php class ClassMapBaz {}');

        $this->generator->setClassMapAuthoritative(true);
        $this->generator->setApcu(true, 'custom\'Prefix');
        $this->generator->dump($this->config, $this->repository, $package, $this->im, 'composer', false, '_7');

        $this->assertFileExists($this->vendorDir.'/composer/autoload_classmap.php', "ClassMap file needs to be generated.");
        $this->assertEquals(
            [
                'ClassMapBar' => $this->vendorDir.'/b/b/ClassMapBar.php',
                'ClassMapBaz' => $this->vendorDir.'/c/c/foo/ClassMapBaz.php',
                'ClassMapFoo' => $this->vendorDir.'/a/a/src/ClassMapFoo.php',
                'Composer\\InstalledVersions' => $this->vendorDir.'/composer/InstalledVersions.php',
            ],
            include $this->vendorDir.'/composer/autoload_classmap.php'
        );
        $this->assertAutoloadFiles('classmap8', $this->vendorDir.'/composer', 'classmap');

        $this->assertStringContainsString('$loader->setClassMapAuthoritative(true);', (string) file_get_contents($this->vendorDir.'/composer/autoload_real.php'));
        $this->assertStringContainsString('$loader->setApcuPrefix(\'custom\\\'Prefix\');', (string) file_get_contents($this->vendorDir.'/composer/autoload_real.php'));
    }

    public function testFilesAutoloadGeneration(): void
    {
        $package = new RootPackage('root/a', '1.0', '1.0');
        $package->setAutoload(['files' => ['root.php']]);
        $package->setRequires([
            'a/a' => new Link('a', 'a/a', new MatchAllConstraint()),
            'b/b' => new Link('a', 'b/b', new MatchAllConstraint()),
            'c/c' => new Link('a', 'c/c', new MatchAllConstraint()),
        ]);

        $packages = [];
        $packages[] = $a = new Package('a/a', '1.0', '1.0');
        $packages[] = $b = new Package('b/b', '1.0', '1.0');
        $packages[] = $c = new Package('c/c', '1.0', '1.0');
        $a->setAutoload(['files' => ['test.php']]);
        $b->setAutoload(['files' => ['test2.php']]);
        $c->setAutoload(['files' => ['test3.php', 'foo/bar/test4.php']]);
        $c->setTargetDir('foo/bar');

        $this->repository->expects($this->once())
            ->method('getCanonicalPackages')
            ->will($this->returnValue($packages));

        $this->fs->ensureDirectoryExists($this->vendorDir.'/a/a');
        $this->fs->ensureDirectoryExists($this->vendorDir.'/b/b');
        $this->fs->ensureDirectoryExists($this->vendorDir.'/c/c/foo/bar');
        file_put_contents($this->vendorDir.'/a/a/test.php', '<?php function testFilesAutoloadGeneration1() {}');
        file_put_contents($this->vendorDir.'/b/b/test2.php', '<?php function testFilesAutoloadGeneration2() {}');
        file_put_contents($this->vendorDir.'/c/c/foo/bar/test3.php', '<?php function testFilesAutoloadGeneration3() {}');
        file_put_contents($this->vendorDir.'/c/c/foo/bar/test4.php', '<?php function testFilesAutoloadGeneration4() {}');
        file_put_contents($this->workingDir.'/root.php', '<?php function testFilesAutoloadGenerationRoot() {}');

        $this->generator->dump($this->config, $this->repository, $package, $this->im, 'composer', false, 'FilesAutoload');
        $this->assertFileContentEquals(__DIR__.'/Fixtures/autoload_functions.php', $this->vendorDir.'/autoload.php');
        $this->assertFileContentEquals(__DIR__.'/Fixtures/autoload_real_functions.php', $this->vendorDir.'/composer/autoload_real.php');
        $this->assertFileContentEquals(__DIR__.'/Fixtures/autoload_static_functions.php', $this->vendorDir.'/composer/autoload_static.php');
        $this->assertFileContentEquals(__DIR__.'/Fixtures/autoload_files_functions.php', $this->vendorDir.'/composer/autoload_files.php');

        $loader = require $this->vendorDir . '/autoload.php';
        $loader->unregister();
        $this->assertTrue(function_exists('testFilesAutoloadGeneration1'));
        $this->assertTrue(function_exists('testFilesAutoloadGeneration2'));
        $this->assertTrue(function_exists('testFilesAutoloadGeneration3'));
        $this->assertTrue(function_exists('testFilesAutoloadGeneration4'));
        $this->assertTrue(function_exists('testFilesAutoloadGenerationRoot'));
    }

    public function testFilesAutoloadGenerationRemoveExtraEntitiesFromAutoloadFiles(): void
    {
        $autoloadPackage = new RootPackage('root/a', '1.0', '1.0');
        $autoloadPackage->setAutoload(['files' => ['root.php']]);
        $autoloadPackage->setIncludePaths(['/lib', '/src']);

        $notAutoloadPackage = new RootPackage('root/a', '1.0', '1.0');

        $requires = [
            'a/a' => new Link('a', 'a/a', new MatchAllConstraint()),
            'b/b' => new Link('a', 'b/b', new MatchAllConstraint()),
            'c/c' => new Link('a', 'c/c', new MatchAllConstraint()),
        ];
        $autoloadPackage->setRequires($requires);
        $notAutoloadPackage->setRequires($requires);

        $autoloadPackages = [];
        $autoloadPackages[] = $a = new Package('a/a', '1.0', '1.0');
        $autoloadPackages[] = $b = new Package('b/b', '1.0', '1.0');
        $autoloadPackages[] = $c = new Package('c/c', '1.0', '1.0');
        $a->setAutoload(['files' => ['test.php']]);
        $a->setIncludePaths(['lib1', 'src1']);
        $b->setAutoload(['files' => ['test2.php']]);
        $b->setIncludePaths(['lib2']);
        $c->setAutoload(['files' => ['test3.php', 'foo/bar/test4.php']]);
        $c->setIncludePaths(['lib3']);
        $c->setTargetDir('foo/bar');

        $notAutoloadPackages = [];
        $notAutoloadPackages[] = $a = new Package('a/a', '1.0', '1.0');
        $notAutoloadPackages[] = $b = new Package('b/b', '1.0', '1.0');
        $notAutoloadPackages[] = $c = new Package('c/c', '1.0', '1.0');

        $this->repository->expects($this->exactly(3))
            ->method('getCanonicalPackages')
            ->willReturnOnConsecutiveCalls(
                $autoloadPackages,
                $notAutoloadPackages,
                $notAutoloadPackages
            );

        $this->fs->ensureDirectoryExists($this->vendorDir.'/a/a');
        $this->fs->ensureDirectoryExists($this->vendorDir.'/b/b');
        $this->fs->ensureDirectoryExists($this->vendorDir.'/c/c/foo/bar');
        file_put_contents($this->vendorDir.'/a/a/test.php', '<?php function testFilesAutoloadGeneration1() {}');
        file_put_contents($this->vendorDir.'/b/b/test2.php', '<?php function testFilesAutoloadGeneration2() {}');
        file_put_contents($this->vendorDir.'/c/c/foo/bar/test3.php', '<?php function testFilesAutoloadGeneration3() {}');
        file_put_contents($this->vendorDir.'/c/c/foo/bar/test4.php', '<?php function testFilesAutoloadGeneration4() {}');
        file_put_contents($this->workingDir.'/root.php', '<?php function testFilesAutoloadGenerationRoot() {}');

        $this->generator->dump($this->config, $this->repository, $autoloadPackage, $this->im, 'composer', false, 'FilesAutoload');
        $this->assertFileContentEquals(__DIR__.'/Fixtures/autoload_functions.php', $this->vendorDir.'/autoload.php');
        $this->assertFileContentEquals(__DIR__.'/Fixtures/autoload_real_functions_with_include_paths.php', $this->vendorDir.'/composer/autoload_real.php');
        $this->assertFileContentEquals(__DIR__.'/Fixtures/autoload_static_functions_with_include_paths.php', $this->vendorDir.'/composer/autoload_static.php');
        $this->assertFileContentEquals(__DIR__.'/Fixtures/autoload_files_functions.php', $this->vendorDir.'/composer/autoload_files.php');
        $this->assertFileContentEquals(__DIR__.'/Fixtures/include_paths_functions.php', $this->vendorDir.'/composer/include_paths.php');

        $this->generator->dump($this->config, $this->repository, $autoloadPackage, $this->im, 'composer', false, 'FilesAutoload');
        $this->assertFileContentEquals(__DIR__.'/Fixtures/autoload_functions.php', $this->vendorDir.'/autoload.php');
        $this->assertFileContentEquals(__DIR__.'/Fixtures/autoload_real_functions_with_include_paths.php', $this->vendorDir.'/composer/autoload_real.php');
        $this->assertFileContentEquals(__DIR__.'/Fixtures/autoload_files_functions_with_removed_extra.php', $this->vendorDir.'/composer/autoload_files.php');
        $this->assertFileContentEquals(__DIR__.'/Fixtures/include_paths_functions_with_removed_extra.php', $this->vendorDir.'/composer/include_paths.php');

        $this->generator->dump($this->config, $this->repository, $notAutoloadPackage, $this->im, 'composer', false, 'FilesAutoload');
        $this->assertFileContentEquals(__DIR__.'/Fixtures/autoload_functions.php', $this->vendorDir.'/autoload.php');
        $this->assertFileContentEquals(__DIR__.'/Fixtures/autoload_real_functions_with_removed_include_paths_and_autolad_files.php', $this->vendorDir.'/composer/autoload_real.php');
        $this->assertFileContentEquals(__DIR__.'/Fixtures/autoload_static_functions_with_removed_include_paths_and_autolad_files.php', $this->vendorDir.'/composer/autoload_static.php');
        $this->assertFileDoesNotExist($this->vendorDir.'/composer/autoload_files.php');
        $this->assertFileDoesNotExist($this->vendorDir.'/composer/include_paths.php');
    }

    public function testFilesAutoloadOrderByDependencies(): void
    {
        $package = new RootPackage('root/a', '1.0', '1.0');
        $package->setAutoload(['files' => ['root2.php']]);
        $package->setRequires([
            'z/foo' => new Link('a', 'z/foo', new MatchAllConstraint()),
            'b/bar' => new Link('a', 'b/bar', new MatchAllConstraint()),
            'd/d' => new Link('a', 'd/d', new MatchAllConstraint()),
            'e/e' => new Link('a', 'e/e', new MatchAllConstraint()),
        ]);

        $packages = [];
        $packages[] = $z = new Package('z/foo', '1.0', '1.0');
        $packages[] = $b = new Package('b/bar', '1.0', '1.0');
        $packages[] = $d = new Package('d/d', '1.0', '1.0');
        $packages[] = $c = new Package('c/lorem', '1.0', '1.0');
        $packages[] = $e = new Package('e/e', '1.0', '1.0');

        // expected order:
        // c requires nothing
        // d requires c
        // b requires c & d
        // e requires c
        // z requires c
        // (b, e, z ordered alphabetically)

        $z->setAutoload(['files' => ['testA.php']]);
        $z->setRequires(['c/lorem' => new Link('z/foo', 'c/lorem', new MatchAllConstraint())]);

        $b->setAutoload(['files' => ['testB.php']]);
        $b->setRequires(['c/lorem' => new Link('b/bar', 'c/lorem', new MatchAllConstraint()), 'd/d' => new Link('b/bar', 'd/d', new MatchAllConstraint())]);

        $c->setAutoload(['files' => ['testC.php']]);

        $d->setAutoload(['files' => ['testD.php']]);
        $d->setRequires(['c/lorem' => new Link('d/d', 'c/lorem', new MatchAllConstraint())]);

        $e->setAutoload(['files' => ['testE.php']]);
        $e->setRequires(['c/lorem' => new Link('e/e', 'c/lorem', new MatchAllConstraint())]);

        $this->repository->expects($this->once())
            ->method('getCanonicalPackages')
            ->will($this->returnValue($packages));

        $this->fs->ensureDirectoryExists($this->vendorDir . '/z/foo');
        $this->fs->ensureDirectoryExists($this->vendorDir . '/b/bar');
        $this->fs->ensureDirectoryExists($this->vendorDir . '/c/lorem');
        $this->fs->ensureDirectoryExists($this->vendorDir . '/d/d');
        $this->fs->ensureDirectoryExists($this->vendorDir . '/e/e');
        file_put_contents($this->vendorDir . '/z/foo/testA.php', '<?php function testFilesAutoloadOrderByDependency1() {}');
        file_put_contents($this->vendorDir . '/b/bar/testB.php', '<?php function testFilesAutoloadOrderByDependency2() {}');
        file_put_contents($this->vendorDir . '/c/lorem/testC.php', '<?php function testFilesAutoloadOrderByDependency3() {}');
        file_put_contents($this->vendorDir . '/d/d/testD.php', '<?php function testFilesAutoloadOrderByDependency4() {}');
        file_put_contents($this->vendorDir . '/e/e/testE.php', '<?php function testFilesAutoloadOrderByDependency5() {}');
        file_put_contents($this->workingDir . '/root2.php', '<?php function testFilesAutoloadOrderByDependencyRoot() {}');

        $this->generator->dump($this->config, $this->repository, $package, $this->im, 'composer', false, 'FilesAutoloadOrder');
        $this->assertFileContentEquals(__DIR__ . '/Fixtures/autoload_functions_by_dependency.php', $this->vendorDir . '/autoload.php');
        $this->assertFileContentEquals(__DIR__ . '/Fixtures/autoload_real_files_by_dependency.php', $this->vendorDir . '/composer/autoload_real.php');
        $this->assertFileContentEquals(__DIR__ . '/Fixtures/autoload_static_files_by_dependency.php', $this->vendorDir . '/composer/autoload_static.php');

        $loader = require $this->vendorDir . '/autoload.php';
        $loader->unregister();

        $this->assertTrue(function_exists('testFilesAutoloadOrderByDependency1'));
        $this->assertTrue(function_exists('testFilesAutoloadOrderByDependency2'));
        $this->assertTrue(function_exists('testFilesAutoloadOrderByDependency3'));
        $this->assertTrue(function_exists('testFilesAutoloadOrderByDependency4'));
        $this->assertTrue(function_exists('testFilesAutoloadOrderByDependency5'));
        $this->assertTrue(function_exists('testFilesAutoloadOrderByDependencyRoot'));
    }

    /**
     * Test that PSR-0 and PSR-4 mappings are processed in the correct order for
     * autoloading and for classmap generation:
     * - The main package has priority over other packages.
     * - Longer namespaces have priority over shorter namespaces.
     */
    public function testOverrideVendorsAutoloading(): void
    {
        $rootPackage = new RootPackage('root/z', '1.0', '1.0');
        $rootPackage->setAutoload([
            'psr-0' => ['A\\B' => $this->workingDir.'/lib'],
            'classmap' => [$this->workingDir.'/src'],
        ]);
        $rootPackage->setRequires([
            'a/a' => new Link('z', 'a/a', new MatchAllConstraint()),
            'b/b' => new Link('z', 'b/b', new MatchAllConstraint()),
        ]);

        $packages = [];
        $packages[] = $a = new Package('a/a', '1.0', '1.0');
        $packages[] = $b = new Package('b/b', '1.0', '1.0');
        $a->setAutoload([
            'psr-0' => ['A' => 'src/', 'A\\B' => 'lib/'],
            'classmap' => ['classmap'],
        ]);
        $b->setAutoload([
            'psr-0' => ['B\\Sub\\Name' => 'src/'],
        ]);

        $this->repository->expects($this->once())
            ->method('getCanonicalPackages')
            ->will($this->returnValue($packages));

        $this->fs->ensureDirectoryExists($this->workingDir.'/lib/A/B');
        $this->fs->ensureDirectoryExists($this->workingDir.'/src/');
        $this->fs->ensureDirectoryExists($this->vendorDir.'/composer');
        $this->fs->ensureDirectoryExists($this->vendorDir.'/a/a/classmap');
        $this->fs->ensureDirectoryExists($this->vendorDir.'/a/a/src');
        $this->fs->ensureDirectoryExists($this->vendorDir.'/a/a/lib/A/B');
        $this->fs->ensureDirectoryExists($this->vendorDir.'/b/b/src');

        // Define the classes A\B\C and Foo\Bar in the main package.
        file_put_contents($this->workingDir.'/lib/A/B/C.php', '<?php namespace A\\B; class C {}');
        file_put_contents($this->workingDir.'/src/classes.php', '<?php namespace Foo; class Bar {}');

        // Define the same two classes in the package a/a.
        file_put_contents($this->vendorDir.'/a/a/lib/A/B/C.php', '<?php namespace A\\B; class C {}');
        file_put_contents($this->vendorDir.'/a/a/classmap/classes.php', '<?php namespace Foo; class Bar {}');

        $expectedNamespace = <<<EOF
<?php

// autoload_namespaces.php @generated by Composer

\$vendorDir = dirname(__DIR__);
\$baseDir = dirname(\$vendorDir);

return array(
    'B\\\\Sub\\\\Name' => array(\$vendorDir . '/b/b/src'),
    'A\\\\B' => array(\$baseDir . '/lib', \$vendorDir . '/a/a/lib'),
    'A' => array(\$vendorDir . '/a/a/src'),
);

EOF;

        // autoload_psr4.php is expected to be empty in this example.
        $expectedPsr4 = <<<EOF
<?php

// autoload_psr4.php @generated by Composer

\$vendorDir = dirname(__DIR__);
\$baseDir = dirname(\$vendorDir);

return array(
);

EOF;

        $expectedClassmap = <<<EOF
<?php

// autoload_classmap.php @generated by Composer

\$vendorDir = dirname(__DIR__);
\$baseDir = dirname(\$vendorDir);

return array(
    'A\\\\B\\\\C' => \$baseDir . '/lib/A/B/C.php',
    'Composer\\\\InstalledVersions' => \$vendorDir . '/composer/InstalledVersions.php',
    'Foo\\\\Bar' => \$baseDir . '/src/classes.php',
);

EOF;

        $this->generator->dump($this->config, $this->repository, $rootPackage, $this->im, 'composer', true, '_9');
        $this->assertStringEqualsFile($this->vendorDir.'/composer/autoload_namespaces.php', $expectedNamespace);
        $this->assertStringEqualsFile($this->vendorDir.'/composer/autoload_psr4.php', $expectedPsr4);
        $this->assertStringEqualsFile($this->vendorDir.'/composer/autoload_classmap.php', $expectedClassmap);
    }

    public function testIncludePathFileGeneration(): void
    {
        $package = new RootPackage('root/a', '1.0', '1.0');
        $packages = [];

        $a = new Package("a/a", "1.0", "1.0");
        $a->setIncludePaths(["lib/"]);

        $b = new Package("b/b", "1.0", "1.0");
        $b->setIncludePaths(["library"]);

        $c = new Package("c", "1.0", "1.0");
        $c->setIncludePaths(["library"]);

        $packages[] = $a;
        $packages[] = $b;
        $packages[] = $c;

        $this->repository->expects($this->once())
            ->method("getCanonicalPackages")
            ->will($this->returnValue($packages));

        $this->fs->ensureDirectoryExists($this->vendorDir.'/composer');

        $this->generator->dump($this->config, $this->repository, $package, $this->im, "composer", false, '_10');

        $this->assertFileContentEquals(__DIR__.'/Fixtures/include_paths.php', $this->vendorDir.'/composer/include_paths.php');
        $this->assertEquals(
            [
                $this->vendorDir."/a/a/lib",
                $this->vendorDir."/b/b/library",
                $this->vendorDir."/c/library",
            ],
            require $this->vendorDir."/composer/include_paths.php"
        );
    }

    public function testIncludePathsArePrependedInAutoloadFile(): void
    {
        $package = new RootPackage('root/a', '1.0', '1.0');
        $packages = [];

        $a = new Package("a/a", "1.0", "1.0");
        $a->setIncludePaths(["lib/"]);

        $packages[] = $a;

        $this->repository->expects($this->once())
            ->method("getCanonicalPackages")
            ->will($this->returnValue($packages));

        mkdir($this->vendorDir."/composer", 0777, true);

        $this->generator->dump($this->config, $this->repository, $package, $this->im, "composer", false, '_11');

        $oldIncludePath = get_include_path();

        $loader = require $this->vendorDir."/autoload.php";
        $loader->unregister();

        $this->assertEquals(
            $this->vendorDir."/a/a/lib".PATH_SEPARATOR.$oldIncludePath,
            get_include_path()
        );

        set_include_path($oldIncludePath);
    }

    public function testIncludePathsInRootPackage(): void
    {
        $package = new RootPackage('root/a', '1.0', '1.0');
        $package->setIncludePaths(['/lib', '/src']);

        $packages = [$a = new Package("a/a", "1.0", "1.0")];
        $a->setIncludePaths(["lib/"]);

        $this->repository->expects($this->once())
            ->method("getCanonicalPackages")
            ->will($this->returnValue($packages));

        mkdir($this->vendorDir."/composer", 0777, true);

        $this->generator->dump($this->config, $this->repository, $package, $this->im, "composer", false, '_12');

        $oldIncludePath = get_include_path();

        $loader = require $this->vendorDir."/autoload.php";
        $loader->unregister();

        $this->assertEquals(
            $this->workingDir."/lib".PATH_SEPARATOR.$this->workingDir."/src".PATH_SEPARATOR.$this->vendorDir."/a/a/lib".PATH_SEPARATOR.$oldIncludePath,
            get_include_path()
        );

        set_include_path($oldIncludePath);
    }

    public function testIncludePathFileWithoutPathsIsSkipped(): void
    {
        $package = new RootPackage('root/a', '1.0', '1.0');
        $packages = [];

        $a = new Package("a/a", "1.0", "1.0");
        $packages[] = $a;

        $this->repository->expects($this->once())
            ->method("getCanonicalPackages")
            ->will($this->returnValue($packages));

        mkdir($this->vendorDir."/composer", 0777, true);

        $this->generator->dump($this->config, $this->repository, $package, $this->im, "composer", false, '_12');

        $this->assertFileDoesNotExist($this->vendorDir."/composer/include_paths.php");
    }

    public function testPreAndPostEventsAreDispatchedDuringAutoloadDump(): void
    {
        $this->eventDispatcher
            ->expects($this->exactly(2))
            ->method('dispatchScript')
            ->withConsecutive(
                [ScriptEvents::PRE_AUTOLOAD_DUMP, false],
                [ScriptEvents::POST_AUTOLOAD_DUMP, false]
            );

        $package = new RootPackage('root/a', '1.0', '1.0');
        $package->setAutoload(['psr-0' => ['Prefix' => 'foo/bar/non/existing/']]);

        $this->repository->expects($this->once())
            ->method('getCanonicalPackages')
            ->will($this->returnValue([]));

        $this->generator->setRunScripts(true);
        $this->generator->dump($this->config, $this->repository, $package, $this->im, 'composer', true, '_8');
    }

    public function testUseGlobalIncludePath(): void
    {
        $package = new RootPackage('root/a', '1.0', '1.0');
        $package->setAutoload([
            'psr-0' => ['Main\\Foo' => '', 'Main\\Bar' => ''],
        ]);
        $package->setTargetDir('Main/Foo/');

        $this->repository->expects($this->once())
            ->method('getCanonicalPackages')
            ->will($this->returnValue([]));

        $this->configValueMap['use-include-path'] = true;

        $this->fs->ensureDirectoryExists($this->vendorDir.'/a');

        $this->generator->dump($this->config, $this->repository, $package, $this->im, 'composer', false, 'IncludePath');
        $this->assertFileContentEquals(__DIR__.'/Fixtures/autoload_real_include_path.php', $this->vendorDir.'/composer/autoload_real.php');
        $this->assertFileContentEquals(__DIR__.'/Fixtures/autoload_static_include_path.php', $this->vendorDir.'/composer/autoload_static.php');
    }

    public function testVendorDirExcludedFromWorkingDir(): void
    {
        $workingDir = $this->vendorDir.'/working-dir';
        $vendorDir = $workingDir.'/../vendor';

        $this->fs->ensureDirectoryExists($workingDir);
        chdir($workingDir);

        $package = new RootPackage('root/a', '1.0', '1.0');
        $package->setAutoload([
            'psr-0' => ['Foo' => 'src'],
            'psr-4' => ['Acme\Foo\\' => 'src-psr4'],
            'classmap' => ['classmap'],
            'files' => ['test.php'],
        ]);
        $package->setRequires([
            'b/b' => new Link('a', 'b/b', new MatchAllConstraint()),
        ]);

        $vendorPackage = new Package('b/b', '1.0', '1.0');
        $vendorPackage->setAutoload([
            'psr-0' => ['Bar' => 'lib'],
            'psr-4' => ['Acme\Bar\\' => 'lib-psr4'],
            'classmap' => ['classmaps'],
            'files' => ['bootstrap.php'],
        ]);

        $this->repository->expects($this->once())
            ->method('getCanonicalPackages')
            ->will($this->returnValue([$vendorPackage]));

        $im = $this->getMockBuilder('Composer\Installer\InstallationManager')
            ->disableOriginalConstructor()
            ->getMock();
        $im->expects($this->any())
            ->method('getInstallPath')
            ->will($this->returnCallback(static function ($package) use ($vendorDir): string {
                $targetDir = $package->getTargetDir();

                return $vendorDir.'/'.$package->getName() . ($targetDir ? '/'.$targetDir : '');
            }));

        $this->fs->ensureDirectoryExists($workingDir.'/src/Foo');
        $this->fs->ensureDirectoryExists($workingDir.'/classmap');
        $this->fs->ensureDirectoryExists($vendorDir.'/composer');
        $this->fs->ensureDirectoryExists($vendorDir.'/b/b/lib/Bar');
        $this->fs->ensureDirectoryExists($vendorDir.'/b/b/classmaps');
        file_put_contents($workingDir.'/src/Foo/Bar.php', '<?php namespace Foo; class Bar {}');
        file_put_contents($workingDir.'/classmap/classes.php', '<?php namespace Foo; class Foo {}');
        file_put_contents($workingDir.'/test.php', '<?php class Foo {}');
        file_put_contents($vendorDir.'/b/b/lib/Bar/Foo.php', '<?php namespace Bar; class Foo {}');
        file_put_contents($vendorDir.'/b/b/classmaps/classes.php', '<?php namespace Bar; class Bar {}');
        file_put_contents($vendorDir.'/b/b/bootstrap.php', '<?php class Bar {}');

        $oldVendorDir = $this->vendorDir;
        $this->vendorDir = $vendorDir;
        $this->generator->dump($this->config, $this->repository, $package, $im, 'composer', true, '_13');
        $this->vendorDir = $oldVendorDir;

        $expectedNamespace = <<<'EOF'
<?php

// autoload_namespaces.php @generated by Composer

$vendorDir = dirname(__DIR__);
$baseDir = dirname($vendorDir).'/working-dir';

return array(
    'Foo' => array($baseDir . '/src'),
    'Bar' => array($vendorDir . '/b/b/lib'),
);

EOF;

        $expectedPsr4 = <<<'EOF'
<?php

// autoload_psr4.php @generated by Composer

$vendorDir = dirname(__DIR__);
$baseDir = dirname($vendorDir).'/working-dir';

return array(
    'Acme\\Foo\\' => array($baseDir . '/src-psr4'),
    'Acme\\Bar\\' => array($vendorDir . '/b/b/lib-psr4'),
);

EOF;

        $expectedClassmap = <<<'EOF'
<?php

// autoload_classmap.php @generated by Composer

$vendorDir = dirname(__DIR__);
$baseDir = dirname($vendorDir).'/working-dir';

return array(
    'Bar\\Bar' => $vendorDir . '/b/b/classmaps/classes.php',
    'Bar\\Foo' => $vendorDir . '/b/b/lib/Bar/Foo.php',
    'Composer\\InstalledVersions' => $vendorDir . '/composer/InstalledVersions.php',
    'Foo\\Bar' => $baseDir . '/src/Foo/Bar.php',
    'Foo\\Foo' => $baseDir . '/classmap/classes.php',
);

EOF;

        $this->assertStringEqualsFile($vendorDir.'/composer/autoload_namespaces.php', $expectedNamespace);
        $this->assertStringEqualsFile($vendorDir.'/composer/autoload_psr4.php', $expectedPsr4);
        $this->assertStringEqualsFile($vendorDir.'/composer/autoload_classmap.php', $expectedClassmap);
        $this->assertStringContainsString("\$vendorDir . '/b/b/bootstrap.php',\n", (string) file_get_contents($vendorDir.'/composer/autoload_files.php'));
        $this->assertStringContainsString("\$baseDir . '/test.php',\n", (string) file_get_contents($vendorDir.'/composer/autoload_files.php'));
    }

    public function testUpLevelRelativePaths(): void
    {
        $workingDir = $this->workingDir.'/working-dir';
        mkdir($workingDir, 0777, true);
        chdir($workingDir);

        $package = new RootPackage('root/a', '1.0', '1.0');
        $package->setAutoload([
            'psr-0' => ['Foo' => '../path/../src'],
            'psr-4' => ['Acme\Foo\\' => '../path/../src-psr4'],
            'classmap' => ['../classmap', '../classmap2/subdir', 'classmap3', 'classmap4'],
            'files' => ['../test.php'],
            'exclude-from-classmap' => ['./../classmap/excluded', '../classmap2', 'classmap3/classes.php', 'classmap4/*/classes.php'],
        ]);

        $this->repository->expects($this->once())
            ->method('getCanonicalPackages')
            ->will($this->returnValue([]));

        $this->fs->ensureDirectoryExists($this->workingDir.'/src/Foo');
        $this->fs->ensureDirectoryExists($this->workingDir.'/classmap/excluded');
        $this->fs->ensureDirectoryExists($this->workingDir.'/classmap2/subdir');
        $this->fs->ensureDirectoryExists($this->workingDir.'/working-dir/classmap3');
        $this->fs->ensureDirectoryExists($this->workingDir.'/working-dir/classmap4/foo/');
        file_put_contents($this->workingDir.'/src/Foo/Bar.php', '<?php namespace Foo; class Bar {}');
        file_put_contents($this->workingDir.'/classmap/classes.php', '<?php namespace Foo; class Foo {}');
        file_put_contents($this->workingDir.'/classmap/excluded/classes.php', '<?php namespace Foo; class Boo {}');
        file_put_contents($this->workingDir.'/classmap2/subdir/classes.php', '<?php namespace Foo; class Boo2 {}');
        file_put_contents($this->workingDir.'/working-dir/classmap3/classes.php', '<?php namespace Foo; class Boo3 {}');
        file_put_contents($this->workingDir.'/working-dir/classmap4/foo/classes.php', '<?php namespace Foo; class Boo4 {}');
        file_put_contents($this->workingDir.'/test.php', '<?php class Foo {}');

        $this->generator->dump($this->config, $this->repository, $package, $this->im, 'composer', true, '_14');

        $expectedNamespace = <<<'EOF'
<?php

// autoload_namespaces.php @generated by Composer

$vendorDir = dirname(__DIR__);
$baseDir = dirname($vendorDir).'/working-dir';

return array(
    'Foo' => array($baseDir . '/../src'),
);

EOF;

        $expectedPsr4 = <<<'EOF'
<?php

// autoload_psr4.php @generated by Composer

$vendorDir = dirname(__DIR__);
$baseDir = dirname($vendorDir).'/working-dir';

return array(
    'Acme\\Foo\\' => array($baseDir . '/../src-psr4'),
);

EOF;

        $expectedClassmap = <<<'EOF'
<?php

// autoload_classmap.php @generated by Composer

$vendorDir = dirname(__DIR__);
$baseDir = dirname($vendorDir).'/working-dir';

return array(
    'Composer\\InstalledVersions' => $vendorDir . '/composer/InstalledVersions.php',
    'Foo\\Bar' => $baseDir . '/../src/Foo/Bar.php',
    'Foo\\Foo' => $baseDir . '/../classmap/classes.php',
);

EOF;

        $this->assertStringEqualsFile($this->vendorDir.'/composer/autoload_namespaces.php', $expectedNamespace);
        $this->assertStringEqualsFile($this->vendorDir.'/composer/autoload_psr4.php', $expectedPsr4);
        $this->assertStringEqualsFile($this->vendorDir.'/composer/autoload_classmap.php', $expectedClassmap);
        $this->assertStringContainsString("\$baseDir . '/../test.php',\n", (string) file_get_contents($this->vendorDir.'/composer/autoload_files.php'));
    }

    public function testAutoloadRulesInPackageThatDoesNotExistOnDisk(): void
    {
        $package = new RootPackage('root/a', '1.0', '1.0');
        $package->setRequires([
            'dep/a' => new Link('root/a', 'dep/a', new MatchAllConstraint(), 'requires'),
        ]);
        $dep = new CompletePackage('dep/a', '1.0', '1.0');

        $this->repository->expects($this->any())
            ->method('getCanonicalPackages')
            ->will($this->returnValue([$dep]));

        $dep->setAutoload([
            'psr-0' => ['Foo' => './src'],
        ]);
        $this->generator->dump($this->config, $this->repository, $package, $this->im, 'composer', true, '_19');

        $expectedNamespace = <<<'EOF'
<?php

// autoload_namespaces.php @generated by Composer

$vendorDir = dirname(__DIR__);
$baseDir = dirname($vendorDir);

return array(
    'Foo' => array($vendorDir . '/dep/a/src'),
);

EOF;
        $this->assertStringEqualsFile($this->vendorDir.'/composer/autoload_namespaces.php', $expectedNamespace);

        $dep->setAutoload([
            'psr-4' => ['Acme\Foo\\' => './src-psr4'],
        ]);
        $this->generator->dump($this->config, $this->repository, $package, $this->im, 'composer', true, '_19');

        $expectedPsr4 = <<<'EOF'
<?php

// autoload_psr4.php @generated by Composer

$vendorDir = dirname(__DIR__);
$baseDir = dirname($vendorDir);

return array(
    'Acme\\Foo\\' => array($vendorDir . '/dep/a/src-psr4'),
);

EOF;
        $this->assertStringEqualsFile($this->vendorDir.'/composer/autoload_psr4.php', $expectedPsr4);

        $dep->setAutoload([
            'classmap' => ['classmap'],
        ]);
        try {
            $this->generator->dump($this->config, $this->repository, $package, $this->im, 'composer', true, '_19');
        } catch (\RuntimeException $e) {
            $this->assertSame('Could not scan for classes inside "'.$this->vendorDir.'/dep/a/classmap" which does not appear to be a file nor a folder', $e->getMessage());
        }

        $dep->setAutoload([
            'files' => ['./test.php'],
        ]);
        $this->generator->dump($this->config, $this->repository, $package, $this->im, 'composer', true, '_19');
        $this->assertStringContainsString("\$vendorDir . '/dep/a/test.php',\n", (string) file_get_contents($this->vendorDir.'/composer/autoload_files.php'));

        $package->setAutoload([
            'exclude-from-classmap' => ['../excludedroot', 'root/excl'],
        ]);
        $dep->setAutoload([
            'exclude-from-classmap' => ['../../excluded', 'foo/bar'],
        ]);
        $map = $this->generator->buildPackageMap($this->im, $package, [$dep]);
        $parsed = $this->generator->parseAutoloads($map, $package);
        $this->assertSame([
            preg_quote(strtr((string) realpath(dirname($this->workingDir)), '\\', '/')).'/excludedroot($|/)',
            preg_quote(strtr((string) realpath($this->workingDir), '\\', '/')).'/root/excl($|/)',
        ], $parsed['exclude-from-classmap']);
    }

    public function testEmptyPaths(): void
    {
        $package = new RootPackage('root/a', '1.0', '1.0');
        $package->setAutoload([
            'psr-0' => ['Foo' => ''],
            'psr-4' => ['Acme\Foo\\' => ''],
            'classmap' => [''],
        ]);

        $this->repository->expects($this->once())
            ->method('getCanonicalPackages')
            ->will($this->returnValue([]));

        $this->fs->ensureDirectoryExists($this->workingDir.'/Foo');
        file_put_contents($this->workingDir.'/Foo/Bar.php', '<?php namespace Foo; class Bar {}');
        file_put_contents($this->workingDir.'/class.php', '<?php namespace Classmap; class Foo {}');

        $this->generator->dump($this->config, $this->repository, $package, $this->im, 'composer', true, '_15');

        $expectedNamespace = <<<'EOF'
<?php

// autoload_namespaces.php @generated by Composer

$vendorDir = dirname(__DIR__);
$baseDir = dirname($vendorDir);

return array(
    'Foo' => array($baseDir . '/'),
);

EOF;

        $expectedPsr4 = <<<'EOF'
<?php

// autoload_psr4.php @generated by Composer

$vendorDir = dirname(__DIR__);
$baseDir = dirname($vendorDir);

return array(
    'Acme\\Foo\\' => array($baseDir . '/'),
);

EOF;

        $expectedClassmap = <<<'EOF'
<?php

// autoload_classmap.php @generated by Composer

$vendorDir = dirname(__DIR__);
$baseDir = dirname($vendorDir);

return array(
    'Classmap\\Foo' => $baseDir . '/class.php',
    'Composer\\InstalledVersions' => $vendorDir . '/composer/InstalledVersions.php',
    'Foo\\Bar' => $baseDir . '/Foo/Bar.php',
);

EOF;

        $this->assertStringEqualsFile($this->vendorDir.'/composer/autoload_namespaces.php', $expectedNamespace);
        $this->assertStringEqualsFile($this->vendorDir.'/composer/autoload_psr4.php', $expectedPsr4);
        $this->assertStringEqualsFile($this->vendorDir.'/composer/autoload_classmap.php', $expectedClassmap);
    }

    public function testVendorSubstringPath(): void
    {
        $package = new RootPackage('root/a', '1.0', '1.0');
        $package->setAutoload([
            'psr-0' => ['Foo' => 'composer-test-autoload-src/src'],
            'psr-4' => ['Acme\Foo\\' => 'composer-test-autoload-src/src-psr4'],
        ]);

        $this->repository->expects($this->once())
            ->method('getCanonicalPackages')
            ->will($this->returnValue([]));

        $this->fs->ensureDirectoryExists($this->vendorDir.'/a');

        $expectedNamespace = <<<'EOF'
<?php

// autoload_namespaces.php @generated by Composer

$vendorDir = dirname(__DIR__);
$baseDir = dirname($vendorDir);

return array(
    'Foo' => array($baseDir . '/composer-test-autoload-src/src'),
);

EOF;

        $expectedPsr4 = <<<'EOF'
<?php

// autoload_psr4.php @generated by Composer

$vendorDir = dirname(__DIR__);
$baseDir = dirname($vendorDir);

return array(
    'Acme\\Foo\\' => array($baseDir . '/composer-test-autoload-src/src-psr4'),
);

EOF;

        $this->generator->dump($this->config, $this->repository, $package, $this->im, 'composer', false, 'VendorSubstring');
        $this->assertStringEqualsFile($this->vendorDir.'/composer/autoload_namespaces.php', $expectedNamespace);
        $this->assertStringEqualsFile($this->vendorDir.'/composer/autoload_psr4.php', $expectedPsr4);
    }

    public function testExcludeFromClassmap(): void
    {
        $package = new RootPackage('root/a', '1.0', '1.0');
        $package->setAutoload([
            'psr-0' => [
                'Main' => 'src/',
                'Lala' => ['src/', 'lib/'],
            ],
            'psr-4' => [
                'Acme\Fruit\\' => 'src-fruit/',
                'Acme\Cake\\' => ['src-cake/', 'lib-cake/'],
            ],
            'classmap' => ['composersrc/'],
            'exclude-from-classmap' => [
                '/composersrc/foo/bar/',
                '/composersrc/excludedTests/',
                '/composersrc/ClassToExclude.php',
                '/composersrc/*/excluded/excsubpath',
                '**/excsubpath',
                'composers',    // should _not_ cause exclusion of /composersrc/**, as it is equivalent to /composers/**
                '/src-ca/',     // should _not_ cause exclusion of /src-cake/**, as it is equivalent to /src-ca/**
            ],
        ]);

        $this->repository->expects($this->once())
            ->method('getCanonicalPackages')
            ->will($this->returnValue([]));

        $this->fs->ensureDirectoryExists($this->workingDir.'/composer');
        $this->fs->ensureDirectoryExists($this->workingDir.'/src/Lala/Test');
        $this->fs->ensureDirectoryExists($this->workingDir.'/lib');
        file_put_contents($this->workingDir.'/src/Lala/ClassMapMain.php', '<?php namespace Lala; class ClassMapMain {}');
        file_put_contents($this->workingDir.'/src/Lala/Test/ClassMapMainTest.php', '<?php namespace Lala\Test; class ClassMapMainTest {}');

        $this->fs->ensureDirectoryExists($this->workingDir.'/src-fruit');
        $this->fs->ensureDirectoryExists($this->workingDir.'/src-cake');
        $this->fs->ensureDirectoryExists($this->workingDir.'/lib-cake');
        file_put_contents($this->workingDir.'/src-cake/ClassMapBar.php', '<?php namespace Acme\Cake; class ClassMapBar {}');

        $this->fs->ensureDirectoryExists($this->workingDir.'/composersrc');
        $this->fs->ensureDirectoryExists($this->workingDir.'/composersrc/tests');
        file_put_contents($this->workingDir.'/composersrc/foo.php', '<?php class ClassMapFoo {}');

        // these classes should not be found in the classmap
        $this->fs->ensureDirectoryExists($this->workingDir.'/composersrc/excludedTests');
        file_put_contents($this->workingDir.'/composersrc/excludedTests/bar.php', '<?php class ClassExcludeMapFoo {}');
        file_put_contents($this->workingDir.'/composersrc/ClassToExclude.php', '<?php class ClassClassToExclude {}');
        $this->fs->ensureDirectoryExists($this->workingDir.'/composersrc/long/excluded/excsubpath');
        file_put_contents($this->workingDir.'/composersrc/long/excluded/excsubpath/foo.php', '<?php class ClassExcludeMapFoo2 {}');
        file_put_contents($this->workingDir.'/composersrc/long/excluded/excsubpath/bar.php', '<?php class ClassExcludeMapBar {}');

        // symlink directory in project directory in classmap
        $this->fs->ensureDirectoryExists($this->workingDir.'/forks/bar/src/exclude');
        $this->fs->ensureDirectoryExists($this->workingDir.'/composersrc/foo');

        file_put_contents($this->workingDir.'/forks/bar/src/exclude/FooExclClass.php', '<?php class FooExclClass {};');
        $target = $this->workingDir.'/forks/bar/';
        $link = $this->workingDir.'/composersrc/foo/bar';
        $command = Platform::isWindows()
            ? 'mklink /j "' . str_replace('/', '\\', $link) . '" "' . str_replace('/', '\\', $target) . '"'
            : 'ln -s "' . $target . '" "' . $link . '"';
        exec($command);

        $this->generator->dump($this->config, $this->repository, $package, $this->im, 'composer', true, '_1');

        // Assert that autoload_classmap.php was correctly generated.
        $this->assertAutoloadFiles('classmap', $this->vendorDir.'/composer', 'classmap');
    }

    /**
     * @param array<string, Link>  $requires
     * @param array<string, Link>  $provides
     * @param array<string, Link>  $replaces
     * @param bool|array<string>   $ignorePlatformReqs
     *
     * @dataProvider platformCheckProvider
     */
    public function testGeneratesPlatformCheck(array $requires, ?string $expectedFixture, array $provides = [], array $replaces = [], $ignorePlatformReqs = false): void
    {
        $package = new RootPackage('root/a', '1.0', '1.0');
        $package->setRequires($requires);

        if ($provides) {
            $package->setProvides($provides);
        }

        if ($replaces) {
            $package->setReplaces($replaces);
        }

        $this->repository->expects($this->once())
            ->method('getCanonicalPackages')
            ->will($this->returnValue([]));

        $this->generator->setPlatformRequirementFilter(PlatformRequirementFilterFactory::fromBoolOrList($ignorePlatformReqs));
        $this->generator->dump($this->config, $this->repository, $package, $this->im, 'composer', true, '_1');

        if (null === $expectedFixture) {
            $this->assertFileDoesNotExist($this->vendorDir . '/composer/platform_check.php');
            $this->assertStringNotContainsString("require __DIR__ . '/platform_check.php';", (string) file_get_contents($this->vendorDir.'/composer/autoload_real.php'));
        } else {
            $this->assertFileContentEquals(__DIR__ . '/Fixtures/platform/' . $expectedFixture . '.php', $this->vendorDir . '/composer/platform_check.php');
            $this->assertStringContainsString("require __DIR__ . '/platform_check.php';", (string) file_get_contents($this->vendorDir.'/composer/autoload_real.php'));
        }
    }

    /**
     * @return array<string, mixed[]>
     */
    public function platformCheckProvider(): array
    {
        $versionParser = new VersionParser();

        return [
            'Typical project requirements' => [
                [
                    'php' => new Link('a', 'php', $versionParser->parseConstraints('^7.2')),
                    'ext-xml' => new Link('a', 'ext-xml', $versionParser->parseConstraints('*')),
                    'ext-json' => new Link('a', 'ext-json', $versionParser->parseConstraints('*')),
                ],
                'typical',
            ],
            'No PHP lower bound' => [
                [
                    'php' => new Link('a', 'php', $versionParser->parseConstraints('< 8')),
                ],
                null,
            ],
            'No PHP upper bound' => [
                [
                    'php' => new Link('a', 'php', $versionParser->parseConstraints('>= 7.2')),
                ],
                'no_php_upper_bound',
            ],
            'Specific PHP release version' => [
                [
                    'php' => new Link('a', 'php', $versionParser->parseConstraints('^7.2.8')),
                ],
                'specific_php_release',
            ],
            'No PHP required' => [
                [
                    'ext-xml' => new Link('a', 'ext-xml', $versionParser->parseConstraints('*')),
                    'ext-json' => new Link('a', 'ext-json', $versionParser->parseConstraints('*')),
                ],
                'no_php_required',
            ],
            'Ignoring all platform requirements skips check completely' => [
                [
                    'php' => new Link('a', 'php', $versionParser->parseConstraints('^7.2')),
                    'ext-xml' => new Link('a', 'ext-xml', $versionParser->parseConstraints('*')),
                    'ext-json' => new Link('a', 'ext-json', $versionParser->parseConstraints('*')),
                ],
                null,
                [],
                [],
                true,
            ],
            'Ignored platform requirements are not checked for' => [
                [
                    'php' => new Link('a', 'php', $versionParser->parseConstraints('^7.2.8')),
                    'ext-xml' => new Link('a', 'ext-xml', $versionParser->parseConstraints('*')),
                    'ext-json' => new Link('a', 'ext-json', $versionParser->parseConstraints('*')),
                    'ext-pdo' => new Link('a', 'ext-pdo', $versionParser->parseConstraints('*')),
                ],
                'no_php_required',
                [],
                [],
                ['php', 'ext-pdo'],
            ],
            'Via wildcard ignored platform requirements are not checked for' => [
                [
                    'php' => new Link('a', 'php', $versionParser->parseConstraints('^7.2.8')),
                    'ext-xml' => new Link('a', 'ext-xml', $versionParser->parseConstraints('*')),
                    'ext-json' => new Link('a', 'ext-json', $versionParser->parseConstraints('*')),
                    'ext-fileinfo' => new Link('a', 'ext-fileinfo', $versionParser->parseConstraints('*')),
                    'ext-filesystem' => new Link('a', 'ext-filesystem', $versionParser->parseConstraints('*')),
                    'ext-filter' => new Link('a', 'ext-filter', $versionParser->parseConstraints('*')),
                ],
                'no_php_required',
                [],
                [],
                ['php', 'ext-fil*'],
            ],
            'No extensions required' => [
                [
                    'php' => new Link('a', 'php', $versionParser->parseConstraints('^7.2')),
                ],
                'no_extensions_required',
            ],
            'Replaced/provided extensions are not checked for + checking case insensitivity' => [
                [
                    'ext-xml' => new Link('a', 'ext-xml', $versionParser->parseConstraints('^7.2')),
                    'ext-pdo' => new Link('a', 'ext-Pdo', $versionParser->parseConstraints('^7.2')),
                    'ext-bcmath' => new Link('a', 'ext-bcMath', $versionParser->parseConstraints('^7.2')),
                ],
                'replaced_provided_exts',
                [
                    // constraint does not satisfy all the ^7.2 requirement so we do not accept it as being replaced
                    'ext-pdo' => new Link('a', 'ext-PDO', $versionParser->parseConstraints('7.1.*')),
                    // valid replace of bcmath so no need to check for it
                    'ext-bcmath' => new Link('a', 'ext-BCMath', $versionParser->parseConstraints('^7.1')),
                ],
                [
                    // valid provide of ext-xml so no need to check for it
                    'ext-xml' => new Link('a', 'ext-XML', $versionParser->parseConstraints('*')),
                ],
            ],
        ];
    }

    private function assertAutoloadFiles(string $name, string $dir, string $type = 'namespaces'): void
    {
        $a = __DIR__.'/Fixtures/autoload_'.$name.'.php';
        $b = $dir.'/autoload_'.$type.'.php';
        $this->assertFileContentEquals($a, $b);
    }

    public static function assertFileContentEquals(string $expected, string $actual, ?string $message = null): void
    {
        self::assertSame(
            str_replace("\r", '', (string) file_get_contents($expected)),
            str_replace("\r", '', (string) file_get_contents($actual)),
            $message ?? $expected.' equals '.$actual
        );
    }
}
