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

use Composer\Config;
use Composer\Console\Application;
use Composer\IO\IOInterface;
use Composer\Json\JsonFile;
use Composer\Package\Locker;
use Composer\Pcre\Preg;
use Composer\Repository\InstalledFilesystemRepository;
use Composer\Semver\VersionParser;
use Composer\Package\PackageInterface;
use Composer\Semver\Constraint\Constraint;
use Composer\Test\Mock\FactoryMock;
use Composer\Test\Mock\HttpDownloaderMock;
use Composer\Test\Mock\IOMock;
use Composer\Test\Mock\ProcessExecutorMock;
use Composer\Util\Filesystem;
use Composer\Util\Platform;
use Composer\Util\Silencer;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\Process\ExecutableFinder;
use Composer\Package\Loader\ArrayLoader;
use Composer\Package\BasePackage;
use Composer\Package\RootPackage;
use Composer\Package\AliasPackage;
use Composer\Package\RootAliasPackage;
use Composer\Package\CompletePackage;
use Composer\Package\CompleteAliasPackage;
use Composer\Package\Package;
use Symfony\Component\Process\Process;

abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    /**
     * @var ?VersionParser
     */
    private static $parser;
    /**
     * @var array<string, bool>
     */
    private static $executableCache = [];

    /**
     * @var list<HttpDownloaderMock>
     */
    private $httpDownloaderMocks = [];
    /**
     * @var list<ProcessExecutorMock>
     */
    private $processExecutorMocks = [];
    /**
     * @var list<IOMock>
     */
    private $ioMocks = [];
    /**
     * @var list<string>
     */
    private $tempComposerDirs = [];
    /** @var string|null */
    private $prevCwd = null;

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach ($this->httpDownloaderMocks as $mock) {
            $mock->assertComplete();
        }
        foreach ($this->processExecutorMocks as $mock) {
            $mock->assertComplete();
        }
        foreach ($this->ioMocks as $mock) {
            $mock->assertComplete();
        }

        if (null !== $this->prevCwd) {
            chdir($this->prevCwd);
            $this->prevCwd = null;
            Platform::clearEnv('COMPOSER_HOME');
            Platform::clearEnv('COMPOSER_DISABLE_XDEBUG_WARN');
        }
        $fs = new Filesystem();
        foreach ($this->tempComposerDirs as $dir) {
            $fs->removeDirectory($dir);
        }
    }

    public static function getUniqueTmpDirectory(): string
    {
        $attempts = 5;
        $root = sys_get_temp_dir();

        do {
            $unique = $root . DIRECTORY_SEPARATOR . uniqid('composer-test-' . random_int(1000, 9000));

            if (!file_exists($unique) && Silencer::call('mkdir', $unique, 0777)) {
                return realpath($unique);
            }
        } while (--$attempts);

        throw new \RuntimeException('Failed to create a unique temporary directory.');
    }

    /**
     * Creates a composer.json / auth.json inside a temp dir and chdir() into it
     *
     * The directory will be cleaned up on tearDown automatically.
     *
     * @see createInstalledJson
     * @see createComposerLock
     * @see getApplicationTester
     * @param mixed[] $composerJson
     * @param mixed[] $authJson
     * @param mixed[] $composerLock
     * @return string the newly created temp dir
     */
    public function initTempComposer(array $composerJson = [], array $authJson = [], array $composerLock = []): string
    {
        $dir = self::getUniqueTmpDirectory();

        $this->tempComposerDirs[] = $dir;

        $this->prevCwd = Platform::getCwd();

        Platform::putEnv('COMPOSER_HOME', $dir.'/composer-home');
        Platform::putEnv('COMPOSER_DISABLE_XDEBUG_WARN', '1');

        if ($composerJson === []) {
            $composerJson = new \stdClass;
        }
        if ($authJson === []) {
            $authJson = new \stdClass;
        }

        if (is_array($composerJson) && isset($composerJson['repositories']) && !isset($composerJson['repositories']['packagist.org'])) {
            $composerJson['repositories']['packagist.org'] = false;
        }

        chdir($dir);
        file_put_contents($dir.'/composer.json', JsonFile::encode($composerJson, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        file_put_contents($dir.'/auth.json', JsonFile::encode($authJson, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        if ($composerLock !== []) {
            file_put_contents($dir.'/composer.lock', JsonFile::encode($composerLock, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }

        return $dir;
    }

    /**
     * Creates a vendor/composer/installed.json in CWD with the given packages
     *
     * @param PackageInterface[] $packages
     * @param PackageInterface[] $devPackages
     */
    protected function createInstalledJson(array $packages = [], array $devPackages = [], bool $devMode = true): void
    {
        mkdir('vendor/composer', 0777, true);
        $repo = new InstalledFilesystemRepository(new JsonFile('vendor/composer/installed.json'));
        $repo->setDevPackageNames(array_map(static function (PackageInterface $pkg) {
            return $pkg->getPrettyName();
        }, $devPackages));
        foreach ($packages as $pkg) {
            $repo->addPackage($pkg);
            mkdir('vendor/'.$pkg->getName(), 0777, true);
        }
        foreach ($devPackages as $pkg) {
            $repo->addPackage($pkg);
            mkdir('vendor/'.$pkg->getName(), 0777, true);
        }

        $factory = new FactoryMock();
        $repo->write($devMode, $factory->createInstallationManager());
    }

    /**
     * Creates a composer.lock in CWD with the given packages
     *
     * @param PackageInterface[] $packages
     * @param PackageInterface[] $devPackages
     */
    protected function createComposerLock(array $packages = [], array $devPackages = []): void
    {
        $factory = new FactoryMock();

        $locker = new Locker($this->getIOMock(), new JsonFile('./composer.lock'), $factory->createInstallationManager(), (string) file_get_contents('./composer.json'));
        $locker->setLockData($packages, $devPackages, [], [], [], 'dev', [], false, false, []);
    }

    public function getApplicationTester(): ApplicationTester
    {
        $application = new Application();
        $application->setAutoExit(false);
        $application->setCatchExceptions(false);
        if (method_exists($application, 'setCatchErrors')) {
            $application->setCatchErrors(false);
        }

        return new ApplicationTester($application);
    }

    protected static function getVersionParser(): VersionParser
    {
        if (!self::$parser) {
            self::$parser = new VersionParser();
        }

        return self::$parser;
    }

    /**
     * @param Constraint::STR_OP_* $operator
     */
    protected static function getVersionConstraint($operator, string $version): Constraint
    {
        $constraint = new Constraint(
            $operator,
            self::getVersionParser()->normalize($version)
        );

        $constraint->setPrettyString($operator.' '.$version);

        return $constraint;
    }

    /**
     * @template PackageClass of CompletePackage|CompleteAliasPackage
     *
     * @param  string $class  FQCN to be instantiated
     *
     * @return CompletePackage|CompleteAliasPackage|RootPackage|RootAliasPackage
     *
     * @phpstan-param class-string<PackageClass> $class
     * @phpstan-return PackageClass
     */
    protected static function getPackage(string $name = 'dummy/pkg', string $version = '1.0.0', string $class = 'Composer\Package\CompletePackage'): BasePackage
    {
        $normVersion = self::getVersionParser()->normalize($version);

        return new $class($name, $normVersion, $version);
    }

    protected static function getRootPackage(string $name = '__root__', string $version = '1.0.0'): RootPackage
    {
        $normVersion = self::getVersionParser()->normalize($version);

        return new RootPackage($name, $normVersion, $version);
    }

    /**
     * @return ($package is RootPackage ? RootAliasPackage : ($package is CompletePackage ? CompleteAliasPackage : AliasPackage))
     */
    protected static function getAliasPackage(Package $package, string $version): AliasPackage
    {
        $normVersion = self::getVersionParser()->normalize($version);

        if ($package instanceof RootPackage) {
            return new RootAliasPackage($package, $normVersion, $version);
        }
        if ($package instanceof CompletePackage) {
            return new CompleteAliasPackage($package, $normVersion, $version);
        }

        return new AliasPackage($package, $normVersion, $version);
    }

    /**
     * @param array<string, array<string, string>> $config
     */
    protected static function configureLinks(PackageInterface $package, array $config): void
    {
        $arrayLoader = new ArrayLoader();

        foreach (BasePackage::$supportedLinkTypes as $type => $opts) {
            if (isset($config[$type])) {
                $method = 'set'.ucfirst($opts['method']);
                $package->{$method}(
                    $arrayLoader->parseLinks(
                        $package->getName(),
                        $package->getPrettyVersion(),
                        $opts['method'],
                        $config[$type]
                    )
                );
            }
        }
    }

    /**
     * @param array<mixed> $configOptions
     */
    protected function getConfig(array $configOptions = [], bool $useEnvironment = false): Config
    {
        $config = new Config($useEnvironment);
        $config->merge(['config' => $configOptions], 'test');

        return $config;
    }

    protected static function ensureDirectoryExistsAndClear(string $directory): void
    {
        $fs = new Filesystem();

        if (is_dir($directory)) {
            $fs->removeDirectory($directory);
        }

        mkdir($directory, 0777, true);
    }

    /**
     * Check whether or not the given name is an available executable.
     *
     * @param string $executableName The name of the binary to test.
     *
     * @throws \PHPUnit\Framework\SkippedTestError
     */
    protected function skipIfNotExecutable(string $executableName): void
    {
        if (!isset(self::$executableCache[$executableName])) {
            $finder = new ExecutableFinder();
            self::$executableCache[$executableName] = (bool) $finder->find($executableName);
        }

        if (false === self::$executableCache[$executableName]) {
            $this->markTestSkipped($executableName . ' is not found or not executable.');
        }
    }

    /**
     * Transforms an escaped non-Windows command to match Windows escaping.
     *
     * @return string The transformed command
     */
    protected static function getCmd(string $cmd): string
    {
        if (Platform::isWindows()) {
            $cmd = Preg::replaceCallback("/('[^']*')/", static function ($m) {
                assert(is_string($m[1]));
                // Double-quotes are used only when needed
                $char = (strpbrk($m[1], " \t^&|<>()") !== false || $m[1] === "''") ? '"' : '';

                return str_replace("'", $char, $m[1]);
            }, $cmd);
        }

        return $cmd;
    }

    protected function getHttpDownloaderMock(?IOInterface $io = null, ?Config $config = null): HttpDownloaderMock
    {
        $this->httpDownloaderMocks[] = $mock = new HttpDownloaderMock($io, $config);

        return $mock;
    }

    protected function getProcessExecutorMock(): ProcessExecutorMock
    {
        $this->processExecutorMocks[] = $mock = new ProcessExecutorMock($this->getMockBuilder(Process::class));

        return $mock;
    }

    /**
     * @param IOInterface::* $verbosity
     */
    protected function getIOMock(int $verbosity = IOInterface::DEBUG): IOMock
    {
        $this->ioMocks[] = $mock = new IOMock($verbosity);

        return $mock;
    }

    protected function createTempFile(?string $dir = null): string
    {
        $dir = $dir ?? sys_get_temp_dir();
        $name = tempnam($dir, 'c');
        if ($name === false) {
            throw new \UnexpectedValueException('tempnam failed to create a temporary file in '.$dir);
        }

        return $name;
    }
}
