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

use Composer\Util\Zip;
use Composer\Test\TestCase;

/**
 * @author Andreas Schempp <andreas.schempp@terminal42.ch>
 */
class ZipTest extends TestCase
{
    public function testThrowsExceptionIfZipExtensionIsNotLoaded(): void
    {
        if (extension_loaded('zip')) {
            $this->markTestSkipped('The PHP zip extension is loaded.');
        }

        self::expectException('RuntimeException');
        self::expectExceptionMessage('The Zip Util requires PHP\'s zip extension');

        Zip::getComposerJson('');
    }

    public function testReturnsNullifTheZipIsNotFound(): void
    {
        if (!extension_loaded('zip')) {
            $this->markTestSkipped('The PHP zip extension is not loaded.');
        }

        $result = Zip::getComposerJson(__DIR__.'/Fixtures/Zip/invalid.zip');

        self::assertNull($result);
    }

    public function testReturnsNullIfTheZipIsEmpty(): void
    {
        if (!extension_loaded('zip')) {
            $this->markTestSkipped('The PHP zip extension is not loaded.');
        }

        $result = Zip::getComposerJson(__DIR__.'/Fixtures/Zip/empty.zip');

        self::assertNull($result);
    }

    public function testThrowsExceptionIfTheZipHasNoComposerJson(): void
    {
        if (!extension_loaded('zip')) {
            $this->markTestSkipped('The PHP zip extension is not loaded.');
        }

        self::expectException('RuntimeException');
        self::expectExceptionMessage('No composer.json found either at the top level or within the topmost directory');

        Zip::getComposerJson(__DIR__.'/Fixtures/Zip/nojson.zip');
    }

    public function testThrowsExceptionIfTheComposerJsonIsInASubSubfolder(): void
    {
        if (!extension_loaded('zip')) {
            $this->markTestSkipped('The PHP zip extension is not loaded.');
        }

        self::expectException('RuntimeException');
        self::expectExceptionMessage('No composer.json found either at the top level or within the topmost directory');

        Zip::getComposerJson(__DIR__.'/Fixtures/Zip/subfolders.zip');
    }

    public function testReturnsComposerJsonInZipRoot(): void
    {
        if (!extension_loaded('zip')) {
            $this->markTestSkipped('The PHP zip extension is not loaded.');
        }

        $result = Zip::getComposerJson(__DIR__.'/Fixtures/Zip/root.zip');

        self::assertEquals("{\n    \"name\": \"foo/bar\"\n}\n", $result);
    }

    public function testReturnsComposerJsonInFirstFolder(): void
    {
        if (!extension_loaded('zip')) {
            $this->markTestSkipped('The PHP zip extension is not loaded.');
        }

        $result = Zip::getComposerJson(__DIR__.'/Fixtures/Zip/folder.zip');
        self::assertEquals("{\n    \"name\": \"foo/bar\"\n}\n", $result);
    }

    public function testMultipleTopLevelDirsIsInvalid(): void
    {
        if (!extension_loaded('zip')) {
            $this->markTestSkipped('The PHP zip extension is not loaded.');
        }

        self::expectException('RuntimeException');
        self::expectExceptionMessage('Archive has more than one top level directories, and no composer.json was found on the top level, so it\'s an invalid archive. Top level paths found were: folder1/,folder2/');

        Zip::getComposerJson(__DIR__.'/Fixtures/Zip/multiple.zip');
    }

    public function testReturnsComposerJsonFromFirstSubfolder(): void
    {
        if (!extension_loaded('zip')) {
            $this->markTestSkipped('The PHP zip extension is not loaded.');
        }

        $result = Zip::getComposerJson(__DIR__.'/Fixtures/Zip/single-sub.zip');

        self::assertEquals("{\n    \"name\": \"foo/bar\"\n}\n", $result);
    }
}
