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

namespace Composer\Test\Package\Archiver;

use Composer\Util\Platform;
use ZipArchive;
use Composer\Package\Archiver\ZipArchiver;

class ZipArchiverTest extends ArchiverTest
{
    public function testZipArchive()
    {
        if (!class_exists('ZipArchive')) {
            $this->markTestSkipped('Cannot run ZipArchiverTest, missing class "ZipArchive".');
        }

        $files = array(
            'file.txt',
            'foo/bar/baz',
            'x/baz',
            'x/includeme',
        );
        if (!Platform::isWindows()) {
            $files[] = 'foo' . getcwd() . '/file.txt';
        }
        // Set up repository
        $this->setupDummyRepo($files);
        $package = $this->setupPackage();
        $target = sys_get_temp_dir().'/composer_archiver_test.zip';

        // Test archive
        $archiver = new ZipArchiver();
        $archiver->archive($package->getSourceUrl(), $target, 'zip');
        $this->assertFileExists($target);
        $zip = new ZipArchive();
        $res = $zip->open($target);
        self::assertTrue($res, 'Failed asserting that Zip file can be opened');
        foreach ($files as $file) {
            $this->assertSame('content', $zip->getFromName($file), 'Failed asserting that Zip contains ' . $file);
        }
        $zip->close();

        unlink($target);
    }

    /**
     * Create a local dummy repository to run tests against!
     * @param array $files
     */
    protected function setupDummyRepo($files)
    {
        $currentWorkDir = getcwd();
        chdir($this->testDir);
        foreach ($files as $file) {
            $this->writeFile($file, 'content', $currentWorkDir);
        }

        chdir($currentWorkDir);
    }

    protected function writeFile($path, $content, $currentWorkDir)
    {
        if (!file_exists(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }

        $result = file_put_contents($path, $content);
        if (false === $result) {
            chdir($currentWorkDir);
            throw new \RuntimeException('Could not save file.');
        }
    }
}
