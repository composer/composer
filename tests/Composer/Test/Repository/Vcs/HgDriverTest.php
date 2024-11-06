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

    /**
     * @dataProvider supportsDataProvider
     */
    public function testSupports(string $repositoryUrl): void
    {
        self::assertTrue(
            HgDriver::supports($this->io, $this->config, $repositoryUrl)
        );
    }

    public static function supportsDataProvider(): array
    {
        return [
            ['ssh://bitbucket.org/user/repo'],
            ['ssh://hg@bitbucket.org/user/repo'],
            ['ssh://user@bitbucket.org/user/repo'],
            ['https://bitbucket.org/user/repo'],
            ['https://user@bitbucket.org/user/repo'],
        ];
    }

    public function testGetBranchesFilterInvalidBranchNames(): void
    {
        $process = $this->getProcessExecutorMock();

        $driver = new HgDriver(['url' => 'https://example.org/acme.git'], $this->io, $this->config, $this->getMockBuilder('Composer\Util\HttpDownloader')->disableOriginalConstructor()->getMock(), $process);

        $stdout = <<<HG_BRANCHES
default 1:dbf6c8acb640
--help  1:dbf6c8acb640
HG_BRANCHES;

        $stdout1 = <<<HG_BOOKMARKS
help    1:dbf6c8acb641
--help  1:dbf6c8acb641

HG_BOOKMARKS;

        $process
            ->expects([[
                'cmd' => ['hg', 'branches'],
                'stdout' => $stdout,
            ], [
                'cmd' => ['hg', 'bookmarks'],
                'stdout' => $stdout1,
            ]]);

        $branches = $driver->getBranches();
        self::assertSame([
            'help' => 'dbf6c8acb641',
            'default' => 'dbf6c8acb640',
        ], $branches);
    }

    public function testFileGetContentInvalidIdentifier(): void
    {
        self::expectException('\RuntimeException');

        $process = $this->getProcessExecutorMock();
        $driver = new HgDriver(['url' => 'https://example.org/acme.git'], $this->io, $this->config, $this->getMockBuilder('Composer\Util\HttpDownloader')->disableOriginalConstructor()->getMock(), $process);

        self::assertNull($driver->getFileContent('file.txt', 'h'));

        $driver->getFileContent('file.txt', '-h');
    }
}
