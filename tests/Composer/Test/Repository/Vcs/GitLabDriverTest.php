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

use Composer\Repository\Vcs\GitLabDriver;
use Composer\Config;
use Composer\TestCase;
use Composer\Util\Filesystem;
use Prophecy\Argument;

/**
 * @author Jérôme Tamarelle <jerome@tamarelle.net>
 */
class GitLabDriverTest extends TestCase
{
    private $home;
    private $config;
    private $io;
    private $process;
    private $remoteFilesystem;

    public function setUp()
    {
        $this->home = $this->getUniqueTmpDirectory();
        $this->config = new Config();
        $this->config->merge(array(
            'config' => array(
                'home' => $this->home,
                'gitlab-domains' => array(
                    'mycompany.com/gitlab',
                    'othercompany.com/nested/gitlab',
                    'gitlab.com',
                ),
            ),
        ));

        $this->io = $this->prophesize('Composer\IO\IOInterface');
        $this->process = $this->prophesize('Composer\Util\ProcessExecutor');
        $this->remoteFilesystem = $this->prophesize('Composer\Util\RemoteFilesystem');
    }

    public function tearDown()
    {
        $fs = new Filesystem();
        $fs->removeDirectory($this->home);
    }

    public function getInitializeUrls()
    {
        return array(
            array('https://gitlab.com/mygroup/myproject', 'https://gitlab.com/api/v4/projects/mygroup%2Fmyproject'),
            array('http://gitlab.com/mygroup/myproject', 'http://gitlab.com/api/v4/projects/mygroup%2Fmyproject'),
            array('git@gitlab.com:mygroup/myproject', 'https://gitlab.com/api/v4/projects/mygroup%2Fmyproject'),
        );
    }

    /**
     * @dataProvider getInitializeUrls
     */
    public function testInitialize($url, $apiUrl)
    {
        // @link http://doc.gitlab.com/ce/api/projects.html#get-single-project
        $projectData = <<<JSON
{
    "id": 17,
    "default_branch": "mymaster",
    "visibility": "private",
    "http_url_to_repo": "https://gitlab.com/mygroup/myproject.git",
    "ssh_url_to_repo": "git@gitlab.com:mygroup/myproject.git",
    "last_activity_at": "2014-12-01T09:17:51.000+01:00",
    "name": "My Project",
    "name_with_namespace": "My Group / My Project",
    "path": "myproject",
    "path_with_namespace": "mygroup/myproject",
    "web_url": "https://gitlab.com/mygroup/myproject"
}
JSON;

        $this->remoteFilesystem
            ->getContents('gitlab.com', $apiUrl, false, array())
            ->willReturn($projectData)
            ->shouldBeCalledTimes(1)
        ;

        $driver = new GitLabDriver(array('url' => $url), $this->io->reveal(), $this->config, $this->process->reveal(), $this->remoteFilesystem->reveal());
        $driver->initialize();

        $this->assertEquals($apiUrl, $driver->getApiUrl(), 'API URL is derived from the repository URL');
        $this->assertEquals('mymaster', $driver->getRootIdentifier(), 'Root identifier is the default branch in GitLab');
        $this->assertEquals('git@gitlab.com:mygroup/myproject.git', $driver->getRepositoryUrl(), 'The repository URL is the SSH one by default');
        $this->assertEquals('https://gitlab.com/mygroup/myproject', $driver->getUrl());

        return $driver;
    }

