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

use Composer\Config;
use Composer\IO\IOInterface;
use Composer\Pcre\Preg;
use Composer\Semver\VersionParser;
use Composer\Package\PackageInterface;
use Composer\Semver\Constraint\Constraint;
use Composer\Test\Mock\HttpDownloaderMock;
use Composer\Test\Mock\ProcessExecutorMock;
use Composer\Util\Filesystem;
use Composer\Util\Platform;
use Composer\Util\Silencer;
use Symfony\Component\Process\ExecutableFinder;
use Composer\Package\Loader\ArrayLoader;
use Composer\Package\BasePackage;
use Composer\Package\RootPackage;
use Composer\Package\AliasPackage;
use Composer\Package\RootAliasPackage;
use Composer\Package\CompletePackage;
use Composer\Package\CompleteAliasPackage;
use Composer\Package\Package;

abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    /**
     * @var ?VersionParser
     */
    private static $parser;
    /**
     * @var array<string, bool>
     */
    private static $executableCache = array();

    /**
     * @var list<HttpDownloaderMock>
     */
    private $httpDownloaderMocks = [];
    /**
     * @var list<ProcessExecutorMock>
     */
    private $processExecutorMocks = [];

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach ($this->httpDownloaderMocks as $mock) {
            $mock->assertComplete();
        }
        foreach ($this->processExecutorMocks as $mock) {
            $mock->assertComplete();
        }
    }

    /**
     * @return string
     */
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
     * @return VersionParser
     */
    protected static function getVersionParser(): VersionParser
    {
        if (!self::$parser) {
            self::$parser = new VersionParser();
        }

        return self::$parser;
    }

    /**
     * @param Constraint::STR_OP_* $operator
     * @param string $version
     * @return Constraint
     */
    protected function getVersionConstraint($operator, $version): Constraint
    {
        $constraint = new Constraint(
            $operator,
            self::getVersionParser()->normalize($version)
        );

        $constraint->setPrettyString($operator.' '.$version);

        return $constraint;
    }

    /**
     * @template PackageClass of PackageInterface
     *
     * @param  string $class  FQCN to be instantiated
     * @param  string $name
     * @param  string $version
     *
     * @return CompletePackage|CompleteAliasPackage|RootPackage|RootAliasPackage
     *
     * @phpstan-param class-string<PackageClass> $class
     * @phpstan-return PackageClass
     */
    protected function getPackage($name, $version, $class = 'Composer\Package\CompletePackage')
    {
        $normVersion = self::getVersionParser()->normalize($version);

        return new $class($name, $normVersion, $version);
    }

    /**
     * @param string $version
     * @return AliasPackage|RootAliasPackage|CompleteAliasPackage
     */
    protected function getAliasPackage(Package $package, $version): \Composer\Package\AliasPackage
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
     * @return void
     */
    protected function configureLinks(PackageInterface $package, array $config): void
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
     * @param  string $directory
     * @return void
     */
    protected static function ensureDirectoryExistsAndClear($directory): void
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
     * @return void
     *
     * @throws \PHPUnit\Framework\SkippedTestError
     */
    protected function skipIfNotExecutable($executableName): void
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
     * @param string $cmd
     *
     * @return string The transformed command
     */
    protected function getCmd($cmd): string
    {
        if (Platform::isWindows()) {
            $cmd = Preg::replaceCallback("/('[^']*')/", function ($m) {
                // Double-quotes are used only when needed
                $char = (strpbrk($m[1], " \t^&|<>()") !== false || $m[1] === "''") ? '"' : '';

                return str_replace("'", $char, $m[1]);
            }, $cmd);
        }

        return $cmd;
    }

    protected function getHttpDownloaderMock(IOInterface $io = null, Config $config = null): HttpDownloaderMock
    {
        $this->httpDownloaderMocks[] = $mock = new HttpDownloaderMock($io, $config);

        return $mock;
    }

    protected function getProcessExecutorMock(): ProcessExecutorMock
    {
        $this->processExecutorMocks[] = $mock = new ProcessExecutorMock();

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
