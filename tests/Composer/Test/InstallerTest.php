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
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Composer\TestCase;
use Composer\IO\BufferIO;

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

        $downloadManager = $this->getMock('Composer\Downloader\DownloadManager', array(), array($io));
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
                'install' => array($b),
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
                'install' => array($b),
            ),
        );

        return $cases;
    }

    /**
     * @dataProvider getIntegrationTests
     */
    public function testIntegration($file, $message, $condition, $composerConfig, $lock, $installed, $run, $expectLock, $expectOutput, $expect, $expectResult)
    {
        if ($condition) {
            eval('$res = '.$condition.';');
            if (!$res) {
                $this->markTestSkipped($condition);
            }
        }

        $io = new BufferIO('', OutputInterface::VERBOSITY_NORMAL, new OutputFormatter(false));

        // Prepare for exceptions
        if (!is_int($expectResult)) {
            $normalizedOutput = rtrim(str_replace("\n", PHP_EOL, $expect));
            $this->setExpectedException($expectResult, $normalizedOutput);
        }

        // Create Composer mock object according to configuration
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

        $contents = json_encode($composerConfig);
        $locker   = new Locker($io, $lockJsonMock, $repositoryManager, $composer->getInstallationManager(), $contents);
        $composer->setLocker($locker);

        $eventDispatcher = $this->getMockBuilder('Composer\EventDispatcher\EventDispatcher')->disableOriginalConstructor()->getMock();
        $autoloadGenerator = $this->getMock('Composer\Autoload\AutoloadGenerator', array(), array($eventDispatcher));
        $composer->setAutoloadGenerator($autoloadGenerator);
        $composer->setEventDispatcher($eventDispatcher);

        $installer = Installer::create($io, $composer);

        $application = new Application;
        $application->get('install')->setCode(function ($input, $output) use ($installer) {
            $installer
                ->setDevMode(!$input->getOption('no-dev'))
                ->setDryRun($input->getOption('dry-run'))
                ->setIgnorePlatformRequirements($input->getOption('ignore-platform-reqs'));

            return $installer->run();
        });

        $application->get('update')->setCode(function ($input, $output) use ($installer) {
            $installer
                ->setDevMode(!$input->getOption('no-dev'))
                ->setUpdate(true)
                ->setDryRun($input->getOption('dry-run'))
                ->setUpdateWhitelist($input->getArgument('packages'))
                ->setWhitelistDependencies($input->getOption('with-dependencies'))
                ->setPreferStable($input->getOption('prefer-stable'))
                ->setPreferLowest($input->getOption('prefer-lowest'))
                ->setIgnorePlatformRequirements($input->getOption('ignore-platform-reqs'));

            return $installer->run();
        });

        if (!preg_match('{^(install|update)\b}', $run)) {
            throw new \UnexpectedValueException('The run command only supports install and update');
        }

        $application->setAutoExit(false);
        $appOutput = fopen('php://memory', 'w+');
        $result = $application->run(new StringInput($run), new StreamOutput($appOutput));
        fseek($appOutput, 0);

        // Shouldn't check output and results if an exception was expected by this point
        if (!is_int($expectResult)) {
            return;
        }

        $output = str_replace("\r", '', $io->getOutput());
        $this->assertEquals($expectResult, $result, $output . stream_get_contents($appOutput));
        if ($expectLock) {
            unset($actualLock['hash']);
            unset($actualLock['content-hash']);
            unset($actualLock['_readme']);
            $this->assertEquals($expectLock, $actualLock);
        }

        $installationManager = $composer->getInstallationManager();
        $this->assertSame(rtrim($expect), implode("\n", $installationManager->getTrace()));

        if ($expectOutput) {
            $this->assertStringMatchesFormat(rtrim($expectOutput), rtrim($output));
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

            $testData = $this->readTestFile($file, $fixturesDir);

            $installed = array();
            $installedDev = array();
            $lock = array();
            $expectLock = array();
            $expectResult = 0;

            try {
                $message = $testData['TEST'];
                $condition = !empty($testData['CONDITION']) ? $testData['CONDITION'] : null;
                $composer = JsonFile::parseJson($testData['COMPOSER']);

                if (isset($composer['repositories'])) {
                    foreach ($composer['repositories'] as &$repo) {
                        if ($repo['type'] !== 'composer') {
                            continue;
                        }

                        // Change paths like file://foobar to file:///path/to/fixtures
                        if (preg_match('{^file://[^/]}', $repo['url'])) {
                            $repo['url'] = 'file://' . strtr($fixturesDir, '\\', '/') . '/' . substr($repo['url'], 7);
                        }

                        unset($repo);
                    }
                }

                if (!empty($testData['LOCK'])) {
                    $lock = JsonFile::parseJson($testData['LOCK']);
                    if (!isset($lock['hash'])) {
                        $lock['hash'] = md5(json_encode($composer));
                    }
                }
                if (!empty($testData['INSTALLED'])) {
                    $installed = JsonFile::parseJson($testData['INSTALLED']);
                }
                $run = $testData['RUN'];
                if (!empty($testData['EXPECT-LOCK'])) {
                    $expectLock = JsonFile::parseJson($testData['EXPECT-LOCK']);
                }
                $expectOutput = isset($testData['EXPECT-OUTPUT']) ? $testData['EXPECT-OUTPUT'] : null;
                $expect = $testData['EXPECT'];
                if (!empty($testData['EXPECT-EXCEPTION'])) {
                    $expectResult = $testData['EXPECT-EXCEPTION'];
                    if (!empty($testData['EXPECT-EXIT-CODE'])) {
                        throw new \LogicException('EXPECT-EXCEPTION and EXPECT-EXIT-CODE are mutually exclusive');
                    }
                } elseif (!empty($testData['EXPECT-EXIT-CODE'])) {
                    $expectResult = (int) $testData['EXPECT-EXIT-CODE'];
                } else {
                    $expectResult = 0;
                }
            } catch (\Exception $e) {
                die(sprintf('Test "%s" is not valid: '.$e->getMessage(), str_replace($fixturesDir.'/', '', $file)));
            }

            $tests[basename($file)] = array(str_replace($fixturesDir.'/', '', $file), $message, $condition, $composer, $lock, $installed, $run, $expectLock, $expectOutput, $expect, $expectResult);
        }

        return $tests;
    }

    protected function readTestFile(\SplFileInfo $file, $fixturesDir)
    {
        $tokens = preg_split('#(?:^|\n*)--([A-Z-]+)--\n#', file_get_contents($file->getRealPath()), null, PREG_SPLIT_DELIM_CAPTURE);

        $sectionInfo = array(
            'TEST' => true,
            'CONDITION' => false,
            'COMPOSER' => true,
            'LOCK' => false,
            'INSTALLED'  => false,
            'RUN' => true,
            'EXPECT-LOCK' => false,
            'EXPECT-OUTPUT' => false,
            'EXPECT-EXIT-CODE' => false,
            'EXPECT-EXCEPTION' => false,
            'EXPECT' => true,
        );

        $section = null;
        foreach ($tokens as $i => $token) {
            if (null === $section && empty($token)) {
                continue; // skip leading blank
            }

            if (null === $section) {
                if (!isset($sectionInfo[$token])) {
                    throw new \RuntimeException(sprintf(
                        'The test file "%s" must not contain a section named "%s".',
                        str_replace($fixturesDir.'/', '', $file),
                        $token
                    ));
                }
                $section = $token;
                continue;
            }

            $sectionData = $token;

            $data[$section] = $sectionData;
            $section = $sectionData = null;
        }

        foreach ($sectionInfo as $section => $required) {
            if ($required && !isset($data[$section])) {
                throw new \RuntimeException(sprintf(
                    'The test file "%s" must have a section named "%s".',
                    str_replace($fixturesDir.'/', '', $file),
                    $section
                ));
            }
        }

        return $data;
    }
}
