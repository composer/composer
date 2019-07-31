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
use PHPUnit\Framework\TestCase;

/**
 * @author Andreas Schempp <andreas.schempp@terminal42.ch>
 */
class ZipTest extends TestCase
{
    public function testThrowsExceptionIfZipExcentionIsNotLoaded()
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

    public function testReturnsNullIfTheZipHasNoComposerJson()
    {
        if (!extension_loaded('zip')) {
            $this->markTestSkipped('The PHP zip extension is not loaded.');
            return;
        }

        $result = Zip::getComposerJson(__DIR__.'/Fixtures/Zip/nojson.zip');

        $this->assertNull($result);
    }

    public function testReturnsNullIfTheComposerJsonIsInASubSubfolder()
    {
        if (!extension_loaded('zip')) {
            $this->markTestSkipped('The PHP zip extension is not loaded.');
            return;
        }

        $result = Zip::getComposerJson(__DIR__.'/Fixtures/Zip/subfolder.zip');

        $this->assertNull($result);
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

    public function testReturnsRootComposerJsonAndSkipsSubfolders()
    {
        if (!extension_loaded('zip')) {
            $this->markTestSkipped('The PHP zip extension is not loaded.');
            return;
        }

        $result = Zip::getComposerJson(__DIR__.'/Fixtures/Zip/multiple.zip');

        $this->assertEquals("{\n    \"name\": \"foo/bar\"\n}\n", $result);
    }
}
