<?php
namespace Composer\Test\Util;

use Composer\IO\NullIO;
use Composer\Util\Svn;

class SvnTest
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
        $svn = new Svn($url, new NullIO);

        $this->assertEquals($expect, $svn->getCredentialString());
    }

    public function testInteractiveString()
    {
        $url = 'http://svn.example.org';

        $svn = new Svn($url, new NullIO());

        $this->assertEquals(
            "svn ls --non-interactive  'http://svn.example.org'",
            $svn->getCommand('svn ls', $url)
        );
    }
}