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
     * {@link \Composer\IO\NullIO} is always non-interactive.
     *
     * @return array
     */
    public static function urlProvider()
    {
        return array(
            array(
                'http://till:test@svn.example.org/',
                " --no-auth-cache --username 'till' --password 'test' ",
                '\Composer\IO\NullIO',
            ),
            array(
                'http://svn.apache.org/',
                '',
                '\Composer\IO\NullIO',
            ),
            array(
                'svn://johndoe@example.org',
                " --no-auth-cache --username 'johndoe' --password '' ",
                '\Composer\IO\NullIO',
            ),
            array(
                'https://till:secret@corp.svn.local/project1',
                " --username 'till' --password 'secret' ",
                '\Composer\IO\ConsoleIO',
            ),
        );
    }

    /**
     * Test the credential string.
     *
     * @param string $url     The SVN url.
     * @param string $expect  The expectation for the test.
     * @param string $ioClass The IO interface.
     * 
     * @dataProvider urlProvider
     */
    public function testCredentials($url, $expect, $ioClass)
    {
        $io  = new \Composer\IO\NullIO;
        $svn = new SvnDriver($url, $io);

        $this->assertEquals($expect, $svn->getSvnCredentialString());
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
}
