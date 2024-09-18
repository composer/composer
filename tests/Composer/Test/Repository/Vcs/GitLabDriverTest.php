<?php declare(strict_types=1);

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

use Composer\IO\IOInterface;
use Composer\Json\JsonFile;
use Composer\Repository\Vcs\GitLabDriver;
use Composer\Config;
use Composer\Test\Mock\HttpDownloaderMock;
use Composer\Test\TestCase;
use Composer\Util\Filesystem;
use Composer\Util\ProcessExecutor;
use PHPUnit\Framework\MockObject\MockObject;
use Composer\Util\Http\Response;

/**
 * @author Jérôme Tamarelle <jerome@tamarelle.net>
 */
class GitLabDriverTest extends TestCase
{
    /**
     * @var string
     */
    private $home;
    /**
     * @var Config
     */
    private $config;
    /**
     * @var MockObject&IOInterface
     */
    private $io;
    /**
     * @var MockObject&ProcessExecutor
     */
    private $process;
    /**
     * @var HttpDownloaderMock
     */
    private $httpDownloader;

    public function setUp(): void
    {
        $this->home = self::getUniqueTmpDirectory();
        $this->config = $this->getConfig([
            'home' => $this->home,
            'gitlab-domains' => [
                'mycompany.com/gitlab',
                'gitlab.mycompany.com',
                'othercompany.com/nested/gitlab',
                'gitlab.com',
                'gitlab.mycompany.local',
            ],
        ]);

        $this->io = $this->getMockBuilder('Composer\IO\IOInterface')->disableOriginalConstructor()->getMock();
        $this->process = $this->getMockBuilder('Composer\Util\ProcessExecutor')->getMock();
        $this->httpDownloader = $this->getHttpDownloaderMock();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $fs = new Filesystem();
        $fs->removeDirectory($this->home);
    }

    public static function provideInitializeUrls(): array
    {
        return [
            ['https://gitlab.com/mygroup/myproject', 'https://gitlab.com/api/v4/projects/mygroup%2Fmyproject'],
            ['http://gitlab.com/mygroup/myproject', 'http://gitlab.com/api/v4/projects/mygroup%2Fmyproject'],
            ['git@gitlab.com:mygroup/myproject', 'https://gitlab.com/api/v4/projects/mygroup%2Fmyproject'],
        ];
    }

    /**
     * @dataProvider provideInitializeUrls
     * @param non-empty-string $url
     * @param non-empty-string $apiUrl
     */
    public function testInitialize(string $url, string $apiUrl): GitLabDriver
    {
        // @link http://doc.gitlab.com/ce/api/projects.html#get-single-project
        $projectData = <<<JSON
{
    "id": 17,
    "default_branch": "mymaster",
    "visibility": "private",
    "issues_enabled": true,
    "archived": false,
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

        $this->httpDownloader->expects(
            [['url' => $apiUrl, 'body' => $projectData]],
            true
        );

        $driver = new GitLabDriver(['url' => $url], $this->io, $this->config, $this->httpDownloader, $this->process);
        $driver->initialize();

        self::assertEquals($apiUrl, $driver->getApiUrl(), 'API URL is derived from the repository URL');
        self::assertEquals('mymaster', $driver->getRootIdentifier(), 'Root identifier is the default branch in GitLab');
        self::assertEquals('git@gitlab.com:mygroup/myproject.git', $driver->getRepositoryUrl(), 'The repository URL is the SSH one by default');
        self::assertEquals('https://gitlab.com/mygroup/myproject', $driver->getUrl());

        return $driver;
    }

    /**
     * @dataProvider provideInitializeUrls
     * @param non-empty-string $url
     * @param non-empty-string $apiUrl
     */
    public function testInitializePublicProject(string $url, string $apiUrl): GitLabDriver
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

        $this->httpDownloader->expects(
            [['url' => $apiUrl, 'body' => $projectData]],
            true
        );

        $driver = new GitLabDriver(['url' => $url], $this->io, $this->config, $this->httpDownloader, $this->process);
        $driver->initialize();

        self::assertEquals($apiUrl, $driver->getApiUrl(), 'API URL is derived from the repository URL');
        self::assertEquals('mymaster', $driver->getRootIdentifier(), 'Root identifier is the default branch in GitLab');
        self::assertEquals('https://gitlab.com/mygroup/myproject.git', $driver->getRepositoryUrl(), 'The repository URL is the SSH one by default');
        self::assertEquals('https://gitlab.com/mygroup/myproject', $driver->getUrl());

        return $driver;
    }

