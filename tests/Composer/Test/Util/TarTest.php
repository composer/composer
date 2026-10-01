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
use Composer\Util\Tar;
use Composer\Test\TestCase;

/**
 * @author Wissem Riahi <wissemr@gmail.com>
 */
class TarTest extends TestCase
{
    public function testReturnsNullifTheTarIsNotFound(): void
    {
        $result = Tar::getComposerJson(__DIR__.'/Fixtures/Tar/invalid.zip');

        self::assertNull($result);
    }

    public function testReturnsNullIfTheTarIsEmpty(): void
    {
        $result = Tar::getComposerJson(__DIR__.'/Fixtures/Tar/empty.tar.gz');
        self::assertNull($result);
    }

    public function testThrowsExceptionIfTheTarHasNoComposerJson(): void
    {
        self::expectException('RuntimeException');
        Tar::getComposerJson(__DIR__.'/Fixtures/Tar/nojson.tar.gz');
    }

    public function testThrowsExceptionIfTheComposerJsonIsInASubSubfolder(): void
    {
        self::expectException('RuntimeException');
        Tar::getComposerJson(__DIR__.'/Fixtures/Tar/subfolders.tar.gz');
    }

    public function testReturnsComposerJsonInTarRoot(): void
    {
        $result = Tar::getComposerJson(__DIR__.'/Fixtures/Tar/root.tar.gz');
        self::assertEquals("{\n    \"name\": \"foo/bar\"\n}\n", $result);
    }

    public function testReturnsComposerJsonInFirstFolder(): void
    {
        $result = Tar::getComposerJson(__DIR__.'/Fixtures/Tar/folder.tar.gz');
        self::assertEquals("{\n    \"name\": \"foo/bar\"\n}\n", $result);
    }

    public function testMultipleTopLevelDirsIsInvalid(): void
    {
        self::expectException('RuntimeException');
        Tar::getComposerJson(__DIR__.'/Fixtures/Tar/multiple.tar.gz');
    }

    public function testThrowsOnUnsafePhpVersionWithoutOptIn(): void
    {
        if (\PHP_VERSION_ID >= 80000) {
            self::markTestSkipped('Parsing phar/tar metadata is only unsafe on PHP < 8.0');
        }

        $original = Platform::getEnv('COMPOSER_ALLOW_UNSAFE_PHAR_METADATA');
        Platform::clearEnv('COMPOSER_ALLOW_UNSAFE_PHAR_METADATA');
        try {
            self::expectException('RuntimeException');
            self::expectExceptionMessage('Refusing to parse a tar/phar archive on PHP < 8.0');
            Tar::getComposerJson(__DIR__.'/Fixtures/Tar/root.tar.gz');
        } finally {
            if (false === $original) {
                Platform::clearEnv('COMPOSER_ALLOW_UNSAFE_PHAR_METADATA');
            } else {
                Platform::putEnv('COMPOSER_ALLOW_UNSAFE_PHAR_METADATA', $original);
            }
        }
    }
}
