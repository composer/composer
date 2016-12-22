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

namespace Composer;

use Composer\Semver\VersionParser;
use Composer\Package\AliasPackage;
use Composer\Semver\Constraint\Constraint;
use Composer\Util\Filesystem;
use Composer\Util\Silencer;

abstract class TestCase extends \PHPUnit_Framework_TestCase
{
    private static $parser;

    /** All temporary objects holding
    * @var array
    */
    private $tmpobjects = array();

    public function __destruct()
    {
        $fs = new Filesystem();
        foreach ($this->tmpobjects as $object) {
            if (is_dir($object)) {
                $fs->removeDirectory($object);
            }
        }
    }

    public function __call($name, $arguments)
    {
        if ($name === 'getUniqueTmpDirectory') {
            $this->tmpobjects[] = $result = call_user_func_array(__CLASS__ . "::$name", $arguments);
            return $result;
        } else {
            return call_user_func_array(__CLASS__ . "::$name", $arguments);
        }
    }

    public static function __callStatic($name, $arguments)
    {
        return call_user_func_array(__CLASS__ . "::$name", $arguments);
    }

    private static function getUniqueTmpDirectory()
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

        return new AliasPackage($package, $normVersion, $version);
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
     * Check whether or not the given process is available.
     *
     * @param string $process The name of the binary to test.
     *
     * @return bool True if the process is available, false otherwise.
     */
    protected function isProcessAvailable($process)
    {
        $finder = new ExecutableFinder();

        return (bool) $finder->find($process);
    }
}
