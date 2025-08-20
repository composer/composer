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

use Composer\Repository\Vcs\GitHubDriver;
use Composer\Test\TestCase;
use Composer\Util\Filesystem;
use Composer\Config;
use Composer\Util\ProcessExecutor;

class GitHubDriverTest extends TestCase
{
    /** @var string */
    private $home;
    /** @var Config */
    private $config;

    public function setUp(): void
    {
        $this->home = self::getUniqueTmpDirectory();
        $this->config = new Config();
        $this->config->merge([
            'config' => [
                'home' => $this->home,
            ],
        ]);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $fs = new Filesystem;
        $fs->removeDirectory($this->home);
    }

    public function testPrivateRepository(): void
    {
        $repoUrl = 'http://github.com/composer/packagist';
        $repoApiUrl = 'https://api.github.com/repos/composer/packagist';
        $repoSshUrl = 'git@github.com:composer/packagist.git';
        $identifier = 'v0.0.0';
        $sha = 'SOMESHA';

        $io = $this->getMockBuilder('Composer\IO\IOInterface')->getMock();
        $io->expects($this->any())
            ->method('isInteractive')
            ->will($this->returnValue(true));

        $httpDownloader = $this->getHttpDownloaderMock($io, $this->config);
        $httpDownloader->expects(
            [
                ['url' => $repoApiUrl, 'status' => 404],
                ['url' => 'https://api.github.com/', 'body' => '{}'],
                ['url' => $repoApiUrl, 'body' => '{"master_branch": "test_master", "private": true, "owner": {"login": "composer"}, "name": "packagist"}'],
            ],
            true
        );

        $process = $this->getProcessExecutorMock();
        $process->expects([], false, ['return' => 1]);

        $io->expects($this->once())
            ->method('askAndHideAnswer')
            ->with($this->equalTo('Token (hidden): '))
            ->will($this->returnValue('sometoken'));

        $io->expects($this->any())
            ->method('setAuthentication')
            ->with($this->equalTo('github.com'), $this->matchesRegularExpression('{sometoken}'), $this->matchesRegularExpression('{x-oauth-basic}'));

        $configSource = $this->getMockBuilder('Composer\Config\ConfigSourceInterface')->getMock();
        $authConfigSource = $this->getMockBuilder('Composer\Config\ConfigSourceInterface')->getMock();
        $this->config->setConfigSource($configSource);
        $this->config->setAuthConfigSource($authConfigSource);

        $repoConfig = [
            'url' => $repoUrl,
        ];

        $gitHubDriver = new GitHubDriver($repoConfig, $io, $this->config, $httpDownloader, $process);
        $gitHubDriver->initialize();
        $this->setAttribute($gitHubDriver, 'tags', [$identifier => $sha]);

        self::assertEquals('test_master', $gitHubDriver->getRootIdentifier());

        $dist = $gitHubDriver->getDist($sha);
        self::assertIsArray($dist);
        self::assertEquals('zip', $dist['type']);
        self::assertEquals('https://api.github.com/repos/composer/packagist/zipball/SOMESHA', $dist['url']);
        self::assertEquals('SOMESHA', $dist['reference']);

        $source = $gitHubDriver->getSource($sha);
        self::assertEquals('git', $source['type']);
        self::assertEquals($repoSshUrl, $source['url']);
        self::assertEquals('SOMESHA', $source['reference']);
    }