    /**
     * @dataProvider provideInitializeUrls
     * @param non-empty-string $url
     * @param non-empty-string $apiUrl
     */
    public function testInitializePublicProjectAsAnonymous(string $url, string $apiUrl): GitLabDriver
    {
        // @link http://doc.gitlab.com/ce/api/projects.html#get-single-project
        $projectData = <<<JSON
{
    "id": 17,
    "default_branch": "mymaster",
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

        $this->httpDownloader->expects(
            [['url' => $apiUrl, 'body' => $projectData]],
            true
        );

        $driver = new GitLabDriver(['url' => $url], $this->io, $this->config, $this->httpDownloader, $this->process);
        $driver->initialize();

        self::assertEquals($apiUrl, $driver->getApiUrl(), 'API URL is derived from the repository URL');
        self::assertEquals('mymaster', $driver->getRootIdentifier(), 'Root identifier is the default branch in GitLab');
        self::assertEquals('https://gitlab.com/mygroup/myproject.git', $driver->getRepositoryUrl(), 'The repository URL is the SSH one by default');
        self::assertEquals('https://gitlab.com/mygroup/myproject', $driver->getUrl());

        return $driver;
    }

    /**
     * Also support repositories over HTTP (TLS) and has a port number.
     *
     * @group gitlabHttpPort
     */
    public function testInitializeWithPortNumber(): void
    {
        $domain = 'gitlab.mycompany.com';
        $port = '5443';
        $namespace = 'mygroup/myproject';
        $url = sprintf('https://%1$s:%2$s/%3$s', $domain, $port, $namespace);
        $apiUrl = sprintf('https://%1$s:%2$s/api/v4/projects/%3$s', $domain, $port, urlencode($namespace));

        // An incomplete single project API response payload.
        // @link http://doc.gitlab.com/ce/api/projects.html#get-single-project
        $projectData = <<<'JSON'
{
    "default_branch": "1.0.x",
    "http_url_to_repo": "https://%1$s:%2$s/%3$s.git",
    "path": "myproject",
    "path_with_namespace": "%3$s",
    "web_url": "https://%1$s:%2$s/%3$s"
}
JSON;

        $this->httpDownloader->expects(
            [['url' => $apiUrl, 'body' => sprintf($projectData, $domain, $port, $namespace)]],
            true
        );

        $driver = new GitLabDriver(['url' => $url], $this->io, $this->config, $this->httpDownloader, $this->process);
        $driver->initialize();

        self::assertEquals($apiUrl, $driver->getApiUrl(), 'API URL is derived from the repository URL');
        self::assertEquals('1.0.x', $driver->getRootIdentifier(), 'Root identifier is the default branch in GitLab');
        self::assertEquals($url.'.git', $driver->getRepositoryUrl(), 'The repository URL is the SSH one by default');
        self::assertEquals($url, $driver->getUrl());
    }

    public function testInvalidSupportData(): void
    {
        $driver = $this->testInitialize($repoUrl = 'https://gitlab.com/mygroup/myproject', 'https://gitlab.com/api/v4/projects/mygroup%2Fmyproject');
        $this->setAttribute($driver, 'branches', ['main' => 'SOMESHA']);
        $this->setAttribute($driver, 'tags', []);

        $this->httpDownloader->expects([
            ['url' => 'https://gitlab.com/api/v4/projects/mygroup%2Fmyproject/repository/files/composer%2Ejson/raw?ref=SOMESHA', 'body' => '{"support": "'.$repoUrl.'" }'],
        ], true);

        $data = $driver->getComposerInformation('main');

        self::assertIsArray($data);
        self::assertSame('https://gitlab.com/mygroup/myproject/-/tree/main', $data['support']['source']);
    }

    public function testGetDist(): void
    {
        $driver = $this->testInitialize('https://gitlab.com/mygroup/myproject', 'https://gitlab.com/api/v4/projects/mygroup%2Fmyproject');

        $reference = 'c3ebdbf9cceddb82cd2089aaef8c7b992e536363';
        $expected = [
            'type' => 'zip',
            'url' => 'https://gitlab.com/api/v4/projects/mygroup%2Fmyproject/repository/archive.zip?sha='.$reference,
            'reference' => $reference,
            'shasum' => '',
        ];

        self::assertEquals($expected, $driver->getDist($reference));
    }

    public function testGetSource(): void
    {
        $driver = $this->testInitialize('https://gitlab.com/mygroup/myproject', 'https://gitlab.com/api/v4/projects/mygroup%2Fmyproject');

        $reference = 'c3ebdbf9cceddb82cd2089aaef8c7b992e536363';
        $expected = [
            'type' => 'git',
            'url' => 'git@gitlab.com:mygroup/myproject.git',
            'reference' => $reference,
        ];

        self::assertEquals($expected, $driver->getSource($reference));
    }

    public function testGetSource_GivenPublicProject(): void
    {
        $driver = $this->testInitializePublicProject('https://gitlab.com/mygroup/myproject', 'https://gitlab.com/api/v4/projects/mygroup%2Fmyproject');

        $reference = 'c3ebdbf9cceddb82cd2089aaef8c7b992e536363';
        $expected = [
            'type' => 'git',
            'url' => 'https://gitlab.com/mygroup/myproject.git',
            'reference' => $reference,
        ];

        self::assertEquals($expected, $driver->getSource($reference));
    }

    public function testGetTags(): void
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

        $this->httpDownloader->expects(
            [['url' => $apiUrl, 'body' => $tagData]],
            true
        );
        $driver->setHttpDownloader($this->httpDownloader);

        $expected = [
            'v1.0.0' => '092ed2c762bbae331e3f51d4a17f67310bf99a81',
            'v2.0.0' => '8e8f60b3ec86d63733db3bd6371117a758027ec6',
        ];

        self::assertEquals($expected, $driver->getTags());
        self::assertEquals($expected, $driver->getTags(), 'Tags are cached');
    }

