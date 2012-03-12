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

/**
 * @author Till Klampaeckel <till@php.net>
 */
class SvnDriverTest extends \PHPUnit_Framework_TestCase
{
    /**
     * Provide some examples for {@self::testCredentials()}.
     *
     * @return array
     */
    public function urlProvider()
    {
        return array(
            array('http://till:test@svn.example.org/', $this->getCmd(" --no-auth-cache --username 'till' --password 'test' ")),
            array('http://svn.apache.org/', ''),
            array('svn://johndoe@example.org', $this->getCmd(" --no-auth-cache --username 'johndoe' --password '' ")),
        );
    }

    /**
     * Test the credential string.
     *
     * @param string $url    The SVN url.
     * @param string $expect The expectation for the test.
     *
     * @dataProvider urlProvider
     */
    public function testCredentials($url, $expect)
    {
        $svn = new SvnDriver($url, new NullIO);

        $this->assertEquals($expect, $svn->getSvnCredentialString());
    }

    /**
     * Test the execute method.
     */
    public function testExecute()
    {
        $this->markTestIncomplete("Currently no way to mock the output value which is passed by reference.");

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

        $svn = new SvnDriver('http://till:secret@corp.svn.local/repo', $console, $process);
        $svn->execute('svn ls', 'http://corp.svn.local/repo');
    }

    public function testInteractiveString()
    {
        $url = 'http://svn.example.org';

        $io  = new \Composer\IO\NullIO; // non-interactive by design
        $svn = new SvnDriver($url, $io);

        $this->assertEquals(
            "svn ls --non-interactive  'http://svn.example.org'",
            $svn->getSvnCommand('svn ls', $url)
        );
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
            array('http://svn.sf.net', true),
            array('svn://example.org', true),
            array('svn+ssh://example.org', true),
            array('file:///d:/repository_name/project', true),
            array('file:///repository_name/project', true),
        );
    }

    /**
     * Nail a bug in {@link SvnDriver::support()}.
     *
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