    public function testPublicRepository(): void
    {
        $repoUrl = 'http://github.com/composer/packagist';
        $repoApiUrl = 'https://api.github.com/repos/composer/packagist';
        $identifier = 'v0.0.0';
        $sha = 'SOMESHA';

        $io = $this->getMockBuilder('Composer\IO\IOInterface')->getMock();
        $io->expects($this->any())
            ->method('isInteractive')
            ->will($this->returnValue(true));

        $httpDownloader = $this->getHttpDownloaderMock($io, $this->config);
        $httpDownloader->expects(
            [
                ['url' => $repoApiUrl, 'body' => '{"master_branch": "test_master", "owner": {"login": "composer"}, "name": "packagist"}'],
            ],
            true
        );

        $repoConfig = [
            'url' => $repoUrl,
        ];
        $repoUrl = 'https://github.com/composer/packagist.git';

        $gitHubDriver = new GitHubDriver($repoConfig, $io, $this->config, $httpDownloader, $this->getProcessExecutorMock());
        $gitHubDriver->initialize();
        $this->setAttribute($gitHubDriver, 'tags', [$identifier => $sha]);

        self::assertEquals('test_master', $gitHubDriver->getRootIdentifier());

        $dist = $gitHubDriver->getDist($sha);
        self::assertIsArray($dist);
        self::assertEquals('zip', $dist['type']);
        self::assertEquals('https://api.github.com/repos/composer/packagist/zipball/SOMESHA', $dist['url']);
        self::assertEquals($sha, $dist['reference']);

        $source = $gitHubDriver->getSource($sha);
        self::assertEquals('git', $source['type']);
        self::assertEquals($repoUrl, $source['url']);
        self::assertEquals($sha, $source['reference']);
    }

    public function testPublicRepository2(): void
    {
        $repoUrl = 'http://github.com/composer/packagist';
        $repoApiUrl = 'https://api.github.com/repos/composer/packagist';
        $identifier = 'feature/3.2-foo';
        $sha = 'SOMESHA';

        $io = $this->getMockBuilder('Composer\IO\IOInterface')->getMock();
        $io->expects($this->any())
            ->method('isInteractive')
            ->will($this->returnValue(true));

        $httpDownloader = $this->getHttpDownloaderMock($io, $this->config);
        $httpDownloader->expects(
            [
                ['url' => $repoApiUrl, 'body' => '{"master_branch": "test_master", "owner": {"login": "composer"}, "name": "packagist"}'],
                ['url' => 'https://api.github.com/repos/composer/packagist/contents/composer.json?ref=feature%2F3.2-foo', 'body' => '{"encoding":"base64","content":"'.base64_encode('{"support": {"source": "'.$repoUrl.'" }}').'"}'],
                ['url' => 'https://api.github.com/repos/composer/packagist/commits/feature%2F3.2-foo', 'body' => '{"commit": {"committer":{ "date": "2012-09-10"}}}'],
                ['url' => 'https://api.github.com/repos/composer/packagist/contents/.github/FUNDING.yml', 'body' => '{"encoding": "base64", "content": "'.base64_encode("custom: https://example.com").'"}'],
            ],
            true
        );

        $repoConfig = [
            'url' => $repoUrl,
        ];
        $repoUrl = 'https://github.com/composer/packagist.git';

        $gitHubDriver = new GitHubDriver($repoConfig, $io, $this->config, $httpDownloader, $this->getProcessExecutorMock());
        $gitHubDriver->initialize();
        $this->setAttribute($gitHubDriver, 'tags', [$identifier => $sha]);

        self::assertEquals('test_master', $gitHubDriver->getRootIdentifier());

        $dist = $gitHubDriver->getDist($sha);
        self::assertIsArray($dist);
        self::assertEquals('zip', $dist['type']);
        self::assertEquals('https://api.github.com/repos/composer/packagist/zipball/SOMESHA', $dist['url']);
        self::assertEquals($sha, $dist['reference']);

        $source = $gitHubDriver->getSource($sha);
        self::assertEquals('git', $source['type']);
        self::assertEquals($repoUrl, $source['url']);
        self::assertEquals($sha, $source['reference']);

        $data = $gitHubDriver->getComposerInformation($identifier);

        self::assertIsArray($data);
        self::assertArrayNotHasKey('abandoned', $data);
    }

