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

use Composer\Repository\Vcs\SvnDriver;
use Composer\Config;
use Composer\Test\TestCase;
use Composer\Util\Filesystem;
use Composer\Util\ProcessExecutor;

class SvnDriverTest extends TestCase
{
    /**
     * @var string
     */
    protected $home;
    /**
     * @var Config
     */
    protected $config;

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
        $fs = new Filesystem();
        $fs->removeDirectory($this->home);
    }

    public function testWrongCredentialsInUrl(): void
    {
        self::expectException('RuntimeException');
        self::expectExceptionMessage("Repository https://till:secret@corp.svn.local/repo could not be processed, wrong credentials provided (svn: OPTIONS of 'https://corp.svn.local/repo': authorization failed: Could not authenticate to server: rejected Basic challenge (https://corp.svn.local/))");

        $console = $this->getMockBuilder('Composer\IO\IOInterface')->getMock();
        $httpDownloader = $this->getMockBuilder('Composer\Util\HttpDownloader')->disableOriginalConstructor()->getMock();

        $output = "svn: OPTIONS of 'https://corp.svn.local/repo':";
        $output .= " authorization failed: Could not authenticate to server:";
        $output .= " rejected Basic challenge (https://corp.svn.local/)";

        $process = $this->getProcessExecutorMock();
        $authedCommand = ['svn', 'ls', '--verbose', '--non-interactive', '--username', 'till', '--password', 'secret', '--', 'https://till:secret@corp.svn.local/repo/trunk'];
        $process->expects([
            ['cmd' => $authedCommand, 'return' => 1, 'stderr' => $output],
            ['cmd' => $authedCommand, 'return' => 1, 'stderr' => $output],
            ['cmd' => $authedCommand, 'return' => 1, 'stderr' => $output],
            ['cmd' => $authedCommand, 'return' => 1, 'stderr' => $output],
            ['cmd' => $authedCommand, 'return' => 1, 'stderr' => $output],
            ['cmd' => $authedCommand, 'return' => 1, 'stderr' => $output],
            ['cmd' => ['svn', '--version'], 'return' => 0, 'stdout' => '1.2.3'],
        ], true);

        $repoConfig = [
            'url' => 'https://till:secret@corp.svn.local/repo',
        ];

        $svn = new SvnDriver($repoConfig, $console, $this->config, $httpDownloader, $process);
        $svn->initialize();
    }

    public static function supportProvider(): array
    {
        return [
            ['http://svn.apache.org', true],
            ['https://svn.sf.net', true],
            ['svn://example.org', true],
            ['svn+ssh://example.org', true],
        ];
    }

    /**
     * @dataProvider supportProvider
     */
    public function testSupport(string $url, bool $assertion): void
    {
        $config = new Config();
        $result = SvnDriver::supports($this->getMockBuilder('Composer\IO\IOInterface')->getMock(), $config, $url);
        self::assertEquals($assertion, $result);
    }
}
