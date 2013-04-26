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
use Composer\Util\Filesystem;

use Symfony\Component\Process\Process;
use Symfony\Component\Process\ExecutableFinder;

class ArchivableFilesFinderTest extends \PHPUnit_Framework_TestCase
{
    protected $sources;
    protected $finder;
    protected $fs;

    protected function setUp()
    {
        $fs = new Filesystem;
        $this->fs = $fs;

        $this->sources = $fs->normalizePath(
            realpath(sys_get_temp_dir()).'/composer_archiver_test'.uniqid(mt_rand(), true)
        );

        $fileTree = array(
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
            'toplevelA.foo',
            'toplevelB.foo',
            'prefixA.foo',
            'prefixB.foo',
            'prefixC.foo',
            'prefixD.foo',
            'prefixE.foo',
            'prefixF.foo',
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
            '!*/*/*/prefixC.foo'
        );

        $this->finder = new ArchivableFilesFinder($this->sources, $excludes);

        $this->assertArchivableFiles(array(
            '/A/prefixA.foo',
            '/A/prefixD.foo',
            '/A/prefixE.foo',
            '/A/prefixF.foo',
            '/B/sub/prefixA.foo',
            '/B/sub/prefixC.foo',
            '/B/sub/prefixD.foo',
            '/B/sub/prefixE.foo',
            '/B/sub/prefixF.foo',
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
        // Ensure that git is available for testing.
        if (!$this->isProcessAvailable('git')) {
            return $this->markTestSkipped('git is not available.');
        }

        file_put_contents($this->sources.'/.gitignore', implode("\n", array(
            '# gitignore rules with comments and blank lines',
            '',
            'prefixE.foo',
            '# and more',
            '# comments',
            '',
            '!/prefixE.foo',
            '/prefixD.foo',
            'prefixF.*',
            '!/*/*/prefixF.foo',
            '',
            'refixD.foo',
        )));

        // git does not currently support negative git attributes
        file_put_contents($this->sources.'/.gitattributes', implode("\n", array(
            '',
            '# gitattributes rules with comments and blank lines',
            'prefixB.foo export-ignore',
            //'!/prefixB.foo export-ignore',
            '/prefixA.foo export-ignore',
            'prefixC.* export-ignore',
            //'!/*/*/prefixC.foo export-ignore'
        )));

        $this->finder = new ArchivableFilesFinder($this->sources, array());

        $this->assertArchivableFiles($this->getArchivedFiles('git init && '.
            'git add .git* && '.
            'git commit -m "ignore rules" && '.
            'git add . && '.
            'git commit -m "init" && '.
            'git archive --format=zip --prefix=archive/ -o archive.zip HEAD'
        ));
    }

    public function testHgExcludes()
    {
        // Ensure that Mercurial is available for testing.
        if (!$this->isProcessAvailable('hg')) {
            return $this->markTestSkipped('Mercurial is not available.');
        }

        file_put_contents($this->sources.'/.hgignore', implode("\n", array(
            '# hgignore rules with comments, blank lines and syntax changes',
            '',
            'pre*A.foo',
            'prefixE.foo',
            '# and more',
            '# comments',
            '',
            '^prefixD.foo',
            'syntax: glob',
            'prefixF.*',
            'B/*',
        )));

        $this->finder = new ArchivableFilesFinder($this->sources, array());

        $expectedFiles = $this->getArchivedFiles('hg init && '.
            'hg add && '.
            'hg commit -m "init" && '.
            'hg archive archive.zip'
        );

        array_shift($expectedFiles); // remove .hg_archival.txt

        $this->assertArchivableFiles($expectedFiles);
    }

    protected function getArchivableFiles()
    {
        $files = array();
        foreach ($this->finder as $file) {
            if (!$file->isDir()) {
                $files[] = preg_replace('#^'.preg_quote($this->sources, '#').'#', '', $this->fs->normalizePath($file->getRealPath()));
            }
        }

        sort($files);

        return $files;
    }

    protected function getArchivedFiles($command)
    {
        $process = new Process($command, $this->sources);
        $process->run();

        $archive = new \PharData($this->sources.'/archive.zip');
        $iterator = new \RecursiveIteratorIterator($archive);

        $files = array();
        foreach ($iterator as $file) {
            $files[] = preg_replace('#^phar://'.preg_quote($this->sources, '#').'/archive\.zip/archive#', '', $this->fs->normalizePath($file));
        }

        unset($archive, $iterator, $file);
        unlink($this->sources.'/archive.zip');

        return $files;
    }

    protected function assertArchivableFiles($expectedFiles)
    {
        $actualFiles = $this->getArchivableFiles();

        $this->assertEquals($expectedFiles, $actualFiles);
    }

    /**
     * Check whether or not the given process is available.
     *
     * @param string $process The name of the binary to test.
     *
     * @return boolean True if the process is available, false otherwise.
     */
    protected function isProcessAvailable($process)
    {
        $finder = new ExecutableFinder();

        return (bool) $finder->find($process);
    }
}