    public function testGetPaginatedRefs(): void
    {
        $driver = $this->testInitialize('https://gitlab.com/mygroup/myproject', 'https://gitlab.com/api/v4/projects/mygroup%2Fmyproject');

        // @link http://doc.gitlab.com/ce/api/repositories.html#list-project-repository-branches
        $branchData = [
            [
               "name" => "mymaster",
                "commit" => [
                    "id" => "97eda36b5c1dd953a3792865c222d4e85e5f302e",
                    "committed_date" => "2013-01-03T21:04:07.000+01:00",
                ],
            ],
            [
                "name" => "staging",
                "commit" => [
                    "id" => "502cffe49f136443f2059803f2e7192d1ac066cd",
                    "committed_date" => "2013-03-09T16:35:23.000+01:00",
                ],
            ],
        ];

        for ($i = 0; $i < 98; $i++) {
            $branchData[] = [
                "name" => "stagingdupe",
                "commit" => [
                    "id" => "502cffe49f136443f2059803f2e7192d1ac066cd",
                    "committed_date" => "2013-03-09T16:35:23.000+01:00",
                ],
            ];
        }

        $branchData = JsonFile::encode($branchData);

        $this->httpDownloader->expects(
            [
                [
                    'url' => 'https://gitlab.com/api/v4/projects/mygroup%2Fmyproject/repository/branches?per_page=100',
                    'body' => $branchData,
                    'headers' => ['Link: <http://gitlab.com/api/v4/projects/mygroup%2Fmyproject/repository/tags?id=mygroup%2Fmyproject&page=2&per_page=20>; rel="next", <http://gitlab.com/api/v4/projects/mygroup%2Fmyproject/repository/tags?id=mygroup%2Fmyproject&page=1&per_page=20>; rel="first", <http://gitlab.com/api/v4/projects/mygroup%2Fmyproject/repository/tags?id=mygroup%2Fmyproject&page=3&per_page=20>; rel="last"'],
                ],
                [
                    'url' => "http://gitlab.com/api/v4/projects/mygroup%2Fmyproject/repository/tags?id=mygroup%2Fmyproject&page=2&per_page=20",
                    'body' => $branchData,
                    'headers' => ['Link: <http://gitlab.com/api/v4/projects/mygroup%2Fmyproject/repository/tags?id=mygroup%2Fmyproject&page=2&per_page=20>; rel="prev", <http://gitlab.com/api/v4/projects/mygroup%2Fmyproject/repository/tags?id=mygroup%2Fmyproject&page=1&per_page=20>; rel="first", <http://gitlab.com/api/v4/projects/mygroup%2Fmyproject/repository/tags?id=mygroup%2Fmyproject&page=3&per_page=20>; rel="last"'],
                ],
            ],
            true
        );

        $driver->setHttpDownloader($this->httpDownloader);

        $expected = [
            'mymaster' => '97eda36b5c1dd953a3792865c222d4e85e5f302e',
            'staging' => '502cffe49f136443f2059803f2e7192d1ac066cd',
            'stagingdupe' => '502cffe49f136443f2059803f2e7192d1ac066cd',
        ];

        self::assertEquals($expected, $driver->getBranches());
        self::assertEquals($expected, $driver->getBranches(), 'Branches are cached');
    }

    public function testGetBranches(): void
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

        $this->httpDownloader->expects(
            [['url' => $apiUrl, 'body' => $branchData]],
            true
        );

        $driver->setHttpDownloader($this->httpDownloader);

        $expected = [
            'mymaster' => '97eda36b5c1dd953a3792865c222d4e85e5f302e',
            'staging' => '502cffe49f136443f2059803f2e7192d1ac066cd',
        ];