    /**
     * @dataProvider getInitializeUrls
     */
    public function testInitializePublicProject($url, $apiUrl)
    {
        // @link http://doc.gitlab.com/ce/api/projects.html#get-single-project
        $projectData = <<<JSON
{
    "id": 17,
    "default_branch": "mymaster",
    "visibility": "public",
    "http_url_to_repo": "https://gitlab.com/mygroup/myproject.git",
    "ssh_url_to_repo": "git@gitlab.com:mygroup/myproject.git",
    "last_activity_at": "2014-12-01T09:17:51.000+01:00",
    "name": "My Project",
    "name_with_namespace": "My Group / My Project",
    "path": "myproject",
    "path_with_namespace": "mygroup/myproject",
    "web_url": "https://gitlab.com/mygroup/myproject"
}
JSON;

        $this->remoteFilesystem
            ->getContents('gitlab.com', $apiUrl, false, array())
            ->willReturn($projectData)
            ->shouldBeCalledTimes(1)
        ;

        $driver = new GitLabDriver(array('url' => $url), $this->io->reveal(), $this->config, $this->process->reveal(), $this->remoteFilesystem->reveal());
        $driver->initialize();

        $this->assertEquals($apiUrl, $driver->getApiUrl(), 'API URL is derived from the repository URL');
        $this->assertEquals('mymaster', $driver->getRootIdentifier(), 'Root identifier is the default branch in GitLab');
        $this->assertEquals('https://gitlab.com/mygroup/myproject.git', $driver->getRepositoryUrl(), 'The repository URL is the SSH one by default');
        $this->assertEquals('https://gitlab.com/mygroup/myproject', $driver->getUrl());

        return $driver;
    }

    public function testGetDist()
    {
        $driver = $this->testInitialize('https://gitlab.com/mygroup/myproject', 'https://gitlab.com/api/v4/projects/mygroup%2Fmyproject');

        $reference = 'c3ebdbf9cceddb82cd2089aaef8c7b992e536363';
        $expected = array(
            'type' => 'zip',
            'url' => 'https://gitlab.com/api/v4/projects/mygroup%2Fmyproject/repository/archive.zip?sha='.$reference,
            'reference' => $reference,
            'shasum' => '',
        );

        $this->assertEquals($expected, $driver->getDist($reference));
    }

    public function testGetSource()
    {
        $driver = $this->testInitialize('https://gitlab.com/mygroup/myproject', 'https://gitlab.com/api/v4/projects/mygroup%2Fmyproject');

        $reference = 'c3ebdbf9cceddb82cd2089aaef8c7b992e536363';
        $expected = array(
            'type' => 'git',
            'url' => 'git@gitlab.com:mygroup/myproject.git',
            'reference' => $reference,
        );

        $this->assertEquals($expected, $driver->getSource($reference));
    }

    public function testGetSource_GivenPublicProject()
    {
        $driver = $this->testInitializePublicProject('https://gitlab.com/mygroup/myproject', 'https://gitlab.com/api/v4/projects/mygroup%2Fmyproject', true);

        $reference = 'c3ebdbf9cceddb82cd2089aaef8c7b992e536363';
        $expected = array(
            'type' => 'git',
            'url' => 'https://gitlab.com/mygroup/myproject.git',
            'reference' => $reference,
        );

        $this->assertEquals($expected, $driver->getSource($reference));
    }

    public function testGetTags()
    {
        $driver = $this->testInitialize('https://gitlab.com/mygroup/myproject', 'https://gitlab.com/api/v4/projects/mygroup%2Fmyproject');

        $apiUrl = 'https://gitlab.com/api/v4/projects/mygroup%2Fmyproject/repository/tags?per_page=100';

        // @link http://doc.gitlab.com/ce/api/repositories.html#list-project-repository-tags
        $tagData = <<<JSON
[
    {
       "name": "v1.0.0",
        "commit": {
            "id": "092ed2c762bbae331e3f51d4a17f67310bf99a81",
            "committed_date": "2012-05-28T04:42:42-07:00"
        }
    },
    {
        "name": "v2.0.0",
        "commit": {
            "id": "8e8f60b3ec86d63733db3bd6371117a758027ec6",
            "committed_date": "2014-07-06T12:59:11.000+02:00"
        }
    }
]
JSON;

        $this->remoteFilesystem
            ->getContents('gitlab.com', $apiUrl, false, array())
            ->willReturn($tagData)
            ->shouldBeCalledTimes(1)
        ;
        $this->remoteFilesystem->getLastHeaders()
            ->willReturn(array());

        $driver->setRemoteFilesystem($this->remoteFilesystem->reveal());

        $expected = array(
            'v1.0.0' => '092ed2c762bbae331e3f51d4a17f67310bf99a81',
            'v2.0.0' => '8e8f60b3ec86d63733db3bd6371117a758027ec6',
        );

        $this->assertEquals($expected, $driver->getTags());
        $this->assertEquals($expected, $driver->getTags(), 'Tags are cached');
    }