    public function testInvalidSupportData(): void
    {
        $repoUrl = 'http://github.com/composer/packagist';
        $repoApiUrl = 'https://api.github.com/repos/composer/packagist';
        $identifier = 'feature/3.2-foo';
        $sha = 'SOMESHA';

        $io = $this->getMockBuilder('Composer\IO\IOInterface')->getMock();
        $io->expects($this->any())
            ->method('isInteractive')
            ->will($this->returnValue(true));

        $httpDownloader = $this->getHttpDownloaderMock($io, $this->config);
        $httpDownloader->expects(
            [
                ['url' => $repoApiUrl, 'body' => '{"master_branch": "test_master", "owner": {"login": "composer"}, "name": "packagist"}'],
                ['url' => 'https://api.github.com/repos/composer/packagist/contents/composer.json?ref=feature%2F3.2-foo', 'body' => '{"encoding":"base64","content":"'.base64_encode('{"support": "'.$repoUrl.'" }').'"}'],
                ['url' => 'https://api.github.com/repos/composer/packagist/commits/feature%2F3.2-foo', 'body' => '{"commit": {"committer":{ "date": "2012-09-10"}}}'],
                ['url' => 'https://api.github.com/repos/composer/packagist/contents/.github/FUNDING.yml', 'body' => '{"encoding": "base64", "content": "'.base64_encode("custom: https://example.com").'"}'],
            ],
            true
        );

        $repoConfig = [
            'url' => $repoUrl,
        ];

        $gitHubDriver = new GitHubDriver($repoConfig, $io, $this->config, $httpDownloader, $this->getProcessExecutorMock());
        $gitHubDriver->initialize();
        $this->setAttribute($gitHubDriver, 'tags', [$identifier => $sha]);
        $this->setAttribute($gitHubDriver, 'branches', ['test_master' => $sha]);

        $data = $gitHubDriver->getComposerInformation($identifier);

        self::assertIsArray($data);
        self::assertSame('https://github.com/composer/packagist/tree/feature/3.2-foo', $data['support']['source']);
    }

    /**
     * @dataProvider fundingUrlProvider
     * @param array<array{type: string, url: string}>|null $expected
     */
    public function testFundingFormat(string $funding, ?array $expected): void
    {
        $repoUrl = 'http://github.com/composer/packagist';
        $repoApiUrl = 'https://api.github.com/repos/composer/packagist';
        $identifier = 'feature/3.2-foo';
        $sha = 'SOMESHA';

        $io = $this->getMockBuilder('Composer\IO\IOInterface')->getMock();
        $io->expects($this->any())
            ->method('isInteractive')
            ->will($this->returnValue(true));

        $httpDownloader = $this->getHttpDownloaderMock($io, $this->config);
        $httpDownloader->expects(
            [
                ['url' => $repoApiUrl, 'body' => '{"master_branch": "test_master", "owner": {"login": "composer"}, "name": "packagist"}'],
                ['url' => 'https://api.github.com/repos/composer/packagist/contents/composer.json?ref=feature%2F3.2-foo', 'body' => '{"encoding":"base64","content":"'.base64_encode('{"support": {"source": "'.$repoUrl.'" }}').'"}'],
                ['url' => 'https://api.github.com/repos/composer/packagist/commits/feature%2F3.2-foo', 'body' => '{"commit": {"committer":{ "date": "2012-09-10"}}}'],
                ['url' => 'https://api.github.com/repos/composer/packagist/contents/.github/FUNDING.yml', 'body' => '{"encoding": "base64", "content": "'.base64_encode($funding).'"}'],
            ],
            true
        );

        $repoConfig = [
            'url' => $repoUrl,
        ];

        $gitHubDriver = new GitHubDriver($repoConfig, $io, $this->config, $httpDownloader, $this->getProcessExecutorMock());
        $gitHubDriver->initialize();
        $this->setAttribute($gitHubDriver, 'tags', [$identifier => $sha]);
        $this->setAttribute($gitHubDriver, 'branches', ['test_master' => $sha]);

        $data = $gitHubDriver->getComposerInformation($identifier);

        self::assertIsArray($data);
        if ($expected === null) {
            self::assertArrayNotHasKey('funding', $data);
        } else {
            self::assertSame(array_values($expected), array_values($data['funding']));
        }
    }

