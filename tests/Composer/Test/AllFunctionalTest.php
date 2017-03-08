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

namespace Composer\Test;

use Composer\TestCase;
use Composer\Util\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\Process;

/**
 * @group slow
 */
class AllFunctionalTest extends TestCase
{
    protected $oldcwd;
    protected $oldenv;
    protected $testDir;
    private static $pharPath;

    public function setUp()
    {
        $this->oldcwd = getcwd();

        chdir(__DIR__.'/Fixtures/functional');
    }

    public function tearDown()
    {
        chdir($this->oldcwd);

        $fs = new Filesystem;

        if ($this->testDir) {
            $fs->removeDirectory($this->testDir);
            $this->testDir = null;
        }

        if ($this->oldenv) {
            $fs->removeDirectory(getenv('COMPOSER_HOME'));
            $_SERVER['COMPOSER_HOME'] = $this->oldenv;
            putenv('COMPOSER_HOME='.$_SERVER['COMPOSER_HOME']);
            $this->oldenv = null;
        }
    }

    public static function setUpBeforeClass()
    {
        self::$pharPath = self::getUniqueTmpDirectory() . '/composer.phar';
    }

    public static function tearDownAfterClass()
    {
        $fs = new Filesystem;
        $fs->removeDirectory(dirname(self::$pharPath));
    }

    public function testBuildPhar()
    {
        if (defined('HHVM_VERSION')) {
            $this->markTestSkipped('Building the phar does not work on HHVM.');
        }

        $target = dirname(self::$pharPath);
        $fs = new Filesystem();
        chdir($target);

        $it = new \RecursiveDirectoryIterator(__DIR__.'/../../../', \RecursiveDirectoryIterator::SKIP_DOTS);
        $ri = new \RecursiveIteratorIterator($it, \RecursiveIteratorIterator::SELF_FIRST);

        foreach ($ri as $file) {
            $targetPath = $target . DIRECTORY_SEPARATOR . $ri->getSubPathName();
            if ($file->isDir()) {
                $fs->ensureDirectoryExists($targetPath);
            } else {
                copy($file->getPathname(), $targetPath);
            }
        }

        $proc = new Process('php '.escapeshellarg('./bin/compile'), $target);
        $exitcode = $proc->run();

        if ($exitcode !== 0 || trim($proc->getOutput())) {
            $this->fail($proc->getOutput());
        }

        $this->assertTrue(file_exists(self::$pharPath));
    }

    /**
     * @dataProvider getTestFiles
     * @depends testBuildPhar
     */
    public function testIntegration(\SplFileInfo $testFile)
    {
        $testData = $this->parseTestFile($testFile);

        $this->oldenv = getenv('COMPOSER_HOME');
        $_SERVER['COMPOSER_HOME'] = $this->testDir.'home';
        putenv('COMPOSER_HOME='.$_SERVER['COMPOSER_HOME']);

        $cmd = 'php '.escapeshellarg(self::$pharPath).' --no-ansi '.$testData['RUN'];
        $proc = new Process($cmd, __DIR__.'/Fixtures/functional', null, null, 300);
        $exitcode = $proc->run();

        if (isset($testData['EXPECT'])) {
            $this->assertEquals($testData['EXPECT'], $this->cleanOutput($proc->getOutput()), 'Error Output: '.$proc->getErrorOutput());
        }
        if (isset($testData['EXPECT-REGEX'])) {
            $this->assertRegExp($testData['EXPECT-REGEX'], $this->cleanOutput($proc->getOutput()), 'Error Output: '.$proc->getErrorOutput());
        }
        if (isset($testData['EXPECT-ERROR'])) {
            $this->assertEquals($testData['EXPECT-ERROR'], $this->cleanOutput($proc->getErrorOutput()));
        }
        if (isset($testData['EXPECT-ERROR-REGEX'])) {
            $this->assertRegExp($testData['EXPECT-ERROR-REGEX'], $this->cleanOutput($proc->getErrorOutput()));
        }
        if (isset($testData['EXPECT-EXIT-CODE'])) {
            $this->assertSame($testData['EXPECT-EXIT-CODE'], $exitcode);
        }
    }

    public function getTestFiles()
    {
        $tests = array();
        foreach (Finder::create()->in(__DIR__.'/Fixtures/functional')->name('*.test')->files() as $file) {
            $tests[] = array($file);
        }

        return $tests;
    }

    private function parseTestFile(\SplFileInfo $file)
    {
        $tokens = preg_split('#(?:^|\n*)--([A-Z-]+)--\n#', file_get_contents($file->getRealPath()), null, PREG_SPLIT_DELIM_CAPTURE);
        $data = array();
        $section = null;

        $testDir = self::getUniqueTmpDirectory();
        $this->testDir = $testDir;
        $varRegex = '#%([a-zA-Z_-]+)%#';
        $variableReplacer = function ($match) use (&$data, $testDir) {
            list(, $var) = $match;

            switch ($var) {
                case 'testDir':
                    $data['test_dir'] = $testDir;

                    return $testDir;

                default:
                    throw new \InvalidArgumentException(sprintf('Unknown variable "%s". Supported variables: "testDir"', $var));
            }
        };

        for ($i = 0, $c = count($tokens); $i < $c; $i++) {
            if ('' === $tokens[$i] && null === $section) {
                continue;
            }

            // Handle section headers.
            if (null === $section) {
                $section = $tokens[$i];
                continue;
            }

            $sectionData = $tokens[$i];

            // Allow sections to validate, or modify their section data.
            switch ($section) {
                case 'RUN':
                    $sectionData = preg_replace_callback($varRegex, $variableReplacer, $sectionData);
                    break;

                case 'EXPECT-EXIT-CODE':
                    $sectionData = (int) $sectionData;
                    break;

                case 'EXPECT':
                case 'EXPECT-REGEX':
                case 'EXPECT-ERROR':
                case 'EXPECT-ERROR-REGEX':
                    $sectionData = preg_replace_callback($varRegex, $variableReplacer, $sectionData);
                    break;

                default:
                    throw new \RuntimeException(sprintf(
                        'Unknown section "%s". Allowed sections: "RUN", "EXPECT", "EXPECT-ERROR", "EXPECT-EXIT-CODE", "EXPECT-REGEX", "EXPECT-ERROR-REGEX". '
                       .'Section headers must be written as "--HEADER_NAME--".',
                       $section
                   ));
            }

            $data[$section] = $sectionData;
            $section = $sectionData = null;
        }

        // validate data
        if (!isset($data['RUN'])) {
            throw new \RuntimeException('The test file must have a section named "RUN".');
        }
        if (!isset($data['EXPECT']) && !isset($data['EXPECT-ERROR']) && !isset($data['EXPECT-REGEX']) && !isset($data['EXPECT-ERROR-REGEX'])) {
            throw new \RuntimeException('The test file must have a section named "EXPECT", "EXPECT-ERROR", "EXPECT-REGEX", or "EXPECT-ERROR-REGEX".');
        }

        return $data;
    }

    private function cleanOutput($output)
    {
        $processed = '';

        for ($i = 0; $i < strlen($output); $i++) {
            if ($output[$i] === "\x08") {
                $processed = substr($processed, 0, -1);
            } elseif ($output[$i] !== "\r") {
                $processed .= $output[$i];
            }
        }

        return $processed;
    }
}
