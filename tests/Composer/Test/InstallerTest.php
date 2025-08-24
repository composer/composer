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

namespace Composer\Test;

use Composer\DependencyResolver\Request;
use Composer\Filter\PlatformRequirementFilter\PlatformRequirementFilterFactory;
use Composer\Installer;
use Composer\Pcre\Preg;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Composer\IO\BufferIO;
use Composer\Json\JsonFile;
use Composer\Package\Dumper\ArrayDumper;
use Composer\Util\Filesystem;
use Composer\Repository\ArrayRepository;
use Composer\Repository\RepositoryManager;
use Composer\Repository\RepositoryInterface;
use Composer\Repository\InstalledArrayRepository;
use Composer\Package\RootPackageInterface;
use Composer\Package\BasePackage;
use Composer\Package\PackageInterface;
use Composer\Package\Link;
use Composer\Package\Locker;
use Composer\Test\Mock\FactoryMock;
use Composer\Test\Mock\InstalledFilesystemRepositoryMock;
use Composer\Test\Mock\InstallationManagerMock;
use Composer\Util\Platform;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\StreamOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Formatter\OutputFormatter;

class InstallerTest extends TestCase
{
    /** @var string */
    private $prevCwd;
    /** @var ?string */
    protected $tempComposerHome;

    public function setUp(): void
    {
        $this->prevCwd = Platform::getCwd();
        chdir(__DIR__);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        Platform::clearEnv('COMPOSER_POOL_OPTIMIZER');
        Platform::clearEnv('COMPOSER_FUND');

        chdir($this->prevCwd);
        if (isset($this->tempComposerHome) && is_dir($this->tempComposerHome)) {
            $fs = new Filesystem;
            $fs->removeDirectory($this->tempComposerHome);
        }
    }

    /**
     * @dataProvider provideInstaller
     * @param RootPackageInterface&BasePackage $rootPackage
     * @param RepositoryInterface[] $repositories
     * @param mixed[] $options
     */
    public function testInstaller(RootPackageInterface $rootPackage, array $repositories, array $options): void
    {
        $io = new BufferIO('', OutputInterface::VERBOSITY_NORMAL, new OutputFormatter(false));

        $downloadManager = $this->getMockBuilder('Composer\Downloader\DownloadManager')
            ->setConstructorArgs([$io])
            ->getMock();
        $config = $this->getMockBuilder('Composer\Config')->getMock();
        $config->expects($this->any())
            ->method('get')
            ->will($this->returnCallback(static function ($key) {
                switch ($key) {
                    case 'vendor-dir':
                        return 'foo';
                    case 'lock':
                    case 'notify-on-install':
                        return true;
                    case 'platform':
                        return [];
                }

                throw new \UnexpectedValueException('Unknown key '.$key);
            }));

        $eventDispatcher = $this->getMockBuilder('Composer\EventDispatcher\EventDispatcher')->disableOriginalConstructor()->getMock();
        $httpDownloader = $this->getMockBuilder('Composer\Util\HttpDownloader')->disableOriginalConstructor()->getMock();
        $repositoryManager = new RepositoryManager($io, $config, $httpDownloader, $eventDispatcher);
        $repositoryManager->setLocalRepository(new InstalledArrayRepository());

        foreach ($repositories as $repository) {
            $repositoryManager->addRepository($repository);
        }
        $installationManager = new InstallationManagerMock();

        // emulate a writable lock file
        /** @var ?string $lockData */
        $lockData = null;
        $lockJsonMock = $this->getMockBuilder('Composer\Json\JsonFile')->disableOriginalConstructor()->getMock();
        $lockJsonMock->expects($this->any())
            ->method('read')
            ->will($this->returnCallback(static function () use (&$lockData) {
                return json_decode($lockData, true);
            }));
        $lockJsonMock->expects($this->any())
            ->method('exists')
            ->will($this->returnCallback(static function () use (&$lockData): bool {
                return $lockData !== null;
            }));
        $lockJsonMock->expects($this->any())
            ->method('write')
            ->will($this->returnCallback(static function ($value, $options = 0) use (&$lockData): void {
                $lockData = json_encode($value, JSON_PRETTY_PRINT);
            }));

        $tempLockData = null;
        $locker = new Locker($io, $lockJsonMock, $installationManager, '{}');

        $autoloadGenerator = $this->getMockBuilder('Composer\Autoload\AutoloadGenerator')->disableOriginalConstructor()->getMock();

        $installer = new Installer($io, $config, clone $rootPackage, $downloadManager, $repositoryManager, $locker, $installationManager, $eventDispatcher, $autoloadGenerator);
        $installer->setAudit(false);
        $result = $installer->run();

        $output = str_replace("\r", '', $io->getOutput());
        self::assertEquals(0, $result, $output);

        $expectedInstalled = $options['install'] ?? [];
        $expectedUpdated = $options['update'] ?? [];
        $expectedUninstalled = $options['uninstall'] ?? [];

        $installed = $installationManager->getInstalledPackages();
        self::assertEquals($this->makePackagesComparable($expectedInstalled), $this->makePackagesComparable($installed));

        $updated = $installationManager->getUpdatedPackages();
        self::assertSame($expectedUpdated, $updated);

        $uninstalled = $installationManager->getUninstalledPackages();
        self::assertSame($expectedUninstalled, $uninstalled);
    }

