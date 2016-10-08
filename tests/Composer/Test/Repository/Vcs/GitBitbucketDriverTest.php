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
     * @param array $repoConfig
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

    public function testGetRootIdentifier()
    {
        $driver = $this->getDriver(array('url' => 'https://bitbucket.org/user/repo.git'));

        $this->rfs->expects($this->any())
            ->method('getContents')
            ->with(
                $this->originUrl,
                'https://api.bitbucket.org/1.0/repositories/user/repo',
                false
            )
            ->willReturn(
                '{"scm": "git", "has_wiki": false, "last_updated": "2016-05-17T13:20:21.993", "no_forks": true, "forks_count": 0, "created_on": "2015-02-18T16:22:24.688", "owner": "user", "logo": "https://bitbucket.org/user/repo/avatar/32/?ts=1463484021", "email_mailinglist": "", "is_mq": false, "size": 9975494, "read_only": false, "fork_of": null, "mq_of": null, "followers_count": 0, "state": "available", "utc_created_on": "2015-02-18 15:22:24+00:00", "website": "", "description": "", "has_issues": false, "is_fork": false, "slug": "repo", "is_private": true, "name": "repo", "language": "php", "utc_last_updated": "2016-05-17 11:20:21+00:00", "no_public_forks": true, "creator": null, "resource_uri": "/1.0/repositories/user/repo"}'
            );

        $this->assertEquals(
            'master',
            $driver->getRootIdentifier()
        );
    }

    public function testGetParams()
    {
        $url = 'https://bitbucket.org/user/repo.git';
        $driver = $this->getDriver(array('url' => $url));

        $this->assertEquals($url, $driver->getUrl());

        $this->assertEquals(
            array(
                'type' => 'zip',
                'url' => 'https://bitbucket.org/user/repo/get/reference.zip',
                'reference' => 'reference',
                'shasum' => ''
            ),
            $driver->getDist('reference')
        );

        $this->assertEquals(
            array('type' => 'git', 'url' => $url, 'reference' => 'reference'),
            $driver->getSource('reference')
        );
    }

    public function testGetComposerInformation()
    {
        $driver = $this->getDriver(array('url' => 'https://bitbucket.org/user/repo.git'));

        $this->rfs->expects($this->any())
            ->method('getContents')
            ->withConsecutive(
                array('bitbucket.org', 'https://api.bitbucket.org/1.0/repositories/user/repo/src/master/composer.json', false),
                array('bitbucket.org', 'https://api.bitbucket.org/1.0/repositories/user/repo/changesets/master', false),
                array('bitbucket.org', 'https://api.bitbucket.org/1.0/repositories/user/repo/tags', false),
                array('bitbucket.org', 'https://api.bitbucket.org/1.0/repositories/user/repo/branches', false)
            )
            ->willReturnOnConsecutiveCalls(
                '{"node": "937992d19d72", "path": "composer.json", "data": "{\n  \"name\": \"user/repo\",\n  \"description\": \"test repo\",\n  \"license\": \"GPL\",\n  \"authors\": [\n    {\n      \"name\": \"Name\",\n      \"email\": \"local@domain.tld\"\n    }\n  ],\n  \"require\": {\n    \"creator/package\": \"^1.0\"\n  },\n  \"require-dev\": {\n    \"phpunit/phpunit\": \"~4.8\"\n  }\n}\n", "size": 269}',
                '{"node": "937992d19d72", "files": [{"type": "modified", "file": "path/to/file"}], "raw_author": "User <local@domain.tld>", "utctimestamp": "2016-05-17 11:19:52+00:00", "author": "user", "timestamp": "2016-05-17 13:19:52", "raw_node": "937992d19d72b5116c3e8c4a04f960e5fa270b22", "parents": ["71e195a33361"], "branch": "master", "message": "Commit message\n", "revision": null, "size": -1}',
                '{}',
                '{"master": {"node": "937992d19d72", "files": [{"type": "modified", "file": "path/to/file"}], "raw_author": "User <local@domain.tld>", "utctimestamp": "2016-05-17 11:19:52+00:00", "author": "user", "timestamp": "2016-05-17 13:19:52", "raw_node": "937992d19d72b5116c3e8c4a04f960e5fa270b22", "parents": ["71e195a33361"], "branch": "master", "message": "Commit message\n", "revision": null, "size": -1}}'
            );

        $this->assertEquals(
            array(
                'name' => 'user/repo',
                'description' => 'test repo',
                'license' => 'GPL',
                'authors' => array(
                    array(
                        'name' => 'Name',
                        'email' => 'local@domain.tld'
                    )
                ),
                'require' => array(
                    'creator/package' => '^1.0'
                ),
                'require-dev' => array(
                    'phpunit/phpunit' => '~4.8'
                ),
                'time' => '2016-05-17 13:19:52',
                'support' => array(
                    'source' => 'https://bitbucket.org/user/repo/src/937992d19d72b5116c3e8c4a04f960e5fa270b22/?at=master'
                )
            ),
            $driver->getComposerInformation('master')
        );
    }

    public function testGetTags()
    {
        $driver = $this->getDriver(array('url' => 'https://bitbucket.org/user/repo.git'));

        $this->rfs->expects($this->once())
            ->method('getContents')
            ->with(
                'bitbucket.org',
                'https://api.bitbucket.org/1.0/repositories/user/repo/tags',
                false
            )
            ->willReturn(
                '{"1.0.1": {"node": "9b78a3932143", "files": [{"type": "modified", "file": "path/to/file"}], "branches": [], "raw_author": "User <local@domain.tld>", "utctimestamp": "2015-04-16 14:50:40+00:00", "author": "user", "timestamp": "2015-04-16 16:50:40", "raw_node": "9b78a3932143497c519e49b8241083838c8ff8a1", "parents": ["84531c04dbfc", "50c2a4635ad0"], "branch": null, "message": "Commit message\n", "revision": null, "size": -1}, "1.0.0": {"node": "d3393d514318", "files": [{"type": "modified", "file": "path/to/file2"}], "branches": [], "raw_author": "User <local@domain.tld>", "utctimestamp": "2015-04-16 09:31:45+00:00", "author": "user", "timestamp": "2015-04-16 11:31:45", "raw_node": "d3393d514318a9267d2f8ebbf463a9aaa389f8eb", "parents": ["5a29a73cd1a0"], "branch": null, "message": "Commit message\n", "revision": null, "size": -1}}'
            );

        $this->assertEquals(
            array(
                '1.0.1' => '9b78a3932143497c519e49b8241083838c8ff8a1',
                '1.0.0' => 'd3393d514318a9267d2f8ebbf463a9aaa389f8eb'
            ),
            $driver->getTags()
        );
    }

    public function testGetBranches()
    {
        $driver = $this->getDriver(array('url' => 'https://bitbucket.org/user/repo.git'));

        $this->rfs->expects($this->once())
            ->method('getContents')
            ->with(
                'bitbucket.org',
                'https://api.bitbucket.org/1.0/repositories/user/repo/branches',
                false
            )
            ->willReturn(
                '{"master": {"node": "937992d19d72", "files": [{"type": "modified", "file": "path/to/file"}], "raw_author": "User <local@domain.tld>", "utctimestamp": "2016-05-17 11:19:52+00:00", "author": "user", "timestamp": "2016-05-17 13:19:52", "raw_node": "937992d19d72b5116c3e8c4a04f960e5fa270b22", "parents": ["71e195a33361"], "branch": "master", "message": "Commit message\n", "revision": null, "size": -1}}'
            );

        $this->assertEquals(
            array(
                'master' => '937992d19d72b5116c3e8c4a04f960e5fa270b22'
            ),
            $driver->getBranches()
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
