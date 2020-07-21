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

use Composer\Util\Zip;
use Composer\Test\TestCase;

/**
 * @author Andreas Schempp <andreas.schempp@terminal42.ch>
 */
class ZipTest extends TestCase
{
    public function testThrowsExceptionIfZipExtensionIsNotLoaded()
    {
        if (extension_loaded('zip')) {
            $this->markTestSkipped('The PHP zip extension is loaded.');
        }

        $this->setExpectedException('\RuntimeException', 'The Zip Util requires PHP\'s zip extension');

        Zip::getComposerJson('');
    }

    public function testReturnsNullifTheZipIsNotFound()
    {
        if (!extension_loaded('zip')) {
            $this->markTestSkipped('The PHP zip extension is not loaded.');
            return;
        }

        $result = Zip::getComposerJson(__DIR__.'/Fixtures/Zip/invalid.zip');

        $this->assertNull($result);
    }

    public function testReturnsNullIfTheZipIsEmpty()
    {
        if (!extension_loaded('zip')) {
            $this->markTestSkipped('The PHP zip extension is not loaded.');
            return;
        }

        $result = Zip::getComposerJson(__DIR__.'/Fixtures/Zip/empty.zip');

        $this->assertNull($result);
    }

    /**
     * @expectedException \RuntimeException
     */
    public function testThrowsExceptionIfTheZipHasNoComposerJson()
    {
        if (!extension_loaded('zip')) {
            $this->markTestSkipped('The PHP zip extension is not loaded.');
            return;
        }

        Zip::getComposerJson(__DIR__.'/Fixtures/Zip/nojson.zip');
    }

    /**
     * @expectedException \RuntimeException
     */
    public function testThrowsExceptionIfTheComposerJsonIsInASubSubfolder()
    {
        if (!extension_loaded('zip')) {
            $this->markTestSkipped('The PHP zip extension is not loaded.');
            return;
        }

        Zip::getComposerJson(__DIR__.'/Fixtures/Zip/subfolders.zip');
    }

    public function testReturnsComposerJsonInZipRoot()
    {
        if (!extension_loaded('zip')) {
            $this->markTestSkipped('The PHP zip extension is not loaded.');
            return;
        }

        $result = Zip::getComposerJson(__DIR__.'/Fixtures/Zip/root.zip');

        $this->assertEquals("{\n    \"name\": \"foo/bar\"\n}\n", $result);
    }

    public function testReturnsComposerJsonInFirstFolder()
    {
        if (!extension_loaded('zip')) {
            $this->markTestSkipped('The PHP zip extension is not loaded.');
            return;
        }

        $result = Zip::getComposerJson(__DIR__.'/Fixtures/Zip/folder.zip');
        $this->assertEquals("{\n    \"name\": \"foo/bar\"\n}\n", $result);
    }

    /**
     * @expectedException \RuntimeException
     */
    public function testMultipleTopLevelDirsIsInvalid()
    {
        if (!extension_loaded('zip')) {
            $this->markTestSkipped('The PHP zip extension is not loaded.');
            return;
        }

        Zip::getComposerJson(__DIR__.'/Fixtures/Zip/multiple.zip');
    }

    public function testReturnsComposerJsonFromFirstSubfolder()
    {
        if (!extension_loaded('zip')) {
            $this->markTestSkipped('The PHP zip extension is not loaded.');
            return;
        }

        $result = Zip::getComposerJson(__DIR__.'/Fixtures/Zip/single-sub.zip');

        $this->assertEquals("{\n    \"name\": \"foo/bar\"\n}\n", $result);
    }

    /**
     * @expectedException \RuntimeException
     */
    public function testThrowsExceptionIfMultipleComposerInSubFoldersWereFound()
    {
        if (!extension_loaded('zip')) {
            $this->markTestSkipped('The PHP zip extension is not loaded.');
            return;
        }

        Zip::getComposerJson(__DIR__.'/Fixtures/Zip/multiple_subfolders.zip');
    }
}