    /**
     * @param  PackageInterface[] $packages
     * @return mixed[]
     */
    protected function makePackagesComparable(array $packages): array
    {
        $dumper = new ArrayDumper();

        $comparable = [];
        foreach ($packages as $package) {
            $comparable[] = $dumper->dump($package);
        }

        return $comparable;
    }

    public static function provideInstaller(): array
    {
        $cases = [];

        // when A requires B and B requires A, and A is a non-published root package
        // the install of B should succeed

        $a = self::getPackage('A', '1.0.0', 'Composer\Package\RootPackage');
        $a->setRequires([
            'b' => new Link('A', 'B', $v = self::getVersionConstraint('=', '1.0.0'), Link::TYPE_REQUIRE, $v->getPrettyString()),
        ]);
        $b = self::getPackage('B', '1.0.0');
        $b->setRequires([
            'a' => new Link('B', 'A', $v = self::getVersionConstraint('=', '1.0.0'), Link::TYPE_REQUIRE, $v->getPrettyString()),
        ]);

        $cases[] = [
            $a,
            [new ArrayRepository([$b])],
            [
                'install' => [$b],
            ],
        ];

        // #480: when A requires B and B requires A, and A is a published root package
        // only B should be installed, as A is the root

        $a = self::getPackage('A', '1.0.0', 'Composer\Package\RootPackage');
        $a->setRequires([
            'b' => new Link('A', 'B', $v = self::getVersionConstraint('=', '1.0.0'), Link::TYPE_REQUIRE, $v->getPrettyString()),
        ]);
        $b = self::getPackage('B', '1.0.0');
        $b->setRequires([
            'a' => new Link('B', 'A', $v = self::getVersionConstraint('=', '1.0.0'), Link::TYPE_REQUIRE, $v->getPrettyString()),
        ]);

        $cases[] = [
            $a,
            [new ArrayRepository([$a, $b])],
            [
                'install' => [$b],
            ],
        ];

        // TODO why are there not more cases with uninstall/update?
        return $cases;
    }

    /**
     * @group slow
     * @dataProvider provideSlowIntegrationTests
     * @param mixed[] $composerConfig
     * @param ?array<mixed> $lock
     * @param ?array<mixed> $installed
     * @param mixed[]|false $expectLock
     * @param ?array<mixed> $expectInstalled
     * @param int|class-string<\Throwable> $expectResult
     */
    public function testSlowIntegration(string $file, string $message, ?string $condition, array $composerConfig, ?array $lock, ?array $installed, string $run, $expectLock, ?array $expectInstalled, ?string $expectOutput, ?string $expectOutputOptimized, string $expect, $expectResult): void
    {
        Platform::putEnv('COMPOSER_POOL_OPTIMIZER', '0');

        $this->doTestIntegration($file, $message, $condition, $composerConfig, $lock, $installed, $run, $expectLock, $expectInstalled, $expectOutput, $expect, $expectResult);
    }

