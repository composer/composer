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

namespace Composer\Test\Command;

use Composer\Test\TestCase;
use Composer\Util\Filesystem;
use Composer\Util\Platform;
use Composer\Util\ProcessExecutor;
use Generator;

class StatusCommandTest extends TestCase
{
    public function testNoLocalChanges(): void
    {
        $this->initTempComposer(['require' => ['root/req' => '1.*']]);

        $package = self::getPackage('root/req');
        $package->setType('metapackage');

        $this->createComposerLock([$package], []);
        $this->createInstalledJson([$package], []);

        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => 'status']);

        self::assertSame('No local changes', trim($appTester->getDisplay(true)));
    }

    /**
     * @dataProvider locallyModifiedPackagesUseCaseProvider
     * @param array<mixed> $composerJson
     * @param array<mixed> $commandFlags
     * @param array<mixed> $packageData
     */
    public function testLocallyModifiedPackages(
        array $composerJson,
        array $commandFlags,
        array $packageData
    ): void {
        $this->initTempComposer($composerJson);

        $package = self::getPackage($packageData['name'], $packageData['version']);
        $package->setInstallationSource($packageData['installation_source']);

        if ($packageData['installation_source'] === 'source') {
            $package->setSourceType($packageData['type']);
            $package->setSourceUrl($packageData['url']);
            $package->setSourceReference($packageData['reference']);
        }

        if ($packageData['installation_source'] === 'dist') {
            $package->setDistType($packageData['type']);
            $package->setDistUrl($packageData['url']);
            $package->setDistReference($packageData['reference']);
        }

        $this->createComposerLock([$package], []);

        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => 'install']);

        file_put_contents(getcwd() . '/vendor/' . $packageData['name'] . '/composer.json', '{}');

        $appTester->run(array_merge(['command' => 'status'], $commandFlags));

        $expected = 'You have changes in the following dependencies:';
        $actual = trim($appTester->getDisplay(true));

        self::assertStringContainsString($expected, $actual);
        self::assertStringContainsString($packageData['name'], $actual);
    }

    public static function locallyModifiedPackagesUseCaseProvider(): Generator
    {
        yield 'locally modified package from source' => [
            ['require' => ['composer/class-map-generator' => '^1.0']],
            [],
            [
                'name' => 'composer/class-map-generator' ,
                'version' => '1.1',
                'installation_source' => 'source',
                'type' => 'git',
                'url' => 'https://github.com/composer/class-map-generator.git',
                'reference' => '953cc4ea32e0c31f2185549c7d216d7921f03da9',
            ],
        ];

        yield 'locally modified package from dist' => [
            ['require' => ['composer/ca-bundle' => '^1.5']],
            ['--verbose' => true],
            [
                'name' => 'composer/ca-bundle',
                'version' => '1.5.12',
                'installation_source' => 'dist',
                'type' => 'zip',
                'url' => 'https://api.github.com/repos/composer/ca-bundle/zipball/00a2f4201641d5c53f7fc0195e6c8d9fcc321a78',
                'reference' => '00a2f4201641d5c53f7fc0195e6c8d9fcc321a78',
            ],
        ];
    }

    /**
     * @dataProvider unmodifiedPathRepositoryPackageProvider
     */
    public function testNoLocalChangesForPathRepositoryPackages(bool $symlink, bool $packageHasOwnGitRepository): void
    {
        $this->initPathRepositoryProject($symlink, $packageHasOwnGitRepository);

        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => 'status', '--verbose' => true]);

        self::assertSame('No local changes', trim($appTester->getDisplay(true)));
        self::assertSame(0, $appTester->getStatusCode());
    }

    public static function unmodifiedPathRepositoryPackageProvider(): Generator
    {
        yield 'symlinked' => [true, false];
        yield 'mirrored' => [false, false];
        yield 'symlinked with its own git repository' => [true, true];
    }

    /**
     * @dataProvider shallowCloneProvider
     */
    public function testVersionVariationOfPathRepositoryPackageWithOwnGitRepository(bool $replaceWithShallowClone): void
    {
        $dir = $this->initPathRepositoryProject(true, true);
        $this->git(['commit', '--allow-empty', '-m', 'new commit after locking'], $dir.'/packages/bar');
        if ($replaceWithShallowClone) {
            // the locked commit is then missing from the package's repository
            rename($dir.'/packages/bar', $dir.'/bar-origin');
            $this->git(['clone', '-q', '--no-local', '--depth', '1', $dir.'/bar-origin', $dir.'/packages/bar'], $dir);
        }

        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => 'status', '--verbose' => true]);

        $display = $appTester->getDisplay(true);
        self::assertStringContainsString('You have version variations in the following dependencies:', $display);
        self::assertStringContainsString('vendor/test/bar', $display);
    }

    public static function shallowCloneProvider(): Generator
    {
        yield 'new commit' => [false];
        yield 'shallow clone of a new commit' => [true];
    }

    public function testVersionVariationOfPathPackageInGitWorktree(): void
    {
        $this->skipIfNotExecutable('git');

        $dir = $this->initTempComposer();
        mkdir($dir.'/bar-repository');
        file_put_contents($dir.'/bar-repository/composer.json', '{"name": "test/bar"}');
        $this->git(['init', '-q'], $dir.'/bar-repository');
        $this->git(['add', '-A'], $dir.'/bar-repository');
        $this->git(['commit', '-m', 'init'], $dir.'/bar-repository');
        // a linked worktree has a .git file instead of a .git directory
        $this->git(['worktree', 'add', '-b', 'feature', $dir.'/bar'], $dir.'/bar-repository');
        $process = new ProcessExecutor();
        $process->execute(['git', 'rev-parse', 'HEAD'], $commit, $dir.'/bar');

        file_put_contents($dir.'/composer.json', json_encode([
            'repositories' => [
                ['type' => 'package', 'package' => ['name' => 'test/bar', 'version' => '1.0.0', 'dist' => ['type' => 'path', 'url' => 'bar', 'reference' => trim($commit)]]],
                ['packagist.org' => false],
            ],
            'require' => ['test/bar' => '1.0.0'],
        ]));

        $appTester = $this->getApplicationTester();
        self::assertSame(0, $appTester->run(['command' => 'install']));

        $this->git(['commit', '--allow-empty', '-m', 'new commit after locking'], $dir.'/bar');
        $appTester->run(['command' => 'status', '--verbose' => true]);

        $display = $appTester->getDisplay(true);
        self::assertStringContainsString('You have version variations in the following dependencies:', $display);
        self::assertStringContainsString('vendor/test/bar', $display);
    }

    public function testSymlinkReplacingMirroredPathRepositoryPackageIsReported(): void
    {
        if (Platform::isWindows()) {
            $this->markTestSkipped('Creating a directory symlink requires extra privileges on Windows');
        }

        $dir = $this->initPathRepositoryProject(false, false);

        $filesystem = new Filesystem();
        $filesystem->removeDirectory($dir.'/vendor/test/bar');
        symlink($dir.'/packages/bar', $dir.'/vendor/test/bar');

        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => 'status', '--verbose' => true]);

        self::assertStringContainsString('vendor/test/bar is a symbolic link.', $appTester->getDisplay(true));
        self::assertSame(1, $appTester->getStatusCode());
    }

    public function testSymlinkedDistPackageIsReported(): void
    {
        if (Platform::isWindows()) {
            $this->markTestSkipped('Creating a directory symlink requires extra privileges on Windows');
        }

        $dir = $this->initTempComposer([
            'repositories' => [['type' => 'artifact', 'url' => 'artifacts/']],
            'require' => ['vendor0/package0' => '0.0.1'],
        ]);
        mkdir($dir.'/artifacts');
        copy(__DIR__.'/../Repository/Fixtures/artifacts/package0.zip', $dir.'/artifacts/package0.zip');

        $appTester = $this->getApplicationTester();
        self::assertSame(0, $appTester->run(['command' => 'install']));

        rename($dir.'/vendor/vendor0/package0', $dir.'/package0');
        symlink($dir.'/package0', $dir.'/vendor/vendor0/package0');

        $appTester->run(['command' => 'status', '--verbose' => true]);

        self::assertStringContainsString('vendor/vendor0/package0 is a symbolic link.', $appTester->getDisplay(true));
        self::assertSame(1, $appTester->getStatusCode());
    }

    /**
     * Installs test/bar from a path repository inside a git repository, the layout of a project with local packages
     */
    private function initPathRepositoryProject(bool $symlink, bool $packageHasOwnGitRepository): string
    {
        $this->skipIfNotExecutable('git');

        $dir = $this->initTempComposer([
            'repositories' => [['type' => 'path', 'url' => 'packages/*', 'options' => ['symlink' => $symlink]]],
            'require' => ['test/bar' => '^1.0'],
        ]);
        mkdir($dir.'/packages/bar', 0777, true);
        file_put_contents($dir.'/packages/bar/composer.json', '{"name": "test/bar", "version": "1.0.0"}');
        if ($packageHasOwnGitRepository) {
            $this->git(['init', '-q'], $dir.'/packages/bar');
            $this->git(['add', '-A'], $dir.'/packages/bar');
            $this->git(['commit', '-m', 'init'], $dir.'/packages/bar');
        }

        file_put_contents($dir.'/.gitignore', "/vendor/\n/composer-home/\n");
        $this->git(['init', '-q'], $dir);
        $this->git(['add', '-A'], $dir);
        $this->git(['commit', '-m', 'init'], $dir);

        $appTester = $this->getApplicationTester();
        self::assertSame(0, $appTester->run(['command' => 'install']));

        return $dir;
    }

    /**
     * @param non-empty-list<string> $args
     */
    private function git(array $args, string $cwd): void
    {
        $process = new ProcessExecutor();
        $command = array_merge(['git', '-c', 'user.name=Composer', '-c', 'user.email=composer@example.org', '-c', 'commit.gpgsign=false'], $args);
        if (0 !== $process->execute($command, $output, $cwd)) {
            throw new \RuntimeException('Failed to execute '.implode(' ', $command).': '.$process->getErrorOutput());
        }
    }
}
