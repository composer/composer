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
use Composer\TestCase;
use Composer\Util\Filesystem;
use Composer\Config;

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
        $this->io = $this->getMock('Composer\IO\IOInterface');
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

}
