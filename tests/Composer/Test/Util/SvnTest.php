<?php
namespace Composer\Test\Util;

use Composer\IO\NullIO;
use Composer\Util\Svn;

class SvnTest extends \PHPUnit_Framework_TestCase
{
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
        $reflMethod = new \ReflectionMethod('Composer\\Util\\Svn', 'getCredentialString');
        $reflMethod->setAccessible(true);

        $this->assertEquals($expect, $reflMethod->invoke($svn));
    }

    /**
     * Provide some examples for {@self::testCredentials()}.
     *
     * @return array
     */
    public function urlProvider()
    {
        return array(
            array('http://till:test@svn.example.org/', $this->getCmd(" --username 'till' --password 'test' ")),
            array('http://svn.apache.org/', ''),
            array('svn://johndoe@example.org', $this->getCmd(" --username 'johndoe' --password '' ")),
        );
    }

    public function testInteractiveString()
    {
        $url = 'http://svn.example.org';

        $svn = new Svn($url, new NullIO());
        $reflMethod = new \ReflectionMethod('Composer\\Util\\Svn', 'getCommand');
        $reflMethod->setAccessible(true);

        $this->assertEquals(
            $this->getCmd("svn ls --non-interactive  'http://svn.example.org'"),
            $reflMethod->invokeArgs($svn, array('svn ls', $url))
        );
    }

    private function getCmd($cmd)
    {
        if (defined('PHP_WINDOWS_VERSION_BUILD')) {
            return strtr($cmd, "'", '"');
        }

        return $cmd;
    }
}