    /**
     * @dataProvider provideIntegrationTests
     * @param mixed[] $composerConfig
     * @param ?array<mixed> $lock
     * @param ?array<mixed> $installed
     * @param mixed[]|false $expectLock
     * @param ?array<mixed> $expectInstalled
     * @param int|class-string<\Throwable> $expectResult
     */
    public function testIntegrationWithPoolOptimizer(string $file, string $message, ?string $condition, array $composerConfig, ?array $lock, ?array $installed, string $run, $expectLock, ?array $expectInstalled, ?string $expectOutput, ?string $expectOutputOptimized, string $expect, $expectResult): void
    {
        Platform::putEnv('COMPOSER_POOL_OPTIMIZER', '1');

        $this->doTestIntegration($file, $message, $condition, $composerConfig, $lock, $installed, $run, $expectLock, $expectInstalled, $expectOutputOptimized ?: $expectOutput, $expect, $expectResult);
    }

    /**
     * @dataProvider provideIntegrationTests
     * @param mixed[] $composerConfig
     * @param ?array<mixed> $lock
     * @param ?array<mixed> $installed
     * @param mixed[]|false $expectLock
     * @param ?array<mixed> $expectInstalled
     * @param int|class-string<\Throwable> $expectResult
     */
    public function testIntegrationWithRawPool(string $file, string $message, ?string $condition, array $composerConfig, ?array $lock, ?array $installed, string $run, $expectLock, ?array $expectInstalled, ?string $expectOutput, ?string $expectOutputOptimized, string $expect, $expectResult): void
    {
        Platform::putEnv('COMPOSER_POOL_OPTIMIZER', '0');

        $this->doTestIntegration($file, $message, $condition, $composerConfig, $lock, $installed, $run, $expectLock, $expectInstalled, $expectOutput, $expect, $expectResult);
    }

