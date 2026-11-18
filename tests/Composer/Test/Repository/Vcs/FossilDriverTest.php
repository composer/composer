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

use Composer\Repository\Vcs\FossilDriver;
use Composer\Config;
use Composer\IO\NullIO;
use Composer\Test\TestCase;
use Composer\Util\Filesystem;

class FossilDriverTest extends TestCase
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

    public static function supportProvider(): array
    {
        return [
            ['http://fossil.kd2.org/kd2fw/', true],
            ['https://chiselapp.com/user/rkeene/repository/flint/index', true],
            ['ssh://fossil.kd2.org/kd2fw.fossil', true],
        ];
    }

    /**
     * @dataProvider supportProvider
     */
    public function testSupport(string $url, bool $assertion): void
    {
        $config = new Config();
        $result = FossilDriver::supports($this->getMockBuilder('Composer\IO\IOInterface')->getMock(), $config, $url);
        self::assertEquals($assertion, $result);
    }

    public function testCloneFailureOutputIsSanitized(): void
    {
        $url = 'https://user:secret@example.org/acme';
        $repoFile = $this->config->get('cache-repo-dir') . '/https---user-----example-org-acme.fossil';

        $process = $this->getProcessExecutorMock();
        $process->expects([
            ['cmd' => ['fossil', 'version']],
            ['cmd' => ['fossil', 'clone', '--', $url, $repoFile], 'return' => 1, 'stderr' => 'Error: unable to connect to "'.$url.'"'],
        ], true);

        $driver = new FossilDriver(['url' => $url], new NullIO(), $this->config, $this->getMockBuilder('Composer\Util\HttpDownloader')->disableOriginalConstructor()->getMock(), $process);

        try {
            $driver->initialize();
            self::fail('Expected an exception');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('unable to connect to "https://user:***@example.org/acme"', $e->getMessage());
            self::assertStringNotContainsString('secret', $e->getMessage());
        }
    }
}
