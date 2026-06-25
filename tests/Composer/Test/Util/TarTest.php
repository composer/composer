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
use Composer\Util\Tar;
use Composer\Test\TestCase;

/**
 * @author Wissem Riahi <wissemr@gmail.com>
 */
class TarTest extends TestCase
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

    public function testReturnsNullifTheTarIsNotFound()
    {
        $result = Tar::getComposerJson(__DIR__.'/Fixtures/Tar/invalid.zip');

        $this->assertNull($result);
    }

    public function testReturnsNullIfTheTarIsEmpty()
    {
        $result = Tar::getComposerJson(__DIR__.'/Fixtures/Tar/empty.tar.gz');
        $this->assertNull($result);
    }

    public function testThrowsExceptionIfTheTarHasNoComposerJson()
    {
        $this->setExpectedException('RuntimeException');
        Tar::getComposerJson(__DIR__.'/Fixtures/Tar/nojson.tar.gz');
    }

    public function testThrowsExceptionIfTheComposerJsonIsInASubSubfolder()
    {
        $this->setExpectedException('RuntimeException');
        Tar::getComposerJson(__DIR__.'/Fixtures/Tar/subfolders.tar.gz');
    }

    public function testReturnsComposerJsonInTarRoot()
    {
        $result = Tar::getComposerJson(__DIR__.'/Fixtures/Tar/root.tar.gz');
        $this->assertEquals("{\n    \"name\": \"foo/bar\"\n}\n", $result);
    }

    public function testReturnsComposerJsonInFirstFolder()
    {
        $result = Tar::getComposerJson(__DIR__.'/Fixtures/Tar/folder.tar.gz');
        $this->assertEquals("{\n    \"name\": \"foo/bar\"\n}\n", $result);
    }

    public function testMultipleTopLevelDirsIsInvalid()
    {
        $this->setExpectedException('RuntimeException');
        Tar::getComposerJson(__DIR__.'/Fixtures/Tar/multiple.tar.gz');
    }

    public function testThrowsOnUnsafePhpVersionWithoutOptIn()
    {
        if (PHP_VERSION_ID >= 80000) {
            $this->markTestSkipped('Parsing phar/tar metadata is only unsafe on PHP < 8.0');
        }

        Platform::clearEnv('COMPOSER_ALLOW_UNSAFE_PHAR_METADATA');

        $this->setExpectedException('RuntimeException', 'Refusing to parse a tar/phar archive on PHP < 8.0');
        Tar::getComposerJson(__DIR__.'/Fixtures/Tar/root.tar.gz');
    }
}
