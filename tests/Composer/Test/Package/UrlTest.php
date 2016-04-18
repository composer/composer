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

namespace Composer\Test\Package;

use Composer\Package\Url;

class UrlTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @test
     */
    public function creation()
    {
        $url = new Url("");
        $this->assertSame("", $url->__toString());

        $subject = Url::create("");
        $this->assertInstanceOf('Composer\Package\Url', $subject);
    }

    /**
     * @see protocol
     */
    public function provideProtocols()
    {
        return array(
            array('ssh://[user@]host.xz[:port]/path/to/repo.git/', 'ssh'),
            array('git://host.xz[:port]/path/to/repo.git/', 'git'),
            array('http://host.xz[:port]/path/to/repo.git/', 'http'),
            array('https://host.xz[:port]/path/to/repo.git/', 'https'),
            array('ftp://host.xz[:port]/path/to/repo.git/', 'ftp'),
            array('ftps://host.xz[:port]/path/to/repo.git/', 'ftps'),
            # alternative scp-like syntax
            array('[user@]host.xz:path/to/repo.git/', 'ssh'),
            # local repositories, also supported by Git natively
            array('/path/to/repo.git/', 'file'),
            array('file:///path/to/repo.git/', 'file'),
            # differentiation cases
            array('host:path/', 'ssh'),
            array('foo:bar', 'ssh'),
            array('./foo:bar', 'file'),
            # more URI examples
            array('svn://svn.host.xz:[port]/svn/uri/trunk', 'svn')
        );
    }

    /**
     * @test
     * @dataProvider provideProtocols
     */
    public function protocol($url, $protocol)
    {
        $subject = new Url($url);
        $this->assertSame($protocol, $subject->getProtocol());
    }
}
