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

    public function setUp(): void
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

    protected function tearDown(): void
    {
        parent::tearDown();
        $fs = new Filesystem;
        $fs->removeDirectory($this->home);
    }

    /**
     * @dataProvider supportsDataProvider
     *
     * @param string $repositoryUrl
     */
    public function testSupports($repositoryUrl): void
    {
        $this->assertTrue(
            HgDriver::supports($this->io, $this->config, $repositoryUrl)
        );
    }

    public function supportsDataProvider(): array
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
