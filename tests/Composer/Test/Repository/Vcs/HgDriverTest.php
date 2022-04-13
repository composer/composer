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

namespace Composer\Test\Repository\Vcs;

use Composer\Repository\Vcs\HgDriver;
use Composer\Test\Mock\ProcessExecutorMock;
use Composer\Test\TestCase;
use Composer\Util\Filesystem;
use Composer\Config;
use Composer\Util\ProcessExecutor;

class HgDriverTest extends TestCase
{
    /** @type \Composer\IO\IOInterface|\PHPUnit_Framework_MockObject_MockObject */
    private $io;
    /** @type Config */
    private $config;
    /** @type string */
    private $home;

    public function setUp()
    {
        $this->io = $this->getMockBuilder('Composer\IO\IOInterface')->getMock();
        $this->home = $this->getUniqueTmpDirectory();
        $this->config = new Config();
        $this->config->merge(array(
            'config' => array(
                'home' => $this->home,
            ),
        ));
    }

    public function tearDown()
    {
        $fs = new Filesystem;
        $fs->removeDirectory($this->home);
    }

    /**
     * @dataProvider supportsDataProvider
     */
    public function testSupports($repositoryUrl)
    {
        $this->assertTrue(
            HgDriver::supports($this->io, $this->config, $repositoryUrl)
        );
    }

    public function supportsDataProvider()
    {
        return array(
            array('ssh://bitbucket.org/user/repo'),
            array('ssh://hg@bitbucket.org/user/repo'),
            array('ssh://user@bitbucket.org/user/repo'),
            array('https://bitbucket.org/user/repo'),
            array('https://user@bitbucket.org/user/repo'),
        );
    }

    public function testGetBranchesFilterInvalidBranchNames()
    {
        $process = $this->getMockBuilder('Composer\Util\ProcessExecutor')->getMock();
        $process->expects($this->any())
            ->method('execute')
            ->will($this->returnValue(0));

        $driver = new HgDriver(array('url' => 'https://example.org/acme.git'), $this->io, $this->config, $process);

        $stdout = <<<HG_BRANCHES
default 1:dbf6c8acb640
--help  1:dbf6c8acb640
HG_BRANCHES;

        $stdout1 = <<<HG_BOOKMARKS
help    1:dbf6c8acb641
--help  1:dbf6c8acb641

HG_BOOKMARKS;

        $process->expects($this->at(0))
            ->method('execute')
            ->with('hg branches');
        $process->expects($this->at(1))
            ->method('splitLines')
            ->will($this->returnValue(preg_split('{\r?\n}', trim($stdout))));
        $process->expects($this->at(2))
            ->method('execute')
            ->with('hg bookmarks');
        $process->expects($this->at(3))
            ->method('splitLines')
            ->will($this->returnValue(preg_split('{\r?\n}', trim($stdout1))));

        $branches = $driver->getBranches();
        $this->assertSame(array(
            'help' => 'dbf6c8acb641',
            'default' => 'dbf6c8acb640',
        ), $branches);
    }

    public function testFileGetContentInvalidIdentifier()
    {
        $this->setExpectedException('\RuntimeException');

        $process = $this->getMockBuilder('Composer\Util\ProcessExecutor')->getMock();
        $process->expects($this->any())
            ->method('execute')
            ->will($this->returnValue(0));
        $driver = new HgDriver(array('url' => 'https://example.org/acme.git'), $this->io, $this->config, $process);

        $this->assertNull($driver->getFileContent('file.txt', 'h'));

        $driver->getFileContent('file.txt', '-h');
    }
}