    public function testGetPaginatedRefs() {
        $driver = $this->testInitialize('https://gitlab.com/mygroup/myproject', 'https://gitlab.com/api/v4/projects/mygroup%2Fmyproject');

        $apiUrl = 'https://gitlab.com/api/v4/projects/mygroup%2Fmyproject/repository/branches?per_page=100';

        // @link http://doc.gitlab.com/ce/api/repositories.html#list-project-repository-branches
        $branchData = <<<JSON
[
    {
       "name": "mymaster",
        "commit": {
            "id": "97eda36b5c1dd953a3792865c222d4e85e5f302e",
            "committed_date": "2013-01-03T21:04:07.000+01:00"
        }
    },
    {
        "name": "staging",
        "commit": {
            "id": "502cffe49f136443f2059803f2e7192d1ac066cd",
            "committed_date": "2013-03-09T16:35:23.000+01:00"
        }
    }
]
JSON;

        $this->remoteFilesystem
            ->getContents('gitlab.com', $apiUrl, false, array())
            ->willReturn($branchData)
            ->shouldBeCalledTimes(1)
        ;

         $this->remoteFilesystem
            ->getContents('gitlab.com', "http://gitlab.com/api/v4/projects/mygroup%2Fmyproject/repository/tags?id=mygroup%2Fmyproject&page=2&per_page=20", false, array())
            ->willReturn($branchData)
            ->shouldBeCalledTimes(1)
        ;

        $this->remoteFilesystem->getLastHeaders()
            ->willReturn(
                array('Link: <http://gitlab.com/api/v4/projects/mygroup%2Fmyproject/repository/tags?id=mygroup%2Fmyproject&page=2&per_page=20>; rel="next", <http://gitlab.com/api/v4/projects/mygroup%2Fmyproject/repository/tags?id=mygroup%2Fmyproject&page=1&per_page=20>; rel="first", <http://gitlab.com/api/v4/projects/mygroup%2Fmyproject/repository/tags?id=mygroup%2Fmyproject&page=3&per_page=20>; rel="last"'),
                array('Link: <http://gitlab.com/api/v4/projects/mygroup%2Fmyproject/repository/tags?id=mygroup%2Fmyproject&page=2&per_page=20>; rel="prev", <http://gitlab.com/api/v4/projects/mygroup%2Fmyproject/repository/tags?id=mygroup%2Fmyproject&page=1&per_page=20>; rel="first", <http://gitlab.com/api/v4/projects/mygroup%2Fmyproject/repository/tags?id=mygroup%2Fmyproject&page=3&per_page=20>; rel="last"')
            )
            ->shouldBeCalledTimes(2);

        $driver->setRemoteFilesystem($this->remoteFilesystem->reveal());

        $expected = array(
            'mymaster' => '97eda36b5c1dd953a3792865c222d4e85e5f302e',
            'staging' => '502cffe49f136443f2059803f2e7192d1ac066cd',
        );

        $this->assertEquals($expected, $driver->getBranches());
        $this->assertEquals($expected, $driver->getBranches(), 'Branches are cached');

    }
    public function testGetBranches()
    {
        $driver = $this->testInitialize('https://gitlab.com/mygroup/myproject', 'https://gitlab.com/api/v4/projects/mygroup%2Fmyproject');

        $apiUrl = 'https://gitlab.com/api/v4/projects/mygroup%2Fmyproject/repository/branches?per_page=100';

        // @link http://doc.gitlab.com/ce/api/repositories.html#list-project-repository-branches
        $branchData = <<<JSON
[
    {
       "name": "mymaster",
        "commit": {
            "id": "97eda36b5c1dd953a3792865c222d4e85e5f302e",
            "committed_date": "2013-01-03T21:04:07.000+01:00"
        }
    },
    {
        "name": "staging",
        "commit": {
            "id": "502cffe49f136443f2059803f2e7192d1ac066cd",
            "committed_date": "2013-03-09T16:35:23.000+01:00"
        }
    }
]
JSON;

        $this->remoteFilesystem
            ->getContents('gitlab.com', $apiUrl, false, array())
            ->willReturn($branchData)
            ->shouldBeCalledTimes(1)
        ;
        $this->remoteFilesystem->getLastHeaders()
            ->willReturn(array());

        $driver->setRemoteFilesystem($this->remoteFilesystem->reveal());

        $expected = array(
            'mymaster' => '97eda36b5c1dd953a3792865c222d4e85e5f302e',
            'staging' => '502cffe49f136443f2059803f2e7192d1ac066cd',
        );

        $this->assertEquals($expected, $driver->getBranches());
        $this->assertEquals($expected, $driver->getBranches(), 'Branches are cached');
    }

