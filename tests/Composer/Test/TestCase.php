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

use Composer\Semver\VersionParser;
use Composer\Package\RootPackageInterface;
use Composer\Package\PackageInterface;
use Composer\Semver\Constraint\Constraint;
use Composer\Util\Filesystem;
use Composer\Util\Silencer;
use Symfony\Component\Process\ExecutableFinder;
use Composer\Package\Loader\ArrayLoader;
use Composer\Package\BasePackage;

abstract class TestCase extends PolyfillTestCase
{
    private static $parser;
    private static $executableCache = array();

    public static function getUniqueTmpDirectory()
    {
        $attempts = 5;
        $root = sys_get_temp_dir();

        do {
            $unique = $root . DIRECTORY_SEPARATOR . uniqid('composer-test-' . rand(1000, 9000));

            if (!file_exists($unique) && Silencer::call('mkdir', $unique, 0777)) {
                return realpath($unique);
            }
        } while (--$attempts);

        throw new \RuntimeException('Failed to create a unique temporary directory.');
    }

    protected static function getVersionParser()
    {
        if (!self::$parser) {
            self::$parser = new VersionParser();
        }

        return self::$parser;
    }

    protected function getVersionConstraint($operator, $version)
    {
        $constraint = new Constraint(
            $operator,
            self::getVersionParser()->normalize($version)
        );

        $constraint->setPrettyString($operator.' '.$version);

        return $constraint;
    }

    protected function getPackage($name, $version, $class = 'Composer\Package\Package')
    {
        $normVersion = self::getVersionParser()->normalize($version);

        return new $class($name, $normVersion, $version);
    }

    protected function getAliasPackage($package, $version)
    {
        $normVersion = self::getVersionParser()->normalize($version);

        $class = 'Composer\Package\AliasPackage';
        if ($package instanceof RootPackageInterface) {
            $class = 'Composer\Package\RootAliasPackage';
        }

        return new $class($package, $normVersion, $version);
    }

    protected function configureLinks(PackageInterface $package, array $config)
    {
        $arrayLoader = new ArrayLoader();

        foreach (BasePackage::$supportedLinkTypes as $type => $opts) {
            if (isset($config[$type])) {
                $method = 'set'.ucfirst($opts['method']);
                $package->{$method}(
                    $arrayLoader->parseLinks(
                        $package->getName(),
                        $package->getPrettyVersion(),
                        $opts['description'],
                        $config[$type]
                    )
                );
            }
        }
    }

    protected static function ensureDirectoryExistsAndClear($directory)
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
    protected function skipIfNotExecutable($executableName)
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
     * @param string      $exception
     * @param string|null $message
     * @param int|null    $code
     */
    public function setExpectedException($exception, $message = null, $code = null)
    {
        if (!class_exists('PHPUnit\Framework\Error\Notice')) {
            $exception = str_replace('PHPUnit\\Framework\\Error\\', 'PHPUnit_Framework_Error_', $exception);
        }
        if (method_exists($this, 'expectException')) {
            $this->expectException($exception);
            if (null !== $message) {
                $this->expectExceptionMessage($message);
            }
        } else {
            parent::setExpectedException($exception, $message, $code);
        }
    }
}
