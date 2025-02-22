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

namespace Composer\Test\Package\Archiver;

use Composer\Util\Platform;
use ZipArchive;
use Composer\Package\Archiver\ZipArchiver;

class ZipArchiverTest extends ArchiverTestCase
{
    /** @var list<string> */
    private $filesToCleanup = [];

    public function testSimpleFiles(): void
    {
        $files = [
            'file.txt' => null,
            'foo/bar/baz' => null,
            'x/baz' => null,
            'x/includeme' => null,
        ];

        if (!Platform::isWindows()) {
            $files['zfoo' . Platform::getCwd() . '/file.txt'] = null;
        }

        $this->assertZipArchive($files);
    }

    /**
     * @dataProvider provideGitignoreExcludeNegationTestCases
     */
    public function testGitignoreExcludeNegation(string $include): void
    {
        $this->assertZipArchive([
            '.gitignore' => "/*\n.*\n!.git*\n$include",
            'docs/README.md' => '# The doc',
        ]);
    }

    public static function provideGitignoreExcludeNegationTestCases(): array
    {
        return [
            ['!/docs'],
            ['!/docs/'],
        ];
    }

    /**
     * @param array<string, string|null> $files
     */
    protected function assertZipArchive(array $files): void
    {
        if (!class_exists('ZipArchive')) {
            $this->markTestSkipped('Cannot run ZipArchiverTest, missing class "ZipArchive".');
        }

        // Set up repository
        $this->setupDummyRepo($files);
        $package = $this->setupPackage();
        $target = $this->filesToCleanup[] = sys_get_temp_dir().'/composer_archiver_test.zip';

        // Test archive
        $archiver = new ZipArchiver();
        $archiver->archive($package->getSourceUrl(), $target, 'zip');
        static::assertFileExists($target);
        $zip = new ZipArchive();
        $res = $zip->open($target);
        static::assertTrue($res, 'Failed asserting that Zip file can be opened');

        $zipContents = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $path = $zip->getNameIndex($i);
            static::assertIsString($path);
            $zipContents[$path] = $zip->getFromName($path);
        }
        $zip->close();

        static::assertSame(
            $files,
            $zipContents,
            'Failed asserting that Zip created with the ZipArchiver contains all files from the repository.'
        );
    }

    /**
     * Create a local dummy repository to run tests against!
     *
     * @param array<string, string|null> $files
     */
    protected function setupDummyRepo(array &$files): void
    {
        $currentWorkDir = Platform::getCwd();
        chdir($this->testDir);
        foreach ($files as $path => $content) {
            if ($files[$path] === null) {
                $files[$path] = 'content';
            }
            $this->writeFile($path, $files[$path], $currentWorkDir);
        }

        chdir($currentWorkDir);
    }

    protected function writeFile(string $path, string $content, string $currentWorkDir): void
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

    protected function tearDown(): void
    {
        foreach ($this->filesToCleanup as $file) {
            unlink($file);
        }
        parent::tearDown();
    }
}