        self::assertEquals($expected, $driver->getBranches());
        self::assertEquals($expected, $driver->getBranches(), 'Branches are cached');
    }

    /**
     * @group gitlabHttpPort
     * @dataProvider dataForTestSupports
     */
    public function testSupports(string $url, bool $expected): void
    {
        self::assertSame($expected, GitLabDriver::supports($this->io, $this->config, $url));
    }

    public static function dataForTestSupports(): array
    {
        return [
            ['http://gitlab.com/foo/bar', true],
            ['http://gitlab.mycompany.com:5443/foo/bar', true],
            ['http://gitlab.com/foo/bar/', true],
            ['http://gitlab.com/foo/bar/', true],
            ['http://gitlab.com/foo/bar.git', true],
            ['http://gitlab.com/foo/bar.git', true],
            ['http://gitlab.com/foo/bar.baz.git', true],
            ['https://gitlab.com/foo/bar', extension_loaded('openssl')], // Platform requirement
            ['https://gitlab.mycompany.com:5443/foo/bar', extension_loaded('openssl')], // Platform requirement
            ['git@gitlab.com:foo/bar.git', extension_loaded('openssl')],
            ['git@example.com:foo/bar.git', false],
            ['http://example.com/foo/bar', false],
            ['http://mycompany.com/gitlab/mygroup/myproject', true],
            ['https://mycompany.com/gitlab/mygroup/myproject', extension_loaded('openssl')],
            ['http://othercompany.com/nested/gitlab/mygroup/myproject', true],
            ['https://othercompany.com/nested/gitlab/mygroup/myproject', extension_loaded('openssl')],
            ['http://gitlab.com/mygroup/mysubgroup/mysubsubgroup/myproject', true],
            ['https://gitlab.com/mygroup/mysubgroup/mysubsubgroup/myproject', extension_loaded('openssl')],
        ];
    }

    public function testGitlabSubDirectory(): void
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

        $this->httpDownloader->expects(
            [['url' => $apiUrl, 'body' => $projectData]],
            true
        );

        $driver = new GitLabDriver(['url' => $url], $this->io, $this->config, $this->httpDownloader, $this->process);
        $driver->initialize();

        self::assertEquals($apiUrl, $driver->getApiUrl(), 'API URL is derived from the repository URL');
    }

    public function testGitlabSubGroup(): void
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

        $this->httpDownloader->expects(
            [['url' => $apiUrl, 'body' => $projectData]],
            true
        );

        $driver = new GitLabDriver(['url' => $url], $this->io, $this->config, $this->httpDownloader, $this->process);
        $driver->initialize();

        self::assertEquals($apiUrl, $driver->getApiUrl(), 'API URL is derived from the repository URL');
    }

    public function testGitlabSubDirectorySubGroup(): void
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

        $this->httpDownloader->expects(
            [['url' => $apiUrl, 'body' => $projectData]],
            true
        );

        $driver = new GitLabDriver(['url' => $url], $this->io, $this->config, $this->httpDownloader, $this->process);
        $driver->initialize();

        self::assertEquals($apiUrl, $driver->getApiUrl(), 'API URL is derived from the repository URL');
    }

    public function testForwardsOptions(): void
    {
        $options = [
            'ssl' => [
                'verify_peer' => false,
            ],
        ];
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

        $this->httpDownloader->expects(
            [['url' => 'https://gitlab.mycompany.local/api/v4/projects/mygroup%2Fmyproject', 'body' => $projectData]],
            true
        );

        $driver = new GitLabDriver(
            ['url' => 'https://gitlab.mycompany.local/mygroup/myproject', 'options' => $options],
            $this->io,
            $this->config,
            $this->httpDownloader,
            $this->process
        );
        $driver->initialize();
    }

    public function testProtocolOverrideRepositoryUrlGeneration(): void
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

        $apiUrl = 'https://gitlab.com/api/v4/projects/mygroup%2Fmyproject';
        $url = 'git@gitlab.com:mygroup/myproject';
        $this->httpDownloader->expects(
            [['url' => $apiUrl, 'body' => $projectData]],
            true
        );

        $config = clone $this->config;
        $config->merge(['config' => ['gitlab-protocol' => 'http']]);
        $driver = new GitLabDriver(['url' => $url], $this->io, $config, $this->httpDownloader, $this->process);
        $driver->initialize();
        self::assertEquals('https://gitlab.com/mygroup/myproject.git', $driver->getRepositoryUrl(), 'Repository URL matches config request for http not git');
    }

    /**
     * @param object $object
     * @param mixed  $value
     */
    protected function setAttribute($object, string $attribute, $value): void
    {
        $attr = new \ReflectionProperty($object, $attribute);
        $attr->setAccessible(true);
        $attr->setValue($object, $value);
    }
}
