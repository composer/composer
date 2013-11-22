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
use Composer\Console\Application;
use Composer\Config;
use Composer\Json\JsonFile;
use Composer\Repository\ArrayRepository;
use Composer\Repository\RepositoryManager;
use Composer\Repository\InstalledArrayRepository;
use Composer\Package\RootPackageInterface;
use Composer\Package\Link;
use Composer\Package\Locker;
use Composer\Test\Mock\FactoryMock;
use Composer\Test\Mock\InstalledFilesystemRepositoryMock;
use Composer\Test\Mock\InstallationManagerMock;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\StreamOutput;
use Composer\TestCase;

class InstallerTest extends TestCase
{
    protected $prevCwd;

    public function setUp()
    {
        $this->prevCwd = getcwd();
        chdir(__DIR__);
    }

    public function tearDown()
    {
        chdir($this->prevCwd);
    }

    /**
     * @dataProvider provideInstaller
     */
    public function testInstaller(RootPackageInterface $rootPackage, $repositories, array $options)
    {
        $io = $this->getMock('Composer\IO\IOInterface');

        $downloadManager = $this->getMock('Composer\Downloader\DownloadManager');
        $config = $this->getMock('Composer\Config');

        $repositoryManager = new RepositoryManager($io, $config);
        $repositoryManager->setLocalRepository(new InstalledArrayRepository());

        if (!is_array($repositories)) {
            $repositories = array($repositories);
        }
        foreach ($repositories as $repository) {
            $repositoryManager->addRepository($repository);
        }

        $locker = $this->getMockBuilder('Composer\Package\Locker')->disableOriginalConstructor()->getMock();
        $installationManager = new InstallationManagerMock();

        $eventDispatcher = $this->getMockBuilder('Composer\EventDispatcher\EventDispatcher')->disableOriginalConstructor()->getMock();
        $autoloadGenerator = $this->getMockBuilder('Composer\Autoload\AutoloadGenerator')->disableOriginalConstructor()->getMock();

        $installer = new Installer($io, $config, clone $rootPackage, $downloadManager, $repositoryManager, $locker, $installationManager, $eventDispatcher, $autoloadGenerator);
        $result = $installer->run();
        $this->assertSame(0, $result);

        $expectedInstalled   = isset($options['install']) ? $options['install'] : array();
        $expectedUpdated     = isset($options['update']) ? $options['update'] : array();
        $expectedUninstalled = isset($options['uninstall']) ? $options['uninstall'] : array();

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

        $a = $this->getPackage('A', '1.0.0', 'Composer\Package\RootPackage');
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

        $a = $this->getPackage('A', '1.0.0', 'Composer\Package\RootPackage');
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

    /**
     * @dataProvider getIntegrationTests
     */
    public function testIntegration($file, $message, $condition, $composerConfig, $lock, $installed, $run, $expectLock, $expectOutput, $expect)
    {
        if ($condition) {
            eval('$res = '.$condition.';');
            if (!$res) {
                $this->markTestSkipped($condition);
            }
        }

        $output = null;
        $io = $this->getMock('Composer\IO\IOInterface');
        $io->expects($this->any())
            ->method('write')
            ->will($this->returnCallback(function ($text, $newline) use (&$output) {
                $output .= $text . ($newline ? "\n":"");
            }));

        $composer = FactoryMock::create($io, $composerConfig);

        $jsonMock = $this->getMockBuilder('Composer\Json\JsonFile')->disableOriginalConstructor()->getMock();
        $jsonMock->expects($this->any())
            ->method('read')
            ->will($this->returnValue($installed));
        $jsonMock->expects($this->any())
            ->method('exists')
            ->will($this->returnValue(true));

        $repositoryManager = $composer->getRepositoryManager();
        $repositoryManager->setLocalRepository(new InstalledFilesystemRepositoryMock($jsonMock));

        $lockJsonMock = $this->getMockBuilder('Composer\Json\JsonFile')->disableOriginalConstructor()->getMock();
        $lockJsonMock->expects($this->any())
            ->method('read')
            ->will($this->returnValue($lock));
        $lockJsonMock->expects($this->any())
            ->method('exists')
            ->will($this->returnValue(true));

        if ($expectLock) {
            $actualLock = array();
            $lockJsonMock->expects($this->atLeastOnce())
                ->method('write')
                ->will($this->returnCallback(function ($hash, $options) use (&$actualLock) {
                    // need to do assertion outside of mock for nice phpunit output
                    // so store value temporarily in reference for later assetion
                    $actualLock = $hash;
                }));
        }

        $locker = new Locker($io, $lockJsonMock, $repositoryManager, $composer->getInstallationManager(), md5(json_encode($composerConfig)));
        $composer->setLocker($locker);

        $eventDispatcher = $this->getMockBuilder('Composer\EventDispatcher\EventDispatcher')->disableOriginalConstructor()->getMock();
        $autoloadGenerator = $this->getMock('Composer\Autoload\AutoloadGenerator', array(), array($eventDispatcher));
        $composer->setAutoloadGenerator($autoloadGenerator);
        $composer->setEventDispatcher($eventDispatcher);

        $installer = Installer::create(
            $io,
            $composer
        );

        $application = new Application;
        $application->get('install')->setCode(function ($input, $output) use ($installer) {
            $installer
                ->setDevMode($input->getOption('dev'))
                ->setDryRun($input->getOption('dry-run'));

            return $installer->run();
        });

        $application->get('update')->setCode(function ($input, $output) use ($installer) {
            $installer
                ->setDevMode($input->getOption('dev'))
                ->setUpdate(true)
                ->setDryRun($input->getOption('dry-run'))
                ->setUpdateWhitelist($input->getArgument('packages'))
                ->setWhitelistDependencies($input->getOption('with-dependencies'));

            return $installer->run();
        });

        if (!preg_match('{^(install|update)\b}', $run)) {
            throw new \UnexpectedValueException('The run command only supports install and update');
        }

        $application->setAutoExit(false);
        $appOutput = fopen('php://memory', 'w+');
        $result = $application->run(new StringInput($run), new StreamOutput($appOutput));
        fseek($appOutput, 0);
        $this->assertEquals(0, $result, $output . stream_get_contents($appOutput));

        if ($expectLock) {
            unset($actualLock['hash']);
            unset($actualLock['_readme']);
            $this->assertEquals($expectLock, $actualLock);
        }

        $installationManager = $composer->getInstallationManager();
        $this->assertSame($expect, implode("\n", $installationManager->getTrace()));

        if ($expectOutput) {
            $this->assertEquals($expectOutput, $output);
        }
    }

    public function getIntegrationTests()
    {
        $fixturesDir = realpath(__DIR__.'/Fixtures/installer/');
        $tests = array();

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($fixturesDir), \RecursiveIteratorIterator::LEAVES_ONLY) as $file) {
            if (!preg_match('/\.test$/', $file)) {
                continue;
            }

            $test = file_get_contents($file->getRealpath());

            $content = '(?:.(?!--[A-Z]))+';
            $pattern = '{^
                --TEST--\s*(?P<test>.*?)\s*
                (?:--CONDITION--\s*(?P<condition>'.$content.'))?\s*
                --COMPOSER--\s*(?P<composer>'.$content.')\s*
                (?:--LOCK--\s*(?P<lock>'.$content.'))?\s*
                (?:--INSTALLED--\s*(?P<installed>'.$content.'))?\s*
                --RUN--\s*(?P<run>.*?)\s*
                (?:--EXPECT-LOCK--\s*(?P<expectLock>'.$content.'))?\s*
                (?:--EXPECT-OUTPUT--\s*(?P<expectOutput>'.$content.'))?\s*
                --EXPECT--\s*(?P<expect>.*?)\s*
            $}xs';

