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

namespace Composer\Test\Repository;

use Composer\Test\TestCase;
use Composer\Util\Platform;
use Symfony\Component\Process\ExecutableFinder;
use Composer\Package\Dumper\ArrayDumper;
use Composer\Repository\VcsRepository;
use Composer\Util\Filesystem;
use Composer\Util\ProcessExecutor;
use Composer\IO\NullIO;
use Composer\Config;

/**
 * @group slow
 */
class VcsRepositoryTest extends TestCase
{
    /**
     * @var string
     */
    private static $composerHome;
    /**
     * @var string
     */
    private static $gitRepo;
    /**
     * @var ?string
     */
    private $skipped = null;

    /**
     * @return void
     */
    protected function initialize(): void
    {
        $locator = new ExecutableFinder();
        if (!$locator->find('git')) {
            $this->skipped = 'This test needs a git binary in the PATH to be able to run';

            return;
        }

        $oldCwd = Platform::getCwd();
        self::$composerHome = self::getUniqueTmpDirectory();
        self::$gitRepo = self::getUniqueTmpDirectory();

        if (!@chdir(self::$gitRepo)) {
            $this->skipped = 'Could not move into the temp git repo '.self::$gitRepo;

            return;
        }

        // init
        $process = new ProcessExecutor;
        $exec = function ($command) use ($process): void {
            $cwd = Platform::getCwd();
            if ($process->execute($command, $output, $cwd) !== 0) {
                throw new \RuntimeException('Failed to execute '.$command.': '.$process->getErrorOutput());
            }
        };

        $exec('git init -q');
        $exec('git checkout -b master');
        $exec('git config user.email composertest@example.org');
        $exec('git config user.name ComposerTest');
        $exec('git config commit.gpgsign false');
        touch('foo');
        $exec('git add foo');
        $exec('git commit -m init');

        // non-composed tag & branch
        $exec('git tag 0.5.0');
        $exec('git branch oldbranch');

        // add composed tag & master branch
        $composer = array('name' => 'a/b');
        file_put_contents('composer.json', json_encode($composer));
        $exec('git add composer.json');
        $exec('git commit -m addcomposer');
        $exec('git tag 0.6.0');

        // add feature-a branch
        $exec('git checkout -b feature/a-1.0-B');
        file_put_contents('foo', 'bar feature');
        $exec('git add foo');
        $exec('git commit -m change-a');

        // add version to composer.json
        $exec('git checkout master');
        $composer['version'] = '1.0.0';
        file_put_contents('composer.json', json_encode($composer));
        $exec('git add composer.json');
        $exec('git commit -m addversion');

        // create tag with wrong version in it
        $exec('git tag 0.9.0');
        // create tag with correct version in it
        $exec('git tag 1.0.0');

        // add feature-b branch
        $exec('git checkout -b feature-b');
        file_put_contents('foo', 'baz feature');
        $exec('git add foo');
        $exec('git commit -m change-b');

        // add 1.0 branch
        $exec('git checkout master');
        $exec('git branch 1.0');

        // add 1.0.x branch
        $exec('git branch 1.1.x');

        // update master to 2.0
        $composer['version'] = '2.0.0';
        file_put_contents('composer.json', json_encode($composer));
        $exec('git add composer.json');
        $exec('git commit -m bump-version');

        chdir($oldCwd);
    }

    public function setUp(): void
    {
        if (!self::$gitRepo) {
            $this->initialize();
        }
        if ($this->skipped) {
            $this->markTestSkipped($this->skipped);
        }
    }

    public static function tearDownAfterClass(): void
    {
        $fs = new Filesystem;
        $fs->removeDirectory(self::$composerHome);
        $fs->removeDirectory(self::$gitRepo);
    }

    public function testLoadVersions(): void
    {
        $expected = array(
            '0.6.0' => true,
            '1.0.0' => true,
            '1.0.x-dev' => true,
            '1.1.x-dev' => true,
            'dev-feature-b' => true,
            'dev-feature/a-1.0-B' => true,
            'dev-master' => true,
            '9999999-dev' => true, // alias of dev-master
        );

        $config = new Config();
        $config->merge(array(
            'config' => array(
                'home' => self::$composerHome,
            ),
        ));
        $httpDownloader = $this->getMockBuilder('Composer\Util\HttpDownloader')->disableOriginalConstructor()->getMock();
        $repo = new VcsRepository(array('url' => self::$gitRepo, 'type' => 'vcs'), new NullIO, $config, $httpDownloader);
        $packages = $repo->getPackages();
        $dumper = new ArrayDumper();

        foreach ($packages as $package) {
            if (isset($expected[$package->getPrettyVersion()])) {
                unset($expected[$package->getPrettyVersion()]);
            } else {
                $this->fail('Unexpected version '.$package->getPrettyVersion().' in '.json_encode($dumper->dump($package)));
            }
        }

        $this->assertEmpty($expected, 'Missing versions: '.implode(', ', array_keys($expected)));
    }
}
