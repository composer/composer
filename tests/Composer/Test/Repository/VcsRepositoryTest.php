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

namespace Composer\Test\Repository;

use Symfony\Component\Process\ExecutableFinder;
use Composer\Package\Dumper\ArrayDumper;
use Composer\Repository\VcsRepository;
use Composer\Repository\Vcs\GitDriver;
use Composer\Util\Filesystem;
use Composer\Util\ProcessExecutor;
use Composer\IO\NullIO;

class VcsRepositoryTest extends \PHPUnit_Framework_TestCase
{
    private static $gitRepo;
    private static $skipped;

    public static function setUpBeforeClass()
    {
        $oldCwd = getcwd();
        self::$gitRepo = sys_get_temp_dir() . '/composer-git-'.rand().'/';

        $locator = new ExecutableFinder();
        if (!$locator->find('git')) {
            self::$skipped = 'This test needs a git binary in the PATH to be able to run';
            return;
        }
        if (!mkdir(self::$gitRepo) || !chdir(self::$gitRepo)) {
            self::$skipped = 'Could not create and move into the temp git repo '.self::$gitRepo;
            return;
        }

        // init
        $process = new ProcessExecutor;
        $process->execute('git init', $null);
        touch('foo');
        $process->execute('git add foo', $null);
        $process->execute('git commit -m init', $null);

        // non-composed tag & branch
        $process->execute('git tag 0.5.0', $null);
        $process->execute('git branch oldbranch', $null);

        // add composed tag & master branch
        $composer = array('name' => 'a/b');
        file_put_contents('composer.json', json_encode($composer));
        $process->execute('git add composer.json', $null);
        $process->execute('git commit -m addcomposer', $null);
        $process->execute('git tag 0.6.0', $null);

        // add feature-a branch
        $process->execute('git checkout -b feature-a', $null);
        file_put_contents('foo', 'bar feature');
        $process->execute('git add foo', $null);
        $process->execute('git commit -m change-a', $null);

        // add version to composer.json
        $process->execute('git checkout master', $null);
        $composer['version'] = '1.0.0';
        file_put_contents('composer.json', json_encode($composer));
        $process->execute('git add composer.json', $null);
        $process->execute('git commit -m addversion', $null);

        // create tag with wrong version in it
        $process->execute('git tag 0.9.0', $null);
        // create tag with correct version in it
        $process->execute('git tag 1.0.0', $null);

        // add feature-b branch
        $process->execute('git checkout -b feature-b', $null);
        file_put_contents('foo', 'baz feature');
        $process->execute('git add foo', $null);
        $process->execute('git commit -m change-b', $null);

        // add 1.0 branch
        $process->execute('git checkout master', $null);
        $process->execute('git branch 1.0', $null);

        // add 1.0.x branch
        $process->execute('git branch 1.1.x', $null);

        // update master to 2.0
        $composer['version'] = '2.0.0';
        file_put_contents('composer.json', json_encode($composer));
        $process->execute('git add composer.json', $null);
        $process->execute('git commit -m bump-version', $null);

        chdir($oldCwd);
    }

    public function setUp()
    {
        if (self::$skipped) {
            $this->markTestSkipped(self::$skipped);
        }
    }

    public static function tearDownAfterClass()
    {
        $fs = new Filesystem;
        $fs->removeDirectory(self::$gitRepo);
    }

    public function testLoadVersions()
    {
        $expected = array(
            '0.6.0' => true,
            '1.0.0' => true,
            '1.0.x-dev' => true,
            '1.1.x-dev' => true,
            'dev-feature-b' => true,
            'dev-feature-a' => true,
            'dev-master' => true,
        );

        $repo = new VcsRepository(array('url' => self::$gitRepo, 'type' => 'vcs'), new NullIO);
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