    /**
     * @dataProvider dataForTestSupports
     */
    public function testSupports($url, $expected)
    {
        $this->assertSame($expected, GitLabDriver::supports($this->io->reveal(), $this->config, $url));
    }

    public function dataForTestSupports()
    {
        return array(
            array('http://gitlab.com/foo/bar', true),
            array('http://gitlab.com/foo/bar/', true),
            array('http://gitlab.com/foo/bar.git', true),
            array('http://gitlab.com/foo/bar.baz.git', true),
            array('https://gitlab.com/foo/bar', extension_loaded('openssl')), // Platform requirement
            array('git@gitlab.com:foo/bar.git', extension_loaded('openssl')),
            array('git@example.com:foo/bar.git', false),
            array('http://example.com/foo/bar', false),
            array('http://mycompany.com/gitlab/mygroup/myproject', true),
            array('https://mycompany.com/gitlab/mygroup/myproject', extension_loaded('openssl')),
            array('http://othercompany.com/nested/gitlab/mygroup/myproject', true),
            array('https://othercompany.com/nested/gitlab/mygroup/myproject', extension_loaded('openssl')),
            array('http://gitlab.com/mygroup/mysubgroup/mysubsubgroup/myproject', true),
            array('https://gitlab.com/mygroup/mysubgroup/mysubsubgroup/myproject', extension_loaded('openssl')),
        );
    }

    public function testGitlabSubDirectory()
    {
        $url = 'https://mycompany.com/gitlab/mygroup/my-pro.ject';
        $apiUrl = 'https://mycompany.com/gitlab/api/v4/projects/mygroup%2Fmy-pro%2Eject';

        $projectData = <<<JSON
{
    "id": 17,
    "default_branch": "mymaster",
    "visibility": "private",
    "http_url_to_repo": "https://gitlab.com/gitlab/mygroup/my-pro.ject",
    "ssh_url_to_repo": "git@gitlab.com:mygroup/my-pro.ject.git",
    "last_activity_at": "2014-12-01T09:17:51.000+01:00",
    "name": "My Project",
    "name_with_namespace": "My Group / My Project",
    "path": "myproject",
    "path_with_namespace": "mygroup/my-pro.ject",
    "web_url": "https://gitlab.com/gitlab/mygroup/my-pro.ject"
}
JSON;

        $this->remoteFilesystem
            ->getContents('mycompany.com/gitlab', $apiUrl, false, array())
            ->willReturn($projectData)
            ->shouldBeCalledTimes(1)
        ;

        $driver = new GitLabDriver(array('url' => $url), $this->io->reveal(), $this->config, $this->process->reveal(), $this->remoteFilesystem->reveal());
        $driver->initialize();

        $this->assertEquals($apiUrl, $driver->getApiUrl(), 'API URL is derived from the repository URL');
    }

