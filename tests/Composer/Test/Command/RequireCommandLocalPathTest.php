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

use Composer\Json\JsonFile;
use Composer\Test\TestCase;

class RequireCommandLocalPathTest extends TestCase
{
    /**
     * @var list<string>
     */
    private array $localPkgDirs = [];

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach ($this->localPkgDirs as $dir) {
            $fs = new \Composer\Util\Filesystem();
            $fs->removeDirectory($dir);
        }
    }

    private function createLocalPackage(string $name, ?string $version = null): string
    {
        $dir = self::getUniqueTmpDirectory();
        $this->localPkgDirs[] = $dir;

        $json = ['name' => $name];
        if (null !== $version) {
            $json['version'] = $version;
        }

        file_put_contents($dir.'/composer.json', json_encode($json));

        return $dir;
    }

    public function testRequireLocalPathAddsRepoAndPackage(): void
    {
        $localDir = $this->createLocalPackage('local/test-pkg', '1.0.0');

        $this->initTempComposer();

        $appTester = $this->getApplicationTester();
        $appTester->run([
            'command' => 'require',
            '--no-audit' => true,
            '--no-install' => true,
            'packages' => [$localDir],
        ]);

        $composerJson = (new JsonFile('composer.json'))->read();

        // Assert path repository was added
        self::assertArrayHasKey('repositories', $composerJson);
        $repos = $composerJson['repositories'];
        $found = false;
        foreach ($repos as $repo) {
            if (isset($repo['type']) && $repo['type'] === 'path' && isset($repo['url'])) {
                $found = true;
                self::assertSame(str_replace('\\', '/', $localDir), $repo['url']);
            }
        }
        self::assertTrue($found, 'Path repository should be added to composer.json');

        // Assert the package was required
        self::assertArrayHasKey('require', $composerJson);
        self::assertArrayHasKey('local/test-pkg', $composerJson['require']);
    }

    public function testRequireLocalRelativePath(): void
    {
        $projectDir = $this->initTempComposer();
        $localDir = $projectDir.'/packages/my-lib';
        mkdir($localDir, 0777, true);
        file_put_contents($localDir.'/composer.json', json_encode(['name' => 'local/my-lib', 'version' => '2.0.0']));

        $appTester = $this->getApplicationTester();
        $appTester->run([
            'command' => 'require',
            '--no-audit' => true,
            '--no-install' => true,
            'packages' => ['./packages/my-lib'],
        ]);

        $composerJson = (new JsonFile('composer.json'))->read();

        self::assertArrayHasKey('require', $composerJson);
        self::assertArrayHasKey('local/my-lib', $composerJson['require']);
    }

    public function testRequireLocalPathDryRunDoesNotWriteRepo(): void
    {
        $localDir = $this->createLocalPackage('local/dry-pkg', '1.0.0');

        $this->initTempComposer([
            'repositories' => [],
        ]);

        $appTester = $this->getApplicationTester();
        $appTester->run([
            'command' => 'require',
            '--dry-run' => true,
            '--no-audit' => true,
            '--no-install' => true,
            'packages' => [$localDir],
        ]);

        $composerJson = (new JsonFile('composer.json'))->read();

        // In dry-run mode, the path repository should not be persisted to composer.json
        // (the repo is only added in-memory for resolution)
        $hasPathRepo = false;
        if (isset($composerJson['repositories'])) {
            foreach ($composerJson['repositories'] as $repo) {
                if (isset($repo['type']) && $repo['type'] === 'path') {
                    $hasPathRepo = true;
                }
            }
        }
        self::assertFalse($hasPathRepo, 'Path repository should not be written to composer.json during dry-run');
    }

    public function testRequireLocalPathDoesNotDuplicateExistingRepo(): void
    {
        $localDir = $this->createLocalPackage('local/existing-pkg', '1.0.0');
        $normalizedDir = str_replace('\\', '/', $localDir);

        $this->initTempComposer([
            'repositories' => [
                'local/existing-pkg' => [
                    'type' => 'path',
                    'url' => $normalizedDir,
                ],
            ],
        ]);

        $appTester = $this->getApplicationTester();
        $appTester->run([
            'command' => 'require',
            '--no-audit' => true,
            '--no-install' => true,
            'packages' => [$localDir],
        ]);

        $composerJson = (new JsonFile('composer.json'))->read();

        // Count path repos pointing to our directory
        $count = 0;
        foreach ($composerJson['repositories'] as $repo) {
            if (isset($repo['type']) && $repo['type'] === 'path' && isset($repo['url']) && $repo['url'] === $normalizedDir) {
                $count++;
            }
        }
        self::assertSame(1, $count, 'Path repository should not be duplicated');
    }

    public function testRequireLocalPathMissingComposerJsonThrows(): void
    {
        $dir = self::getUniqueTmpDirectory();
        $this->localPkgDirs[] = $dir;
        // No composer.json created

        $this->initTempComposer();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('No composer.json found in');

        $appTester = $this->getApplicationTester();
        $appTester->run([
            'command' => 'require',
            '--no-audit' => true,
            'packages' => [$dir],
        ]);
    }

    public function testRequireLocalPathMissingNameThrows(): void
    {
        $dir = self::getUniqueTmpDirectory();
        $this->localPkgDirs[] = $dir;
        file_put_contents($dir.'/composer.json', json_encode(['description' => 'no name']));

        $this->initTempComposer();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Package name is not set in composer.json at');

        $appTester = $this->getApplicationTester();
        $appTester->run([
            'command' => 'require',
            '--no-audit' => true,
            'packages' => [$dir],
        ]);
    }

    public function testRequireLocalPathDryRunFullResolution(): void
    {
        $localDir = $this->createLocalPackage('local/dry-full-pkg', '1.0.0');

        $this->initTempComposer([
            'repositories' => [],
        ]);

        $appTester = $this->getApplicationTester();
        $appTester->run([
            'command' => 'require',
            '--dry-run' => true,
            '--no-audit' => true,
            'packages' => [$localDir],
        ]);

        $output = $appTester->getDisplay(true);
        // Should resolve and lock the package in dry-run mode
        self::assertStringContainsString('Locking local/dry-full-pkg', $output);

        // composer.json should not have the path repo persisted
        $composerJson = (new JsonFile('composer.json'))->read();
        $hasPathRepo = false;
        if (isset($composerJson['repositories'])) {
            foreach ($composerJson['repositories'] as $repo) {
                if (isset($repo['type']) && $repo['type'] === 'path') {
                    $hasPathRepo = true;
                }
            }
        }
        self::assertFalse($hasPathRepo, 'Path repository should not be written to composer.json during dry-run');
    }

    public function testNonPathPackagesPassedThrough(): void
    {
        $this->initTempComposer([
            'repositories' => [
                'packages' => [
                    'type' => 'package',
                    'package' => [
                        ['name' => 'vendor/normal-pkg', 'version' => '1.0.0'],
                    ],
                ],
            ],
        ]);

        $appTester = $this->getApplicationTester();
        $appTester->run([
            'command' => 'require',
            '--dry-run' => true,
            '--no-audit' => true,
            'packages' => ['vendor/normal-pkg'],
        ]);

        $output = $appTester->getDisplay(true);
        self::assertStringContainsString('Locking vendor/normal-pkg (1.0.0)', $output);
    }
}
