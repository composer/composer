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

use Composer\Config;
use Composer\Repository\Vcs\GitBitbucketDriver;
use Composer\TestCase;
use Composer\Util\Filesystem;

/**
 * @group bitbucket
 */
class GitBitbucketDriverTest extends TestCase
{
    /** @type \Composer\IO\IOInterface|\PHPUnit_Framework_MockObject_MockObject */
    private $io;
    /** @type \Composer\Config */
    private $config;
    /** @type \Composer\Util\RemoteFilesystem|\PHPUnit_Framework_MockObject_MockObject */
    private $rfs;
    /** @type string */
    private $home;
    /** @type string */
    private $originUrl = 'bitbucket.org';

    protected function setUp()
    {
        $this->io = $this->getMock('Composer\IO\IOInterface');

        $this->home = $this->getUniqueTmpDirectory();

        $this->config = new Config();
        $this->config->merge(array(
            'config' => array(
                'home' => $this->home,
            ),
        ));

        $this->rfs = $this->getMockBuilder('Composer\Util\RemoteFilesystem')
            ->disableOriginalConstructor()
            ->getMock();
    }

    public function tearDown()
    {
        $fs = new Filesystem;
        $fs->removeDirectory($this->home);
    }

    /**
     * @param  array              $repoConfig
     * @return GitBitbucketDriver
     */
    private function getDriver(array $repoConfig)
    {
        $driver = new GitBitbucketDriver(
            $repoConfig,
            $this->io,
            $this->config,
            null,
            $this->rfs
        );

        $driver->initialize();

        return $driver;
    }

    public function testGetRootIdentifierWrongScmType()
    {
        $this->setExpectedException(
            '\RuntimeException',
            'https://bitbucket.org/user/repo.git does not appear to be a git repository, use https://bitbucket.org/user/repo if this is a mercurial bitbucket repository'
        );

        $this->rfs->expects($this->once())
            ->method('getContents')
            ->with(
                $this->originUrl,
                'https://api.bitbucket.org/2.0/repositories/user/repo?fields=-project%2C-owner',
                false
            )
            ->willReturn(
                '{"scm":"hg","website":"","has_wiki":false,"name":"repo","links":{"branches":{"href":"https:\/\/api.bitbucket.org\/2.0\/repositories\/user\/repo\/refs\/branches"},"tags":{"href":"https:\/\/api.bitbucket.org\/2.0\/repositories\/user\/repo\/refs\/tags"},"clone":[{"href":"https:\/\/user@bitbucket.org\/user\/repo","name":"https"},{"href":"ssh:\/\/hg@bitbucket.org\/user\/repo","name":"ssh"}],"html":{"href":"https:\/\/bitbucket.org\/user\/repo"}},"language":"php","created_on":"2015-02-18T16:22:24.688+00:00","updated_on":"2016-05-17T13:20:21.993+00:00","is_private":true,"has_issues":false}'
            );

        $driver = $this->getDriver(array('url' => 'https://bitbucket.org/user/repo.git'));

        $driver->getRootIdentifier();
    }

