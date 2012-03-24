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
use Composer\IO\NullIO;

class SvnDriverTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @expectedException RuntimeException
     */
    public function testWrongCredentialsInUrl()
    {
        $console = $this->getMock('Composer\IO\IOInterface');
        $console->expects($this->once())
            ->method('isInteractive')
            ->will($this->returnValue(true));

        $output  = "svn: OPTIONS of 'http://corp.svn.local/repo':";
        $output .= " authorization failed: Could not authenticate to server:";
        $output .= " rejected Basic challenge (http://corp.svn.local/)";

        $process = $this->getMock('Composer\Util\ProcessExecutor');
        $process->expects($this->once())
            ->method('execute')
            ->will($this->returnValue(1));
        $process->expects($this->once())
            ->method('getErrorOutput')
            ->will($this->returnValue($output));

        $svn = new SvnDriver('http://till:secret@corp.svn.local/repo', $console, $process);
        $svn->getTags();
    }

    private function getCmd($cmd)
    {
        if (defined('PHP_WINDOWS_VERSION_BUILD')) {
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
        if ($assertion === true) {
            $this->assertTrue(SvnDriver::supports($url));
        } else {
            $this->assertFalse(SvnDriver::supports($url));
        }
    }
}
