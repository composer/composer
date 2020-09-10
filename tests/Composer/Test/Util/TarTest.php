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
}