    public static function fundingUrlProvider(): array
    {
        $allNamedPlatforms = <<<'FUNDING'
community_bridge: project-name
github: [userA, userB]
issuehunt: userName
ko_fi: userName
liberapay: userName
open_collective: userName
patreon: userName
tidelift: Platform/Package
polar: userName
buy_me_a_coffee: userName
thanks_dev: u/gh/userName
otechie: userName
FUNDING;

        return [
            'All named platforms' => [
                $allNamedPlatforms,
                [
                    [
                        'type' => 'community_bridge',
                        'url' => 'https://funding.communitybridge.org/projects/project-name',
                    ],
                    [
                        'type' => 'github',
                        'url' => 'https://github.com/userA',
                    ],
                    [
                        'type' => 'github',
                        'url' => 'https://github.com/userB',
                    ],
                    [
                        'type' => 'issuehunt',
                        'url' => 'https://issuehunt.io/r/userName',
                    ],
                    [
                        'type' => 'ko_fi',
                        'url' => 'https://ko-fi.com/userName',
                    ],
                    [
                        'type' => 'liberapay',
                        'url' => 'https://liberapay.com/userName',
                    ],
                    [
                        'type' => 'open_collective',
                        'url' => 'https://opencollective.com/userName',
                    ],
                    [
                        'type' => 'patreon',
                        'url' => 'https://www.patreon.com/userName',
                    ],
                    [
                        'type' => 'tidelift',
                        'url' => 'https://tidelift.com/funding/github/Platform/Package',
                    ],
                    [
                        'type' => 'polar',
                        'url' => 'https://polar.sh/userName',
                    ],
                    [
                        'type' => 'buy_me_a_coffee',
                        'url' => 'https://www.buymeacoffee.com/userName',
                    ],
                    [
                        'type' => 'thanks_dev',
                        'url' => 'https://thanks.dev/u/gh/userName',
                    ],
                    [
                        'type' => 'otechie',
                        'url' => 'https://otechie.com/userName',
                    ],
                ],
            ],
            'Custom: single schemaless URL' => [
                'custom: example.com',
                [
                    [
                        'type' => 'custom',
                        'url' => 'https://example.com',
                    ],
                ],
            ],
            'Custom: single schemaless URL in array format' => [
                'custom: [example.com]',
                [
                    [
                        'type' => 'custom',
                        'url' => 'https://example.com',
                    ],
                ],
            ],
            'Custom: double-quoted single URL' => [
                'custom: "https://example.com"',
                [
                    [
                        'type' => 'custom',
                        'url' => 'https://example.com',
                    ],
                ],
            ],
            'Custom: double-quoted single URL in array format' => [
                'custom: ["https://example.com"]',
                [
                    [
                        'type' => 'custom',
                        'url' => 'https://example.com',
                    ],
                ],
            ],
            'Custom: array with quoted URL and schemaless unquoted URL' => [
                'custom: ["https://example.com", example.org]',
                [
                    [
                        'type' => 'custom',
                        'url' => 'https://example.com',
                    ],
                    [
                        'type' => 'custom',
                        'url' => 'https://example.org',
                    ],
                ],
            ],
            'Custom: array containing a non-simple scheme-less URL which will be discarded' => [
                'custom: [example.net/funding, "https://example.com", example.org]',
                [
                    [
                        'type' => 'custom',
                        'url' => 'https://example.com',
                    ],
                    [
                        'type' => 'custom',
                        'url' => 'https://example.org',
                    ],
                ],
            ],
        ];
    }

