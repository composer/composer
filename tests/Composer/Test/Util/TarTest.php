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

use Composer\Util\Tar;
use Composer\Test\TestCase;

/**
 * @author Wissem Riahi <wissemr@gmail.com>
 */
class TarTest extends TestCase
{

    public function testReturnsNullifTheTarIsNotFound()
    {
        if (!extension_loaded('zip')) {
            $this->markTestSkipped('The PHP zip extension is not loaded.');
            return;
        }

        $result = Tar::getComposerJson(__DIR__.'/Fixtures/Tar/invalid.zip');

        $this->assertNull($result);
    }

    public function testReturnsNullIfTheTarIsEmpty()
    {
        if (!extension_loaded('zip')) {
            $this->markTestSkipped('The PHP zip extension is not loaded.');
            return;
        }

        $result = Tar::getComposerJson(__DIR__.'/Fixtures/Tar/empty.tar.gz');

        $this->assertNull($result);
    }

    /**
     * @expectedException \RuntimeException
     */
    public function testThrowsExceptionIfTheTarHasNoComposerJson()
    {
        if (!extension_loaded('zip')) {
            $this->markTestSkipped('The PHP zip extension is not loaded.');
            return;
        }

        Tar::getComposerJson(__DIR__.'/Fixtures/Tar/nojson.tar.gz');
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

        Tar::getComposerJson(__DIR__.'/Fixtures/Tar/subfolders.tar.gz');
    }

    public function testReturnsComposerJsonInZipRoot()
    {
        if (!extension_loaded('zip')) {
            $this->markTestSkipped('The PHP zip extension is not loaded.');
            return;
        }

        $result = Tar::getComposerJson(__DIR__.'/Fixtures/Tar/root.tar.gz');

        $this->assertEquals("{\n    \"name\": \"foo/bar\"\n}\n", $result);
    }

    public function testReturnsComposerJsonInFirstFolder()
    {
        if (!extension_loaded('zip')) {
            $this->markTestSkipped('The PHP zip extension is not loaded.');
            return;
        }

        $result = Tar::getComposerJson(__DIR__.'/Fixtures/Tar/folder.tar.gz');
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

        Tar::getComposerJson(__DIR__.'/Fixtures/Tar/multiple.tar.gz');
    }

    public function testReturnsComposerJsonFromFirstSubfolder()
    {
        if (!extension_loaded('zip')) {
            $this->markTestSkipped('The PHP zip extension is not loaded.');
            return;
        }

        $result = Tar::getComposerJson(__DIR__.'/Fixtures/Tar/single-sub.tar.gz');

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

        Tar::getComposerJson(__DIR__.'/Fixtures/Tar/multiple_subfolders.tar.gz');
    }
}
