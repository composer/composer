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

namespace Composer\Test\Util;

use Composer\Util\Platform;
use Composer\Test\TestCase;

/**
 * PlatformTest
 *
 * @author Niels Keurentjes <niels.keurentjes@omines.com>
 */
class PlatformTest extends TestCase
{
    /** @var string|false */
    private $originalAllowUnsafePharMetadata;

    public function setUp()
    {
        $this->originalAllowUnsafePharMetadata = Platform::getEnv('COMPOSER_ALLOW_UNSAFE_PHAR_METADATA');
    }

    public function tearDown()
    {
        if (false === $this->originalAllowUnsafePharMetadata) {
            Platform::clearEnv('COMPOSER_ALLOW_UNSAFE_PHAR_METADATA');
        } else {
            Platform::putEnv('COMPOSER_ALLOW_UNSAFE_PHAR_METADATA', $this->originalAllowUnsafePharMetadata);
        }
    }

    public function testExpandPath()
    {
        putenv('TESTENV=/home/test');
        $this->assertEquals('/home/test/myPath', Platform::expandPath('%TESTENV%/myPath'));
        $this->assertEquals('/home/test/myPath', Platform::expandPath('$TESTENV/myPath'));
        $this->assertEquals((getenv('HOME') ?: getenv('USERPROFILE')) . '/test', Platform::expandPath('~/test'));
    }

    public function testIsWindows()
    {
        // Compare 2 common tests for Windows to the built-in Windows test
        $this->assertEquals(('\\' === DIRECTORY_SEPARATOR), Platform::isWindows());
        $this->assertEquals(defined('PHP_WINDOWS_VERSION_MAJOR'), Platform::isWindows());
    }

    /**
     * @doesNotPerformAssertions
     */
    public function testAssertPharMetadataSafeAllowsWhenOptedIn()
    {
        Platform::putEnv('COMPOSER_ALLOW_UNSAFE_PHAR_METADATA', '1');

        // reaching this point without an exception is the expectation
        Platform::assertPharMetadataSafe();
    }

    /**
     * @doesNotPerformAssertions
     */
    public function testAssertPharMetadataSafeAllowsOnPhp8WithoutOptIn()
    {
        if (PHP_VERSION_ID < 80000) {
            $this->markTestSkipped('Only relevant on PHP 8.0+');
        }

        Platform::clearEnv('COMPOSER_ALLOW_UNSAFE_PHAR_METADATA');

        // reaching this point without an exception is the expectation
        Platform::assertPharMetadataSafe();
    }

    public function testAssertPharMetadataSafeThrowsOnPhp7WithoutOptIn()
    {
        if (PHP_VERSION_ID >= 80000) {
            $this->markTestSkipped('Only relevant on PHP < 8.0');
        }

        Platform::clearEnv('COMPOSER_ALLOW_UNSAFE_PHAR_METADATA');

        $this->setExpectedException('RuntimeException', 'Refusing to parse a tar/phar archive on PHP < 8.0');

        Platform::assertPharMetadataSafe();
    }
}