    /**
     * @param mixed[] $composerConfig
     * @param ?array<mixed> $lock
     * @param ?array<mixed> $installed
     * @param mixed[]|false $expectLock
     * @param ?array<mixed> $expectInstalled
     * @param int|class-string<\Throwable> $expectResult
     */
    private function doTestIntegration(string $file, string $message, ?string $condition, array $composerConfig, ?array $lock, ?array $installed, string $run, $expectLock, ?array $expectInstalled, ?string $expectOutput, string $expect, $expectResult): void
    {
        if ($condition) {
            eval('$res = '.$condition.';');
            if (!$res) { // @phpstan-ignore variable.undefined
                $this->markTestSkipped($condition);
            }
        }

        $io = new BufferIO('', OutputInterface::VERBOSITY_NORMAL, new OutputFormatter(false));

        // Prepare for exceptions
        if (!is_int($expectResult)) {
            $normalizedOutput = rtrim(str_replace("\n", PHP_EOL, $expect));
            self::expectException($expectResult);
            self::expectExceptionMessage($normalizedOutput);
        }

        // Create Composer mock object according to configuration
        $composer = FactoryMock::create($io, $composerConfig);
        $this->tempComposerHome = $composer->getConfig()->get('home');

        $jsonMock = $this->getMockBuilder('Composer\Json\JsonFile')->disableOriginalConstructor()->getMock();
        $jsonMock->expects($this->any())
            ->method('read')
            ->will($this->returnValue($installed));
        $jsonMock->expects($this->any())
            ->method('exists')
            ->will($this->returnValue(true));

        $repositoryManager = $composer->getRepositoryManager();
        $repositoryManager->setLocalRepository(new InstalledFilesystemRepositoryMock($jsonMock));

        // emulate a writable lock file
        $lockData = $lock ? json_encode($lock, JSON_PRETTY_PRINT) : null;
        $lockJsonMock = $this->getMockBuilder('Composer\Json\JsonFile')->disableOriginalConstructor()->getMock();
        $lockJsonMock->expects($this->any())
            ->method('read')
            ->will($this->returnCallback(static function () use (&$lockData) {
                return json_decode($lockData, true);
            }));
        $lockJsonMock->expects($this->any())
            ->method('exists')
            ->will($this->returnCallback(static function () use (&$lockData): bool {
                return $lockData !== null;
            }));
        $lockJsonMock->expects($this->any())
            ->method('write')
            ->will($this->returnCallback(static function ($value, $options = 0) use (&$lockData): void {
                $lockData = json_encode($value, JSON_PRETTY_PRINT);
            }));

        if ($expectLock) {
            $actualLock = [];
            $lockJsonMock->expects($this->atLeastOnce())
                ->method('write')
                ->will($this->returnCallback(static function ($hash, $options) use (&$actualLock): void {
                    // need to do assertion outside of mock for nice phpunit output
                    // so store value temporarily in reference for later assertion
                    $actualLock = $hash;
                }));
        } elseif ($expectLock === false) {
            $lockJsonMock->expects($this->never())
                ->method('write');
        }

        $contents = json_encode($composerConfig);
        $locker = new Locker($io, $lockJsonMock, $composer->getInstallationManager(), $contents);
        $composer->setLocker($locker);

        $eventDispatcher = $this->getMockBuilder('Composer\EventDispatcher\EventDispatcher')->disableOriginalConstructor()->getMock();
        $autoloadGenerator = $this->getMockBuilder('Composer\Autoload\AutoloadGenerator')
            ->setConstructorArgs([$eventDispatcher])
            ->getMock();
        $composer->setAutoloadGenerator($autoloadGenerator);
        $composer->setEventDispatcher($eventDispatcher);

        $installer = Installer::create($io, $composer);

        $application = new Application;
        $install = new Command('install');
        $install->addOption('ignore-platform-reqs', null, InputOption::VALUE_NONE);
        $install->addOption('ignore-platform-req', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY);
        $install->addOption('no-dev', null, InputOption::VALUE_NONE);
        $install->addOption('dry-run', null, InputOption::VALUE_NONE);
        $install->setCode(static function (InputInterface $input, OutputInterface $output) use ($installer): int {
            $ignorePlatformReqs = true === $input->getOption('ignore-platform-reqs') ?: ($input->getOption('ignore-platform-req') ?: false);

            $installer
                ->setDevMode(false === $input->getOption('no-dev'))
                ->setDryRun($input->getOption('dry-run'))
                ->setPlatformRequirementFilter(PlatformRequirementFilterFactory::fromBoolOrList($ignorePlatformReqs))
                ->setAudit(false);

            return $installer->run();
        });
        // Compatibility layer for symfony/console <7.4
        method_exists($application, 'addCommand') ? $application->addCommand($install) : $application->add($install);

        $update = new Command('update');
        $update->addOption('ignore-platform-reqs', null, InputOption::VALUE_NONE);
        $update->addOption('ignore-platform-req', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY);
        $update->addOption('no-dev', null, InputOption::VALUE_NONE);
        $update->addOption('no-install', null, InputOption::VALUE_NONE);
        $update->addOption('dry-run', null, InputOption::VALUE_NONE);
        $update->addOption('lock', null, InputOption::VALUE_NONE);
        $update->addOption('with-all-dependencies', null, InputOption::VALUE_NONE);
        $update->addOption('with-dependencies', null, InputOption::VALUE_NONE);
        $update->addOption('minimal-changes', null, InputOption::VALUE_NONE);
        $update->addOption('prefer-stable', null, InputOption::VALUE_NONE);
        $update->addOption('prefer-lowest', null, InputOption::VALUE_NONE);
        $update->addArgument('packages', InputArgument::IS_ARRAY | InputArgument::OPTIONAL);
        $update->setCode(static function (InputInterface $input, OutputInterface $output) use ($installer): int {
            $packages = $input->getArgument('packages');
            $filteredPackages = array_filter($packages, static function ($package): bool {
                return !in_array($package, ['lock', 'nothing', 'mirrors'], true);
            });
            $updateMirrors = true === $input->getOption('lock') || count($filteredPackages) !== count($packages);
            $packages = $filteredPackages;

            $updateAllowTransitiveDependencies = Request::UPDATE_ONLY_LISTED;
            if (true === $input->getOption('with-all-dependencies')) {
                $updateAllowTransitiveDependencies = Request::UPDATE_LISTED_WITH_TRANSITIVE_DEPS;
            } elseif (true === $input->getOption('with-dependencies')) {
                $updateAllowTransitiveDependencies = Request::UPDATE_LISTED_WITH_TRANSITIVE_DEPS_NO_ROOT_REQUIRE;
            }

            $ignorePlatformReqs = true === $input->getOption('ignore-platform-reqs') ?: ($input->getOption('ignore-platform-req') ?: false);

            $installer
                ->setDevMode(false === $input->getOption('no-dev'))
                ->setUpdate(true)
                ->setInstall(false === $input->getOption('no-install'))
                ->setDryRun($input->getOption('dry-run'))
                ->setUpdateMirrors($updateMirrors)
                ->setUpdateAllowList($packages)
                ->setUpdateAllowTransitiveDependencies($updateAllowTransitiveDependencies)
                ->setPreferStable($input->getOption('prefer-stable'))
                ->setPreferLowest($input->getOption('prefer-lowest'))
                ->setPlatformRequirementFilter(PlatformRequirementFilterFactory::fromBoolOrList($ignorePlatformReqs))
                ->setAudit(false)
                ->setMinimalUpdate($input->getOption('minimal-changes'));

            return $installer->run();
        });
        // Compatibility layer for symfony/console <7.4
        method_exists($application, 'addCommand') ? $application->addCommand($update) : $application->add($update);

        if (!Preg::isMatch('{^(install|update)\b}', $run)) {
            throw new \UnexpectedValueException('The run command only supports install and update');
        }

        $application->setAutoExit(false);
        $appOutput = fopen('php://memory', 'w+');
        if (false === $appOutput) {
            self::fail('Failed to open memory stream');
        }
        $input = new StringInput($run.' -vvv');
        $input->setInteractive(false);
        $result = $application->run($input, new StreamOutput($appOutput));
        fseek($appOutput, 0);

        // Shouldn't check output and results if an exception was expected by this point
        if (!is_int($expectResult)) {
            return;
        }

        $output = str_replace("\r", '', $io->getOutput());
        self::assertEquals($expectResult, $result, $output . stream_get_contents($appOutput));
        if ($expectLock && isset($actualLock)) {
            unset($actualLock['hash'], $actualLock['content-hash'], $actualLock['_readme'], $actualLock['plugin-api-version']);
            foreach (['stability-flags', 'platform', 'platform-dev'] as $key) {
                if ($expectLock[$key] === []) {
                    $expectLock[$key] = new \stdClass;
                }
            }
            self::assertEquals($expectLock, $actualLock);
        }

        if ($expectInstalled !== null) {
            $actualInstalled = [];
            $dumper = new ArrayDumper();

            foreach ($repositoryManager->getLocalRepository()->getCanonicalPackages() as $package) {
                $package = $dumper->dump($package);
                unset($package['version_normalized']);
                $actualInstalled[] = $package;
            }

            usort($actualInstalled, static function ($a, $b): int {
                return strcmp($a['name'], $b['name']);
            });

            self::assertSame($expectInstalled, $actualInstalled);
        }

        /** @var InstallationManagerMock $installationManager */
        $installationManager = $composer->getInstallationManager();
        self::assertSame(rtrim($expect), implode("\n", $installationManager->getTrace()));

        if ($expectOutput) {
            $output = Preg::replace('{^    - .*?\.ini$}m', '__inilist__', $output);
            $output = Preg::replace('{(__inilist__\r?\n)+}', "__inilist__\n", $output);

            self::assertStringMatchesFormat(rtrim($expectOutput), rtrim($output));
        }
    }

