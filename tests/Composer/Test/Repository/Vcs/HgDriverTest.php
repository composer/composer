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
use Composer\IO\BufferIO;
use Composer\Test\Mock\ProcessExecutorMock;
use Composer\Test\TestCase;
use Composer\Util\Filesystem;
use Composer\Config;

class HgDriverTest extends TestCase
{
    /** @var \Composer\IO\IOInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $io;
    /** @var Config */
    private $config;
    /** @var string */
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
     *
     * @param string $repositoryUrl
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

    public function testUpdateFailureOutputIsSanitized()
    {
        $url = 'https://example.org/acme';
        $repoDir = $this->config->get('cache-vcs-dir') . '/https---example-org-acme/';
        mkdir($repoDir, 0777, true);

        $process = new ProcessExecutorMock;
        $process->expects(array(
            array('cmd' => 'hg summary'),
            array('cmd' => 'hg pull', 'return' => 255, 'stderr' => "pulling from https://user:secret@example.org/acme\nabort: HTTP Error 500\n"),
        ));

        $io = new BufferIO();
        $driver = new HgDriver(array('url' => $url), $io, $this->config, $this->getMockBuilder('Composer\Util\HttpDownloader')->disableOriginalConstructor()->getMock(), $process);
        $driver->initialize();

        $this->assertStringContainsString('pulling from https://user:***@example.org/acme', $io->getOutput());
        $this->assertStringNotContainsString('secret', $io->getOutput());
    }

    public function testGetBranchesFilterInvalidBranchNames()
    {
        $process = new ProcessExecutorMock;

        $driver = new HgDriver(array('url' => 'https://example.org/acme.git'), $this->io, $this->config, $this->getMockBuilder('Composer\Util\HttpDownloader')->disableOriginalConstructor()->getMock(), $process);

        $stdout = <<<HG_BRANCHES
default 1:dbf6c8acb640
--help  1:dbf6c8acb640
HG_BRANCHES;

        $stdout1 = <<<HG_BOOKMARKS
help    1:dbf6c8acb641
--help  1:dbf6c8acb641

HG_BOOKMARKS;

        $process
            ->expects(array(array(
                'cmd' => 'hg branches',
                'stdout' => $stdout,
            ), array(
                'cmd' => 'hg bookmarks',
                'stdout' => $stdout1,
            )));

        $branches = $driver->getBranches();
        $this->assertSame(array(
            'help' => 'dbf6c8acb641',
            'default' => 'dbf6c8acb640',
        ), $branches);
    }

    public function testFileGetContentInvalidIdentifier()
    {
        $this->setExpectedException('\RuntimeException');

        $process = new ProcessExecutorMock;
        $driver = new HgDriver(array('url' => 'https://example.org/acme.git'), $this->io, $this->config, $this->getMockBuilder('Composer\Util\HttpDownloader')->disableOriginalConstructor()->getMock(), $process);

        $this->assertNull($driver->getFileContent('file.txt', 'h'));

        $driver->getFileContent('file.txt', '-h');
    }

    public function testGetChangeDateInvalidIdentifier()
    {
        $this->setExpectedException('\RuntimeException');

        $process = new ProcessExecutorMock;
        $driver = new HgDriver(array('url' => 'https://example.org/acme.git'), $this->io, $this->config, $this->getMockBuilder('Composer\Util\HttpDownloader')->disableOriginalConstructor()->getMock(), $process);

        $driver->getChangeDate('-r foo');
    }
}
