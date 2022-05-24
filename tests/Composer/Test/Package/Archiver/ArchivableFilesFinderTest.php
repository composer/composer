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

use Composer\Package\Archiver\ArchivableFilesFinder;
use Composer\Pcre\Preg;
use Composer\Test\TestCase;
use Composer\Util\Filesystem;
use Symfony\Component\Process\Process;

class ArchivableFilesFinderTest extends TestCase
{
    /**
     * @var string
     */
    protected $sources;
    /**
     * @var ArchivableFilesFinder
     */
    protected $finder;
    /**
     * @var Filesystem
     */
    protected $fs;

    protected function setUp()
    {
        $fs = new Filesystem;
        $this->fs = $fs;

        $this->sources = $fs->normalizePath(
            $this->getUniqueTmpDirectory()
        );

        $fileTree = array(
            '.foo',
            'A/prefixA.foo',
            'A/prefixB.foo',
            'A/prefixC.foo',
            'A/prefixD.foo',
            'A/prefixE.foo',
            'A/prefixF.foo',
            'B/sub/prefixA.foo',
            'B/sub/prefixB.foo',
            'B/sub/prefixC.foo',
            'B/sub/prefixD.foo',
            'B/sub/prefixE.foo',
            'B/sub/prefixF.foo',
            'C/prefixA.foo',
            'C/prefixB.foo',
            'C/prefixC.foo',
            'C/prefixD.foo',
            'C/prefixE.foo',
            'C/prefixF.foo',
            'D/prefixA',
            'D/prefixB',
            'D/prefixC',
            'D/prefixD',
            'D/prefixE',
            'D/prefixF',
            'E/subtestA.foo',
            'F/subtestA.foo',
            'G/subtestA.foo',
            'H/subtestA.foo',
            'I/J/subtestA.foo',
            'K/dirJ/subtestA.foo',
            'toplevelA.foo',
            'toplevelB.foo',
            'prefixA.foo',
            'prefixB.foo',
            'prefixC.foo',
            'prefixD.foo',
            'prefixE.foo',
            'prefixF.foo',
            'parameters.yml',
            'parameters.yml.dist',
            '!important!.txt',
            '!important_too!.txt',
            '#weirdfile',
        );

        foreach ($fileTree as $relativePath) {
            $path = $this->sources.'/'.$relativePath;
            $fs->ensureDirectoryExists(dirname($path));
            file_put_contents($path, '');
        }
    }

    protected function tearDown()
    {
        $fs = new Filesystem;
        $fs->removeDirectory($this->sources);
    }

    public function testManualExcludes()
    {
        $excludes = array(
            'prefixB.foo',
            '!/prefixB.foo',
            '/prefixA.foo',
            'prefixC.*',
            '!*/*/*/prefixC.foo',
            '.*',
        );

        $this->finder = new ArchivableFilesFinder($this->sources, $excludes);

        $this->assertArchivableFiles(array(
            '/!important!.txt',
            '/!important_too!.txt',
            '/#weirdfile',
            '/A/prefixA.foo',
            '/A/prefixD.foo',
            '/A/prefixE.foo',
            '/A/prefixF.foo',
            '/B/sub/prefixA.foo',
            '/B/sub/prefixC.foo',
            '/B/sub/prefixD.foo',
            '/B/sub/prefixE.foo',
            '/B/sub/prefixF.foo',
            '/C/prefixA.foo',
            '/C/prefixD.foo',
            '/C/prefixE.foo',
            '/C/prefixF.foo',
            '/D/prefixA',
            '/D/prefixB',
            '/D/prefixC',
            '/D/prefixD',
            '/D/prefixE',
            '/D/prefixF',
            '/E/subtestA.foo',
            '/F/subtestA.foo',
            '/G/subtestA.foo',
            '/H/subtestA.foo',
            '/I/J/subtestA.foo',
            '/K/dirJ/subtestA.foo',
            '/parameters.yml',
            '/parameters.yml.dist',
            '/prefixB.foo',
            '/prefixD.foo',
            '/prefixE.foo',
            '/prefixF.foo',
            '/toplevelA.foo',
            '/toplevelB.foo',
        ));
    }

