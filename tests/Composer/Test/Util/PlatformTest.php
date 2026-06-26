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

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalAllowUnsafePharMetadata = Platform::getEnv('COMPOSER_ALLOW_UNSAFE_PHAR_METADATA');
    }

    protected function tearDown(): void
    {
        Platform::clearEnv('COMPOSER_TEST_BOOL_ENV');
        if (false === $this->originalAllowUnsafePharMetadata) {
            Platform::clearEnv('COMPOSER_ALLOW_UNSAFE_PHAR_METADATA');
        } else {
            Platform::putEnv('COMPOSER_ALLOW_UNSAFE_PHAR_METADATA', $this->originalAllowUnsafePharMetadata);
        }
        parent::tearDown();
    }

    public function testExpandPath(): void
    {
        putenv('TESTENV=/home/test');
        self::assertEquals('/home/test/myPath', Platform::expandPath('%TESTENV%/myPath'));
        self::assertEquals('/home/test/myPath', Platform::expandPath('$TESTENV/myPath'));
        self::assertEquals((getenv('HOME') ?: getenv('USERPROFILE')) . '/test', Platform::expandPath('~/test'));
    }

    public function testIsWindows(): void
    {
        // Compare 2 common tests for Windows to the built-in Windows test
        self::assertEquals(('\\' === DIRECTORY_SEPARATOR), Platform::isWindows());
        self::assertEquals(defined('PHP_WINDOWS_VERSION_MAJOR'), Platform::isWindows());
    }

    /**
     * @return iterable<array{0: ?bool}>
     */
    public static function defaultProvider(): iterable
    {
        yield [false];
        yield [true];
        yield [null];
    }

    /**
     * @dataProvider defaultProvider
     */
    public function testGetBoolEnvReturnsDefaultWhenUnset(?bool $default): void
    {
        self::assertSame($default, Platform::getBoolEnv('COMPOSER_TEST_BOOL_ENV', $default));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function provideBoolEnvValues(): iterable
    {
        yield 'true' => ['true', true];
        yield 'false' => ['false', false];
        yield '1' => ['1', true];
        yield '0' => ['0', false];
        yield 'on' => ['on', true];
        yield 'off' => ['off', false];
    }

    /**
     * @dataProvider provideBoolEnvValues
     */
    public function testGetBoolEnvReturnsExpectedValue(string $value, bool $expected): void
    {
        Platform::putEnv('COMPOSER_TEST_BOOL_ENV', $value);
        self::assertSame($expected, Platform::getBoolEnv('COMPOSER_TEST_BOOL_ENV'));
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function provideInvalidBoolEnvValues(): iterable
    {
        yield 'integer above 1' => ['2'];
        yield 'integer below 0' => ['-1'];
        yield 'arbitrary string' => ['abc'];
        yield 'whitespace' => [' 1 '];
    }

    /**
     * @dataProvider provideInvalidBoolEnvValues
     */
    public function testGetBoolEnvThrowsForInvalidValue(string $value): void
    {
        Platform::putEnv('COMPOSER_TEST_BOOL_ENV', $value);

        self::expectException(\RuntimeException::class);
        self::expectExceptionMessage('Invalid value for COMPOSER_TEST_BOOL_ENV');

        Platform::getBoolEnv('COMPOSER_TEST_BOOL_ENV');
    }

    public function testAssertPharMetadataSafeAllowsWhenOptedIn(): void
    {
        Platform::putEnv('COMPOSER_ALLOW_UNSAFE_PHAR_METADATA', '1');

        Platform::assertPharMetadataSafe();

        // reaching this point without an exception is the assertion
        $this->addToAssertionCount(1);
    }

    public function testAssertPharMetadataSafeAllowsOnPhp8WithoutOptIn(): void
    {
        if (\PHP_VERSION_ID < 80000) {
            self::markTestSkipped('Only relevant on PHP 8.0+');
        }

        Platform::clearEnv('COMPOSER_ALLOW_UNSAFE_PHAR_METADATA');

        Platform::assertPharMetadataSafe();

        // reaching this point without an exception is the assertion
        $this->addToAssertionCount(1);
    }

    public function testAssertPharMetadataSafeThrowsOnPhp7WithoutOptIn(): void
    {
        if (\PHP_VERSION_ID >= 80000) {
            self::markTestSkipped('Only relevant on PHP < 8.0');
        }

        Platform::clearEnv('COMPOSER_ALLOW_UNSAFE_PHAR_METADATA');

        self::expectException(\RuntimeException::class);
        self::expectExceptionMessage('Refusing to parse a tar/phar archive on PHP < 8.0');

        Platform::assertPharMetadataSafe();
    }
}