    public function testDriver()
    {
        $driver = $this->getDriver(array('url' => 'https://bitbucket.org/user/repo.git'));

        $this->rfs->expects($this->any())
            ->method('getContents')
            ->withConsecutive(
                array(
                    $this->originUrl,
                    'https://api.bitbucket.org/2.0/repositories/user/repo?fields=-project%2C-owner',
                    false,
                ),
                array(
                    $this->originUrl,
                    'https://api.bitbucket.org/1.0/repositories/user/repo/main-branch',
                    false,
                ),
                array(
                    $this->originUrl,
                    'https://api.bitbucket.org/2.0/repositories/user/repo/refs/tags?pagelen=100&fields=values.name%2Cvalues.target.hash%2Cnext&sort=-target.date',
                    false,
                ),
                array(
                    $this->originUrl,
                    'https://api.bitbucket.org/2.0/repositories/user/repo/refs/branches?pagelen=100&fields=values.name%2Cvalues.target.hash%2Cvalues.heads%2Cnext&sort=-target.date',
                    false,
                ),
                array(
                    $this->originUrl,
                    'https://api.bitbucket.org/1.0/repositories/user/repo/raw/master/composer.json',
                    false,
                ),
                array(
                    $this->originUrl,
                    'https://api.bitbucket.org/2.0/repositories/user/repo/commit/master?fields=date',
                    false,
                )
            )
            ->willReturnOnConsecutiveCalls(
                '{"scm":"git","website":"","has_wiki":false,"name":"repo","links":{"branches":{"href":"https:\/\/api.bitbucket.org\/2.0\/repositories\/user\/repo\/refs\/branches"},"tags":{"href":"https:\/\/api.bitbucket.org\/2.0\/repositories\/user\/repo\/refs\/tags"},"clone":[{"href":"https:\/\/user@bitbucket.org\/user\/repo.git","name":"https"},{"href":"ssh:\/\/git@bitbucket.org\/user\/repo.git","name":"ssh"}],"html":{"href":"https:\/\/bitbucket.org\/user\/repo"}},"language":"php","created_on":"2015-02-18T16:22:24.688+00:00","updated_on":"2016-05-17T13:20:21.993+00:00","is_private":true,"has_issues":false}',
                '{"name": "master"}',
                '{"values":[{"name":"1.0.1","target":{"hash":"9b78a3932143497c519e49b8241083838c8ff8a1"}},{"name":"1.0.0","target":{"hash":"d3393d514318a9267d2f8ebbf463a9aaa389f8eb"}}]}',
                '{"values":[{"name":"master","target":{"hash":"937992d19d72b5116c3e8c4a04f960e5fa270b22"}}]}',
                '{"name": "user/repo","description": "test repo","license": "GPL","authors": [{"name": "Name","email": "local@domain.tld"}],"require": {"creator/package": "^1.0"},"require-dev": {"phpunit/phpunit": "~4.8"}}',
                '{"date": "2016-05-17T13:19:52+00:00"}'
            );

        $this->assertEquals(
            'master',
            $driver->getRootIdentifier()
        );

        $this->assertEquals(
            array(
                '1.0.1' => '9b78a3932143497c519e49b8241083838c8ff8a1',
                '1.0.0' => 'd3393d514318a9267d2f8ebbf463a9aaa389f8eb',
            ),
            $driver->getTags()
        );

        $this->assertEquals(
            array(
                'master' => '937992d19d72b5116c3e8c4a04f960e5fa270b22',
            ),
            $driver->getBranches()
        );

        $this->assertEquals(
            array(
                'name' => 'user/repo',
                'description' => 'test repo',
                'license' => 'GPL',
                'authors' => array(
                    array(
                        'name' => 'Name',
                        'email' => 'local@domain.tld',
                    ),
                ),
                'require' => array(
                    'creator/package' => '^1.0',
                ),
                'require-dev' => array(
                    'phpunit/phpunit' => '~4.8',
                ),
                'time' => '2016-05-17T13:19:52+00:00',
                'support' => array(
                    'source' => 'https://bitbucket.org/user/repo/src/937992d19d72b5116c3e8c4a04f960e5fa270b22/?at=master',
                ),
                'homepage' => 'https://bitbucket.org/user/repo',
            ),
            $driver->getComposerInformation('master')
        );

        return $driver;
    }

    /**
     * @depends testDriver
     * @param \Composer\Repository\Vcs\VcsDriverInterface $driver
     */
    public function testGetParams($driver)
    {
        $url = 'https://bitbucket.org/user/repo.git';

        $this->assertEquals($url, $driver->getUrl());

        $this->assertEquals(
            array(
                'type' => 'zip',
                'url' => 'https://bitbucket.org/user/repo/get/reference.zip',
                'reference' => 'reference',
                'shasum' => '',
            ),
            $driver->getDist('reference')
        );

        $this->assertEquals(
            array('type' => 'git', 'url' => $url, 'reference' => 'reference'),
            $driver->getSource('reference')
        );
    }

    public function testSupports()
    {
        $this->assertTrue(
            GitBitbucketDriver::supports($this->io, $this->config, 'https://bitbucket.org/user/repo.git')
        );

        $this->assertFalse(
            GitBitbucketDriver::supports($this->io, $this->config, 'git@bitbucket.org:user/repo.git')
        );

        $this->assertFalse(
            GitBitbucketDriver::supports($this->io, $this->config, 'https://github.com/user/repo.git')
        );
    }
}
