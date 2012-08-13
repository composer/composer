<?php

namespace Composer\Test\Functional;

use Symfony\Component\Process\Process;
use Composer\Util\Filesystem;
use Symfony\Component\Finder\Finder;

class AllFunctionalTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @dataProvider getTestFiles
     */
    public function testIntegration(\SplFileInfo $testFile)
    {
        $testData = $this->parseTestFile($testFile);

        $cmd = 'php '.__DIR__.'/../../../../bin/composer '.$testData['command'];
        $proc = new Process($cmd);
        $exitcode = $proc->run();

        if (isset($testData['output'])) {
            $this->assertEquals($testData['output'], $proc->getOutput());
        }
        if (isset($testData['outputregex'])) {
            $this->assertRegExp($testData['outputregex'], $proc->getOutput());
        }
        if (isset($testData['erroroutput'])) {
            $this->assertEquals($testData['erroroutput'], $proc->getErrorOutput());
        }
        if (isset($testData['erroroutputregex'])) {
            $this->assertRegExp($testData['erroroutputregex'], $proc->getErrorOutput());
        }
        if (isset($testData['exitcode'])) {
            $this->assertSame($testData['exitcode'], $exitcode);
        }

        // Clean up.
        $fs = new Filesystem();
        if (isset($testData['test_dir']) && is_dir($testData['test_dir'])) {
            $fs->removeDirectory($testData['test_dir']);
        }
    }

    public function getTestFiles()
    {
        $tests = array();
        foreach (Finder::create()->in(__DIR__)->name('*.test')->files() as $file) {
            $tests[] = array($file);
        }

        return $tests;
    }

    private function parseTestFile(\SplFileInfo $file)
    {
        $tokens = preg_split('#(?:^|\n\n)-- ([a-zA-Z]+) --\n#', file_get_contents($file->getRealPath()), null, PREG_SPLIT_DELIM_CAPTURE);
        $data = array();
        $section = null;

        $varRegex = '#%([^%]+)%#';
        $variableReplacer = function($match) use (&$data) {
            list(, $var) = $match;

            switch ($var) {
                case 'testDir':
                    $testDir = sys_get_temp_dir().'/composer_functional_test'.uniqid(mt_rand(), true);
                    $data['test_dir'] = $testDir;

                    return $testDir;

                default:
                    throw new \InvalidArgumentException(sprintf('Unknown variable "%s". Supported variables: "testDir"', $var));
            }
        };

        for ($i=0,$c=count($tokens); $i<$c; $i++) {
            if ('' === $tokens[$i] && null === $section) {
                continue;
            }

            // Handle section headers.
            if (null === $section) {
                $section = strtolower($tokens[$i]);
                continue;
            }

            $sectionData = $tokens[$i];

            // Allow sections to validate, or modify their section data.
            switch ($section) {
                case 'command':
                    $sectionData = preg_replace_callback($varRegex, $variableReplacer, $sectionData);
                    break;

                case 'exitcode':
                    $sectionData = (integer) $sectionData;

                case 'erroroutputregex':
                case 'outputregex':
                case 'erroroutput':
                case 'output':
                    $sectionData = preg_replace_callback($varRegex, $variableReplacer, $sectionData);
                    break;

                default:
                    throw new \RuntimeException(sprintf('Unknown section "%s". Allowed sections: "command", "output", "erroroutput", "exitcode", "outputregex", "erroroutputregex". '
                                                       .'Section headers must be written as "-- HEADER_NAME --" and be preceded by an empty line if not at the start of the file.', $section));
            }

            $data[$section] = $sectionData;
            $section = $sectionData = null;
        }

        // validate data
        if (!isset($data['command'])) {
            throw new \RuntimeException('The test file must have a section named "COMMAND".');
        }
        if (!isset($data['output']) && !isset($data['erroroutput']) && !isset($data['outputregex']) && !isset($data['erroroutputregex'])) {
            throw new \RuntimeException('The test file must have a section named "OUTPUT", "ERROROUTPUT", "OUTPUTREGEX", or "ERROROUTPUTREGEX".');
        }

        return $data;
    }
}