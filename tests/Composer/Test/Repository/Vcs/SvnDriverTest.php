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

use Composer\Repository\Vcs\SvnDriver;
use Composer\Config;
use Composer\Test\TestCase;
use Composer\Util\Filesystem;
use Composer\Util\Platform;

class SvnDriverTest extends TestCase
{
    protected $home;
    protected $config;

    public function setUp()
    {
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
        $fs = new Filesystem();
        $fs->removeDirectory($this->home);
    }

    /**
     * @expectedException RuntimeException
     */
    public function testWrongCredentialsInUrl()
    {
        $console = $this->getMockBuilder('Composer\IO\IOInterface')->getMock();

        $output = "svn: OPTIONS of 'https://corp.svn.local/repo':";
        $output .= " authorization failed: Could not authenticate to server:";
        $output .= " rejected Basic challenge (https://corp.svn.local/)";

        $process = $this->getMockBuilder('Composer\Util\ProcessExecutor')->getMock();
        $process->expects($this->at(1))
            ->method('execute')
            ->will($this->returnValue(1));
        $process->expects($this->exactly(7))
            ->method('getErrorOutput')
            ->will($this->returnValue($output));
        $process->expects($this->at(2))
            ->method('execute')
            ->will($this->returnValue(0));

        $repoConfig = array(
            'url' => 'https://till:secret@corp.svn.local/repo',
        );

        $svn = new SvnDriver($repoConfig, $console, $this->config, $process);
        $svn->initialize();
    }

    private function getCmd($cmd)
    {
        if (Platform::isWindows()) {
            return strtr($cmd, "'", '"');
        }

        return $cmd;
    }

    public static function supportProvider()
    {
        return array(
            array('http://svn.apache.org', true),
            array('https://svn.sf.net', true),
            array('svn://example.org', true),
            array('svn+ssh://example.org', true),
        );
    }

    /**
     * @dataProvider supportProvider
     */
    public function testSupport($url, $assertion)
    {
        $config = new Config();
        $result = SvnDriver::supports($this->getMockBuilder('Composer\IO\IOInterface')->getMock(), $config, $url);
        $this->assertEquals($assertion, $result);
    }
}