    public static function provideSlowIntegrationTests(): array
    {
        return self::loadIntegrationTests('installer-slow/');
    }

    public static function provideIntegrationTests(): array
    {
        return self::loadIntegrationTests('installer/');
    }

    /**
     * @return mixed[]
     */
    public static function loadIntegrationTests(string $path): array
    {
        $fixturesDir = (string) realpath(__DIR__.'/Fixtures/'.$path);
        $tests = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($fixturesDir), \RecursiveIteratorIterator::LEAVES_ONLY) as $file) {
            $file = (string) $file;

            if (!Preg::isMatch('/\.test$/', $file)) {
                continue;
            }

            try {
                $testData = self::readTestFile($file, $fixturesDir);
                // skip 64bit related tests on 32bit
                if (str_contains($testData['EXPECT-OUTPUT'] ?? '', 'php-64bit') && PHP_INT_SIZE === 4) {
                    continue;
                }

                $installed = [];
                $installedDev = [];
                $lock = [];
                $expectLock = [];
                $expectInstalled = null;
                $expectResult = 0;

                $message = $testData['TEST'];
                $condition = !empty($testData['CONDITION']) ? $testData['CONDITION'] : null;
                $composer = JsonFile::parseJson($testData['COMPOSER']);

                if (isset($composer['repositories'])) {
                    foreach ($composer['repositories'] as &$repo) {
                        if ($repo['type'] !== 'composer') {
                            continue;
                        }

                        // Change paths like file://foobar to file:///path/to/fixtures
                        if (Preg::isMatch('{^file://[^/]}', $repo['url'])) {
                            $repo['url'] = 'file://' . strtr($fixturesDir, '\\', '/') . '/' . substr($repo['url'], 7);
                        }

                        unset($repo);
                    }
                }

                if (!empty($testData['LOCK'])) {
                    $lock = JsonFile::parseJson($testData['LOCK']);
                    if (!isset($lock['hash'])) {
                        $lock['hash'] = hash('md5', JsonFile::encode($composer, 0));
                    }
                }
                if (!empty($testData['INSTALLED'])) {
                    $installed = JsonFile::parseJson($testData['INSTALLED']);
                }
                $run = $testData['RUN'];
                if (!empty($testData['EXPECT-LOCK'])) {
                    if ($testData['EXPECT-LOCK'] === 'false') {
                        $expectLock = false;
                    } else {
                        $expectLock = JsonFile::parseJson($testData['EXPECT-LOCK']);
                    }
                }
                if (!empty($testData['EXPECT-INSTALLED'])) {
                    $expectInstalled = JsonFile::parseJson($testData['EXPECT-INSTALLED']);
                }
                $expectOutput = $testData['EXPECT-OUTPUT'] ?? null;
                $expectOutputOptimized = $testData['EXPECT-OUTPUT-OPTIMIZED'] ?? null;
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

            $tests[basename($file)] = [str_replace($fixturesDir.'/', '', $file), $message, $condition, $composer, $lock, $installed, $run, $expectLock, $expectInstalled, $expectOutput, $expectOutputOptimized, $expect, $expectResult];
        }

        return $tests;
    }

    /**
     * @return mixed[]
     */
    protected static function readTestFile(string $file, string $fixturesDir): array
    {
        $tokens = Preg::split('#(?:^|\n*)--([A-Z-]+)--\n#', file_get_contents($file), -1, PREG_SPLIT_DELIM_CAPTURE);

        $sectionInfo = [
            'TEST' => true,
            'CONDITION' => false,
            'COMPOSER' => true,
            'LOCK' => false,
            'INSTALLED' => false,
            'RUN' => true,
            'EXPECT-LOCK' => false,
            'EXPECT-INSTALLED' => false,
            'EXPECT-OUTPUT' => false,
            'EXPECT-OUTPUT-OPTIMIZED' => false,
            'EXPECT-EXIT-CODE' => false,
            'EXPECT-EXCEPTION' => false,
            'EXPECT' => true,
        ];

        $section = null;
        $data = [];
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