    public function testPublicRepositoryArchived(): void
    {
        $repoUrl = 'http://github.com/composer/packagist';
        $repoApiUrl = 'https://api.github.com/repos/composer/packagist';
        $identifier = 'v0.0.0';
        $sha = 'SOMESHA';
        $composerJsonUrl = 'https://api.github.com/repos/composer/packagist/contents/composer.json?ref=' . $sha;

        $io = $this->getMockBuilder('Composer\IO\IOInterface')->getMock();
        $io->expects($this->any())
            ->method('isInteractive')
            ->will($this->returnValue(true));

        $httpDownloader = $this->getHttpDownloaderMock($io, $this->config);
        $httpDownloader->expects(
            [
                ['url' => $repoApiUrl, 'body' => '{"master_branch": "test_master", "owner": {"login": "composer"}, "name": "packagist", "archived": true}'],
                ['url' => $composerJsonUrl, 'body' => '{"encoding": "base64", "content": "' . base64_encode('{"name": "composer/packagist"}') . '"}'],
                ['url' => 'https://api.github.com/repos/composer/packagist/commits/'.$sha, 'body' => '{"commit": {"committer":{ "date": "2012-09-10"}}}'],
                ['url' => 'https://api.github.com/repos/composer/packagist/contents/.github/FUNDING.yml', 'body' => '{"encoding": "base64", "content": "'.base64_encode("custom: https://example.com").'"}'],
            ],
            true
        );

        $repoConfig = [
            'url' => $repoUrl,
        ];

        $gitHubDriver = new GitHubDriver($repoConfig, $io, $this->config, $httpDownloader, $this->getProcessExecutorMock());
        $gitHubDriver->initialize();
        $this->setAttribute($gitHubDriver, 'tags', [$identifier => $sha]);

        $data = $gitHubDriver->getComposerInformation($sha);

        self::assertIsArray($data);
        self::assertTrue($data['abandoned']);
    }

    public function testPrivateRepositoryNoInteraction(): void
    {
        $repoUrl = 'http://github.com/composer/packagist';
        $repoApiUrl = 'https://api.github.com/repos/composer/packagist';
        $repoSshUrl = 'git@github.com:composer/packagist.git';
        $identifier = 'v0.0.0';
        $sha = 'SOMESHA';

        $io = $this->getMockBuilder('Composer\IO\IOInterface')->getMock();
        $io->expects($this->any())
            ->method('isInteractive')
            ->will($this->returnValue(false));

        $httpDownloader = $this->getHttpDownloaderMock($io, $this->config);
        $httpDownloader->expects(
            [
                ['url' => $repoApiUrl, 'status' => 404],
            ],
            true
        );

        // clean local clone if present
        $fs = new Filesystem();
        $fs->removeDirectory(sys_get_temp_dir() . '/composer-test');
        $this->config->merge(['config' => ['cache-vcs-dir' => sys_get_temp_dir() . '/composer-test/cache']]);

        $process = $this->getProcessExecutorMock();
        $process->expects([
            ['cmd' => ['git', 'config', 'github.accesstoken'], 'return' => 1],
            ['git', 'clone', '--mirror', '--', $repoSshUrl, $this->config->get('cache-vcs-dir').'/git-github.com-composer-packagist.git/'],
            [
                'cmd' => ['git', 'show-ref', '--tags', '--dereference'],
                'stdout' => $sha.' refs/tags/'.$identifier,
            ],
            [
                'cmd' => ['git', 'branch', '--no-color', '--no-abbrev', '-v'],
                'stdout' => '  test_master     edf93f1fccaebd8764383dc12016d0a1a9672d89 Fix test & behavior',
            ],
            [
                'cmd' => ['git', 'branch', '--no-color'],
                'stdout' => '* test_master',
            ],
        ], true);

        $repoConfig = [
            'url' => $repoUrl,
        ];

        $gitHubDriver = new GitHubDriver($repoConfig, $io, $this->config, $httpDownloader, $process);
        $gitHubDriver->initialize();

        self::assertEquals('test_master', $gitHubDriver->getRootIdentifier());

        $dist = $gitHubDriver->getDist($sha);
        self::assertIsArray($dist);
        self::assertEquals('zip', $dist['type']);
        self::assertEquals('https://api.github.com/repos/composer/packagist/zipball/SOMESHA', $dist['url']);
        self::assertEquals($sha, $dist['reference']);

        $source = $gitHubDriver->getSource($identifier);
        self::assertEquals('git', $source['type']);
        self::assertEquals($repoSshUrl, $source['url']);
        self::assertEquals($identifier, $source['reference']);

        $source = $gitHubDriver->getSource($sha);
        self::assertEquals('git', $source['type']);
        self::assertEquals($repoSshUrl, $source['url']);
        self::assertEquals($sha, $source['reference']);
    }