    public function testGitExcludes()
    {
        $this->skipIfNotExecutable('git');

        file_put_contents($this->sources.'/.gitattributes', implode("\n", array(
            '',
            '# gitattributes rules with comments and blank lines',
            'prefixB.foo export-ignore',
            '/prefixA.foo export-ignore',
            'prefixC.* export-ignore',
            '',
            'prefixE.foo export-ignore',
            '# and more',
            '# comments',
            '',
            '/prefixE.foo -export-ignore',
            '/prefixD.foo export-ignore',
            'prefixF.* export-ignore',
            '/*/*/prefixF.foo -export-ignore',
            '',
            'refixD.foo export-ignore',
            '/C export-ignore',
            'D/prefixA export-ignore',
            'E export-ignore',
            'F/ export-ignore',
            'G/* export-ignore',
            'H/** export-ignore',
            'J/ export-ignore',
            'parameters.yml export-ignore',
            '\!important!.txt export-ignore',
            '\#* export-ignore',
        )));

        $this->finder = new ArchivableFilesFinder($this->sources, array());

        $this->assertArchivableFiles($this->getArchivedFiles(
            'git init && '.
            'git config user.email "you@example.com" && '.
            'git config user.name "Your Name" && '.
            'git config commit.gpgsign false && '.
            'git add .git* && '.
            'git commit -m "ignore rules" && '.
            'git add . && '.
            'git commit -m "init" && '.
            'git archive --format=zip --prefix=archive/ -o archive.zip HEAD'
        ));
    }

    public function testSkipExcludes()
    {
        $excludes = array(
            'prefixB.foo',
        );

        $this->finder = new ArchivableFilesFinder($this->sources, $excludes, true);

        $this->assertArchivableFiles(array(
            '/!important!.txt',
            '/!important_too!.txt',
            '/#weirdfile',
            '/.foo',
            '/A/prefixA.foo',
            '/A/prefixB.foo',
            '/A/prefixC.foo',
            '/A/prefixD.foo',
            '/A/prefixE.foo',
            '/A/prefixF.foo',
            '/B/sub/prefixA.foo',
            '/B/sub/prefixB.foo',
            '/B/sub/prefixC.foo',
            '/B/sub/prefixD.foo',
            '/B/sub/prefixE.foo',
            '/B/sub/prefixF.foo',
            '/C/prefixA.foo',
            '/C/prefixB.foo',
            '/C/prefixC.foo',
            '/C/prefixD.foo',
            '/C/prefixE.foo',
            '/C/prefixF.foo',
            '/D/prefixA',
            '/D/prefixB',
            '/D/prefixC',
            '/D/prefixD',
            '/D/prefixE',
            '/D/prefixF',
            '/E/subtestA.foo',
            '/F/subtestA.foo',
            '/G/subtestA.foo',
            '/H/subtestA.foo',
            '/I/J/subtestA.foo',
            '/K/dirJ/subtestA.foo',
            '/parameters.yml',
            '/parameters.yml.dist',
            '/prefixA.foo',
            '/prefixB.foo',
            '/prefixC.foo',
            '/prefixD.foo',
            '/prefixE.foo',
            '/prefixF.foo',
            '/toplevelA.foo',
            '/toplevelB.foo',
        ));
    }

    /**
     * @return string[]
     */
    protected function getArchivableFiles()
    {
        $files = array();
        foreach ($this->finder as $file) {
            if (!$file->isDir()) {
                $files[] = Preg::replace('#^'.preg_quote($this->sources, '#').'#', '', $this->fs->normalizePath($file->getRealPath()));
            }
        }

        sort($files);

        return $files;
    }

    /**
     * @param string $command
     *
     * @return string[]
     */
    protected function getArchivedFiles($command)
    {
        if (method_exists('Symfony\Component\Process\Process', 'fromShellCommandline')) {
            $process = Process::fromShellCommandline($command, $this->sources);
        } else {
            // @phpstan-ignore-next-line symfony/process 2.8 accepts a string but not 5.3 which is used only for PHPStan
            $process = new Process($command, $this->sources);
        }
        $process->run();

        $archive = new \PharData($this->sources.'/archive.zip');
        $iterator = new \RecursiveIteratorIterator($archive);

        $files = array();
        foreach ($iterator as $file) {
            $files[] = Preg::replace('#^phar://'.preg_quote($this->sources, '#').'/archive\.zip/archive#', '', $this->fs->normalizePath($file));
        }

        unset($archive, $iterator, $file);
        unlink($this->sources.'/archive.zip');

        return $files;
    }

    /**
     * @param string[] $expectedFiles
     *
     * @return void
     */
    protected function assertArchivableFiles($expectedFiles)
    {
        $actualFiles = $this->getArchivableFiles();

        $this->assertEquals($expectedFiles, $actualFiles);
    }
}
