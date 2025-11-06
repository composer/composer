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

use Composer\Config;
use Composer\IO\IOInterface;
use Composer\Repository\Vcs\ForgejoDriver;
use Composer\Test\Mock\HttpDownloaderMock;
use Composer\Test\TestCase;
use Composer\Util\Filesystem;
use PHPUnit\Framework\MockObject\MockObject;

class ForgejoDriverTest extends TestCase
{
    /** @var string */
    private $home;
    /** @var Config */
    private $config;
    /** @var IOInterface&MockObject */
    private $io;
    /** @var HttpDownloaderMock */
    private $httpDownloader;

    public function setUp(): void
    {
        $this->home = self::getUniqueTmpDirectory();
        $this->config = new Config();
        $this->config->merge([
            'config' => [
                'home' => $this->home,
                'forgejo-domains' => ['codeberg.org'],
            ],
        ]);

        $this->io = $this->getMockBuilder('Composer\IO\IOInterface')->getMock();
        $this->httpDownloader = $this->getHttpDownloaderMock($this->io, $this->config);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $fs = new Filesystem;
        $fs->removeDirectory($this->home);
    }

    public function testPublicRepository(): void
    {
        $this->expectInteractiveIO();

        $this->httpDownloader->expects(
            [
                ['url' => 'https://codeberg.org/api/v1/repos/acme/repo', 'body' => (string) json_encode([
                    'default_branch' => 'main',
                    'has_issues' => true,
                    'archived' => false,
                    'private' => false,
                    'html_url' => 'https://codeberg.org/acme/repo',
                    'ssh_url' => 'git@codeberg.org:acme/repo.git',
                    'clone_url' => 'https://codeberg.org/acme/repo.git',
                ])],
            ],
            true
        );

        $driver = $this->initializeDriver('https://codeberg.org/acme/repo.git');
        self::assertEquals('main', $driver->getRootIdentifier());

        $sha = 'SOMESHA';
        $dist = $driver->getDist($sha);
        self::assertIsArray($dist);
        self::assertEquals('zip', $dist['type']);
        self::assertEquals('https://codeberg.org/api/v1/repos/acme/repo/archive/SOMESHA.zip', $dist['url']);
        self::assertEquals($sha, $dist['reference']);

        $source = $driver->getSource($sha);
        self::assertEquals('git', $source['type']);
        self::assertEquals('https://codeberg.org/acme/repo.git', $source['url']);
        self::assertEquals($sha, $source['reference']);
    }

    public function testGetBranches(): void
    {
        $this->expectInteractiveIO();

        $this->httpDownloader->expects(
            [
                ['url' => 'https://codeberg.org/api/v1/repos/acme/repo', 'body' => (string) json_encode([
                    'default_branch' => 'main',
                    'has_issues' => true,
                    'archived' => false,
                    'private' => false,
                    'html_url' => 'https://codeberg.org/acme/repo',
                    'ssh_url' => 'git@codeberg.org:acme/repo.git',
                    'clone_url' => 'https://codeberg.org/acme/repo.git',
                ])],
                ['url' => 'https://codeberg.org/api/v1/repos/acme/repo/branches?per_page=100', 'body' => (string) json_encode([
                    ['name' => 'main', 'commit' => ['id' => 'SOMESHA']],
                ])],
            ],
            true
        );

        $driver = $this->initializeDriver('https://codeberg.org/acme/repo.git');
        self::assertEquals(['main' => 'SOMESHA'], $driver->getBranches());
    }

    public function testGetTags(): void
    {
        $this->expectInteractiveIO();

        $this->httpDownloader->expects(
            [
                ['url' => 'https://codeberg.org/api/v1/repos/acme/repo', 'body' => (string) json_encode([
                    'default_branch' => 'main',
                    'has_issues' => true,
                    'archived' => false,
                    'private' => false,
                    'html_url' => 'https://codeberg.org/acme/repo',
                    'ssh_url' => 'git@codeberg.org:acme/repo.git',
                    'clone_url' => 'https://codeberg.org/acme/repo.git',
                ])],
                ['url' => 'https://codeberg.org/api/v1/repos/acme/repo/tags?per_page=100', 'body' => (string) json_encode([
                    ['name' => '1.0', 'commit' => ['sha' => 'SOMESHA']],
                ])],
            ],
            true
        );

        $driver = $this->initializeDriver('https://codeberg.org/acme/repo.git');
        self::assertEquals(['1.0' => 'SOMESHA'], $driver->getTags());
    }

    public function testGetEmptyFileContent(): void
    {
        $this->expectInteractiveIO();

        $this->httpDownloader->expects(
            [
                ['url' => 'https://codeberg.org/api/v1/repos/acme/repo', 'body' => (string) json_encode([
                    'default_branch' => 'main',
                    'has_issues' => true,
                    'archived' => false,
                    'private' => false,
                    'html_url' => 'https://codeberg.org/acme/repo',
                    'ssh_url' => 'git@codeberg.org:acme/repo.git',
                    'clone_url' => 'https://codeberg.org/acme/repo.git',
                ])],
                ['url' => 'https://codeberg.org/api/v1/repos/acme/repo/contents/composer.json?ref=main', 'body' => '{"encoding":"base64","content":""}'],
            ],
            true
        );

        $driver = $this->initializeDriver('https://codeberg.org/acme/repo.git');

        self::assertSame('', $driver->getFileContent('composer.json', 'main'));
    }

    /**
     * @dataProvider supportsProvider
     */
    public function testSupports(bool $expected, string $repoUrl): void
    {
        self::assertSame($expected, ForgejoDriver::supports($this->io, $this->config, $repoUrl));
    }

    /**
     * @return list<array{bool, string}>
     */
    public static function supportsProvider(): array
    {
        return [
            [false, 'https://example.org/acme/repo'],
            [true, 'https://codeberg.org/acme/repository'],
        ];
    }

    private function initializeDriver(string $repoUrl): ForgejoDriver
    {
        $driver = new ForgejoDriver(['url' => $repoUrl], $this->io, $this->config, $this->httpDownloader, $this->getProcessExecutorMock());
        $driver->initialize();

        return $driver;
    }

    private function expectInteractiveIO(bool $isInteractive = true): void
    {
        $this->io->expects($this->any())
            ->method('isInteractive')
            ->will($this->returnValue($isInteractive));
    }
}
