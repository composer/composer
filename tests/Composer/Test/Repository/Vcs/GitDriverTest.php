<?php declare(strict_types=1);

namespace Composer\Test\Repository\Vcs;

use Composer\Config;
use Composer\IO\IOInterface;
use Composer\Repository\Vcs\GitDriver;
use Composer\Test\TestCase;
use Composer\Util\Filesystem;
use Composer\Util\Platform;

class GitDriverTest extends TestCase
{
    /** @var Config */
    private $config;
    /** @var string */
    private $home;
    /** @var false|string */
    private $networkEnv;

    public function setUp(): void
    {
        $this->home = self::getUniqueTmpDirectory();
        $this->config = new Config();
        $this->config->merge([
            'config' => [
                'home' => $this->home,
            ],
        ]);
        $this->networkEnv = Platform::getEnv('COMPOSER_DISABLE_NETWORK');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $fs = new Filesystem;
        $fs->removeDirectory($this->home);
        if ($this->networkEnv === false) {
            Platform::clearEnv('COMPOSER_DISABLE_NETWORK');
        } else {
            Platform::putEnv('COMPOSER_DISABLE_NETWORK', $this->networkEnv);
        }
    }

    public function testGetRootIdentifierFromRemote(): void
    {
        $process = $this->getProcessExecutorMock();
        $io = $this->getMockBuilder(IOInterface::class)->getMock();

        $driver = new GitDriver(['url' => 'https://example.org/acme.git'], $io, $this->config, $this->getHttpDownloaderMock(), $process);

        $stdout = <<<GIT
* remote origin
  Fetch URL: https://example.org/acme.git
  Push  URL: https://example.org/acme.git
  HEAD branch: main
  Remote branches:
    1.10                       tracked
    2.2                        tracked
    main                       tracked
GIT;

        $process
            ->expects([[
                'cmd' => 'git remote show origin',
                'stdout' => $stdout,
            ]]);

        $this->assertSame('main', $driver->getRootIdentifier());
    }

    public function testGetRootIdentifierFromLocalWithNetworkDisabled(): void
    {
        Platform::putEnv('COMPOSER_DISABLE_NETWORK', '1');

        $process = $this->getProcessExecutorMock();
        $io = $this->getMockBuilder(IOInterface::class)->getMock();

        $driver = new GitDriver(['url' => 'https://example.org/acme.git'], $io, $this->config, $this->getHttpDownloaderMock(), $process);

        $stdout = <<<GIT
* main
  2.2
  1.10
GIT;

        $process
            ->expects([[
                'cmd' => 'git branch --no-color',
                'stdout' => $stdout,
            ]]);

        $this->assertSame('main', $driver->getRootIdentifier());
    }
}