            $installed = array();
            $installedDev = array();
            $lock = array();
            $expectLock = array();

            if (preg_match($pattern, $test, $match)) {
                try {
                    $message = $match['test'];
                    $condition = !empty($match['condition']) ? $match['condition'] : null;
                    $composer = JsonFile::parseJson($match['composer']);
                    if (!empty($match['lock'])) {
                        $lock = JsonFile::parseJson($match['lock']);
                        if (!isset($lock['hash'])) {
                            $lock['hash'] = md5(json_encode($composer));
                        }
                    }
                    if (!empty($match['installed'])) {
                        $installed = JsonFile::parseJson($match['installed']);
                    }
                    $run = $match['run'];
                    if (!empty($match['expectLock'])) {
                        $expectLock = JsonFile::parseJson($match['expectLock']);
                    }
                    $expectOutput = $match['expectOutput'];
                    $expect = $match['expect'];
                } catch (\Exception $e) {
                    die(sprintf('Test "%s" is not valid: '.$e->getMessage(), str_replace($fixturesDir.'/', '', $file)));
                }
            } else {
                die(sprintf('Test "%s" is not valid, did not match the expected format.', str_replace($fixturesDir.'/', '', $file)));
            }

            $tests[] = array(str_replace($fixturesDir.'/', '', $file), $message, $condition, $composer, $lock, $installed, $run, $expectLock, $expectOutput, $expect);
        }

        return $tests;
    }
}