    /**
     * @dataProvider invalidUrlProvider
     */
    public function testInitializeInvalidRepoUrl(string $url): void
    {
        $this->expectException('\InvalidArgumentException');

        $repoConfig = [
            'url' => $url,
        ];

        $io = $this->getMockBuilder('Composer\IO\IOInterface')->getMock();
        $httpDownloader = $this->getMockBuilder('Composer\Util\HttpDownloader')
            ->setConstructorArgs([$io, $this->config])
            ->getMock();

        $gitHubDriver = new GitHubDriver($repoConfig, $io, $this->config, $httpDownloader, $this->getProcessExecutorMock());
        $gitHubDriver->initialize();
    }

    /**
     * @return list<array{string}>
     */
    public static function invalidUrlProvider()
    {
        return [
            ['https://github.com/acme'],
            ['https://github.com/acme/repository/releases'],
            ['https://github.com/acme/repository/pulls'],
        ];
    }

    /**
     * @dataProvider supportsProvider
     */
    public function testSupports(bool $expected, string $repoUrl): void
    {
        $io = $this->getMockBuilder('Composer\IO\IOInterface')->getMock();

        self::assertSame($expected, GitHubDriver::supports($io, $this->config, $repoUrl));
    }

    /**
     * @return list<array{bool, string}>
     */
    public static function supportsProvider(): array
    {
        return [
            [false, 'https://github.com/acme'],
            [true, 'https://github.com/acme/repository'],
            [true, 'git@github.com:acme/repository.git'],
            [false, 'https://github.com/acme/repository/releases'],
            [false, 'https://github.com/acme/repository/pulls'],
        ];
    }

    public function testGetEmptyFileContent(): void
    {
        $repoUrl = 'http://github.com/composer/packagist';

        $io = $this->getMockBuilder('Composer\IO\IOInterface')->getMock();
        $io->expects($this->any())
            ->method('isInteractive')
            ->will($this->returnValue(true));

        $httpDownloader = $this->getHttpDownloaderMock($io, $this->config);
        $httpDownloader->expects(
            [
                ['url' => 'https://api.github.com/repos/composer/packagist', 'body' => '{"master_branch": "test_master", "owner": {"login": "composer"}, "name": "packagist", "archived": true}'],
                ['url' => 'https://api.github.com/repos/composer/packagist/contents/composer.json?ref=main', 'body' => '{"encoding":"base64","content":""}'],
            ],
            true
        );

        $repoConfig = [
            'url' => $repoUrl,
        ];

        $gitHubDriver = new GitHubDriver($repoConfig, $io, $this->config, $httpDownloader, $this->getProcessExecutorMock());
        $gitHubDriver->initialize();

        self::assertSame('', $gitHubDriver->getFileContent('composer.json', 'main'));
    }

    /**
     * @param string|object $object
     * @param mixed         $value
     */
    protected function setAttribute($object, string $attribute, $value): void
    {
        $attr = new \ReflectionProperty($object, $attribute);
        (\PHP_VERSION_ID < 80100) and $attr->setAccessible(true);
        $attr->setValue($object, $value);
    }
}
