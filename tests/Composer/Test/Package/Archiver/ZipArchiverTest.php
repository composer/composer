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
    /**
     * @param string $include
     *
     * @dataProvider provideGitignoreExcludeNegationTestCases
     */
    public function testGitignoreExcludeNegation($include): void
    {
        $this->testZipArchive(array(
            'docs/README.md' => '# The doc',
            '.gitignore' => "/*\n.*\n!.git*\n$include",
        ));
    }

    public function provideGitignoreExcludeNegationTestCases(): array
    {
        return array(
            array('!/docs'),
            array('!/docs/'),
        );
    }

    /**
     * @param array<string, string> $files
     */
    public function testZipArchive(array $files = array()): void
    {
        if (!class_exists('ZipArchive')) {
            $this->markTestSkipped('Cannot run ZipArchiverTest, missing class "ZipArchive".');
        }

        if (empty($files)) {
            $files = array(
                'file.txt' => null,
                'foo/bar/baz' => null,
                'x/baz' => null,
                'x/includeme' => null,
            );

            if (!Platform::isWindows()) {
                $files['foo' . getcwd() . '/file.txt'] = null;
            }
        }
        // Set up repository
        $this->setupDummyRepo($files);
        $package = $this->setupPackage();
        $target = sys_get_temp_dir().'/composer_archiver_test.zip';

        // Test archive
        $archiver = new ZipArchiver();
        $archiver->archive($package->getSourceUrl(), $target, 'zip');
        static::assertFileExists($target);
        $zip = new ZipArchive();
        $res = $zip->open($target);
        static::assertTrue($res, 'Failed asserting that Zip file can be opened');
        foreach ($files as $path => $content) {
            static::assertSame($content, $zip->getFromName($path), 'Failed asserting that Zip contains ' . $path);
        }
        $zip->close();

        unlink($target);
    }

    /**
     * Create a local dummy repository to run tests against!
     *
     * @param array<string, string|null> $files
     *
     * @return void
     */
    protected function setupDummyRepo(array &$files): void
    {
        $currentWorkDir = getcwd();
        chdir($this->testDir);
        foreach ($files as $path => $content) {
            if ($files[$path] === null) {
                $files[$path] = 'content';
            }
            $this->writeFile($path, $files[$path], $currentWorkDir);
        }

        chdir($currentWorkDir);
    }

    /**
     * @param string $path
     * @param string $content
     * @param string $currentWorkDir
     *
     * @return void
     */
    protected function writeFile($path, $content, $currentWorkDir): void
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
