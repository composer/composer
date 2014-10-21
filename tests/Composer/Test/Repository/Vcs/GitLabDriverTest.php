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

use Composer\Downloader\TransportException;
use Composer\Repository\Vcs\GitLabDriver;
use Composer\Util\Filesystem;
use Composer\Config;

class GitLabDriverTest extends \PHPUnit_Framework_TestCase
{
    public function setUp()
    {
        $this->config = new Config;

        $this->io = $this->getMock('Composer\IO\IOInterface');

        $this->process = $this->getMock('Composer\Util\ProcessExecutor');

    }

    public function testInterfaceIsComplete()
    {
        $remoteFilesystem = $this->getMockBuilder('Composer\Util\RemoteFilesystem')
            ->setConstructorArgs(array($this->io))
            ->getMock();

        $driver  = new GitLabDriver(array('url' => 'http://google.com'), $this->io, $this->config, $this->process, $remoteFilesystem);
    }
}