    public function testGitlabSubGroup()
    {
        $url = 'https://gitlab.com/mygroup/mysubgroup/myproject';
        $apiUrl = 'https://gitlab.com/api/v4/projects/mygroup%2Fmysubgroup%2Fmyproject';

        $projectData = <<<JSON
{
    "id": 17,
    "default_branch": "mymaster",
    "visibility": "private",
    "http_url_to_repo": "https://gitlab.com/mygroup/mysubgroup/my-pro.ject",
    "ssh_url_to_repo": "git@gitlab.com:mygroup/mysubgroup/my-pro.ject.git",
    "last_activity_at": "2014-12-01T09:17:51.000+01:00",
    "name": "My Project",
    "name_with_namespace": "My Group / My Project",
    "path": "myproject",
    "path_with_namespace": "mygroup/mysubgroup/my-pro.ject",
    "web_url": "https://gitlab.com/mygroup/mysubgroup/my-pro.ject"
}
JSON;

        $this->remoteFilesystem
            ->getContents('gitlab.com', $apiUrl, false, array())
            ->willReturn($projectData)
            ->shouldBeCalledTimes(1)
        ;

        $driver = new GitLabDriver(array('url' => $url), $this->io->reveal(), $this->config, $this->process->reveal(), $this->remoteFilesystem->reveal());
        $driver->initialize();

        $this->assertEquals($apiUrl, $driver->getApiUrl(), 'API URL is derived from the repository URL');
    }

    public function testGitlabSubDirectorySubGroup()
    {
        $url = 'https://mycompany.com/gitlab/mygroup/mysubgroup/myproject';
        $apiUrl = 'https://mycompany.com/gitlab/api/v4/projects/mygroup%2Fmysubgroup%2Fmyproject';

        $projectData = <<<JSON
{
    "id": 17,
    "default_branch": "mymaster",
    "visibility": "private",
    "http_url_to_repo": "https://mycompany.com/gitlab/mygroup/mysubgroup/my-pro.ject",
    "ssh_url_to_repo": "git@mycompany.com:mygroup/mysubgroup/my-pro.ject.git",
    "last_activity_at": "2014-12-01T09:17:51.000+01:00",
    "name": "My Project",
    "name_with_namespace": "My Group / My Project",
    "path": "myproject",
    "path_with_namespace": "mygroup/mysubgroup/my-pro.ject",
    "web_url": "https://mycompany.com/gitlab/mygroup/mysubgroup/my-pro.ject"
}
JSON;

        $this->remoteFilesystem
            ->getContents('mycompany.com/gitlab', $apiUrl, false, array())
            ->willReturn($projectData)
            ->shouldBeCalledTimes(1)
        ;

        $driver = new GitLabDriver(array('url' => $url), $this->io->reveal(), $this->config, $this->process->reveal(), $this->remoteFilesystem->reveal());
        $driver->initialize();

        $this->assertEquals($apiUrl, $driver->getApiUrl(), 'API URL is derived from the repository URL');
    }

    public function testForwardsOptions()
    {
        $options = array(
            'ssl' => array(
                'verify_peer' => false,
            ),
        );
        $projectData = <<<JSON
{
    "id": 17,
    "default_branch": "mymaster",
    "visibility": "private",
    "http_url_to_repo": "https://gitlab.mycompany.local/mygroup/myproject",
    "ssh_url_to_repo": "git@gitlab.mycompany.local:mygroup/myproject.git",
    "last_activity_at": "2014-12-01T09:17:51.000+01:00",
    "name": "My Project",
    "name_with_namespace": "My Group / My Project",
    "path": "myproject",
    "path_with_namespace": "mygroup/myproject",
    "web_url": "https://gitlab.mycompany.local/mygroup/myproject"
}
JSON;

        $this->remoteFilesystem
            ->getContents(Argument::cetera(), $options)
            ->willReturn($projectData)
            ->shouldBeCalled();

        $driver = new GitLabDriver(
            array('url' => 'https://gitlab.mycompany.local/mygroup/myproject', 'options' => $options),
            $this->io->reveal(),
            $this->config,
            $this->process->reveal(),
            $this->remoteFilesystem->reveal()
        );
        $driver->initialize();
    }
}
