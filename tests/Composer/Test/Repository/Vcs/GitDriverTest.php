<?php

namespace Composer\Test\Repository\Vcs;

use Composer\Config;
use Composer\Repository\Vcs\GitDriver;
use Composer\Test\Mock\ProcessExecutorMock;
use Composer\Test\TestCase;

class GitDriverTest extends TestCase
{
    /** @var Config */
    private $config;
    /** @var string */
    private $home;

    public function setUp()
    {
        $this->home = self::getUniqueTmpDirectory();
        $this->config = new Config();
        $this->config->merge(array(
            'config' => array(
                'home' => $this->home,
            ),
        ));
    }

    public function testGetBranchesFilterInvalidBranchNames()
    {
        $process = new ProcessExecutorMock;
        $io = $this->getMockBuilder('Composer\IO\IOInterface')->getMock();

        $driver = new GitDriver(array('url' => 'https://example.org/acme.git'), $io, $this->config, $this->getMockBuilder('Composer\Util\HttpDownloader')->disableOriginalConstructor()->getMock(), $process);
        $this->setRepoDir($driver, $this->home);

        // Branches starting with a - character are not valid git branches names
        // Still assert that they get filtered to prevent issues later on
        $stdout = <<<GIT
* main 089681446ba44d6d9004350192486f2ceb4eaa06 commit
  2.2  12681446ba44d6d9004350192486f2ceb4eaa06 commit
  -h   089681446ba44d6d9004350192486f2ceb4eaa06 commit
GIT;

        $process
            ->expects(array(array(
                'cmd' => 'git branch --no-color --no-abbrev -v',
                'stdout' => $stdout,
            )));

        $branches = $driver->getBranches();
        $this->assertSame(array(
            'main' => '089681446ba44d6d9004350192486f2ceb4eaa06',
            '2.2' => '12681446ba44d6d9004350192486f2ceb4eaa06',
        ), $branches);
    }

    public function testFileGetContentInvalidIdentifier()
    {
        $this->expectException('\RuntimeException');

        $process = new ProcessExecutorMock;
        $io = $this->getMockBuilder('Composer\IO\IOInterface')->getMock();
        $driver = new GitDriver(array('url' => 'https://example.org/acme.git'), $io, $this->config, $this->getMockBuilder('Composer\Util\HttpDownloader')->disableOriginalConstructor()->getMock(), $process);

        $this->assertNull($driver->getFileContent('file.txt', 'h'));

        $driver->getFileContent('file.txt', '-h');
    }

    /**
     * @param GitDriver $driver
     * @param string $path
     */
    private function setRepoDir($driver, $path)
    {
        $reflectionClass = new \ReflectionClass($driver);
        $reflectionProperty = $reflectionClass->getProperty('repoDir');
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue($driver, $path);
    }
}
