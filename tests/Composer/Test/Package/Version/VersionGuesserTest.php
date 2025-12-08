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

namespace Composer\Test\Package\Version;

use Composer\Config;
use Composer\Package\Version\VersionGuesser;
use Composer\Semver\VersionParser;
use Composer\Test\TestCase;
use Composer\Util\Git as GitUtil;
use Composer\Util\Platform;
use Composer\Util\ProcessExecutor;

class VersionGuesserTest extends TestCase
{
    public function setUp(): void
    {
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('proc_open() is not available');
        }
    }

    public function testHgGuessVersionReturnsData(): void
    {
        $branch = 'default';

        $process = $this->getProcessExecutorMock();
        $process->expects([
            ['cmd' => ['git', 'branch', '-a', '--no-color', '--no-abbrev', '-v'], 'return' => 128],
            ['cmd' => ['git', 'describe', '--exact-match', '--tags'], 'return' => 128],
            ['cmd' => array_merge(['git', 'rev-list', '--no-commit-header', '--format=%H', '-n1', 'HEAD'], GitUtil::getNoShowSignatureFlags($process)), 'return' => 128],
            ['cmd' => ['hg', 'branch'], 'return' => 0, 'stdout' => $branch],
            ['cmd' => ['hg', 'branches'], 'return' => 0],
            ['cmd' => ['hg', 'bookmarks'], 'return' => 0],
        ], true);

        GitUtil::getVersion(new ProcessExecutor);

        $config = new Config;
        $config->merge(['repositories' => ['packagist' => false]]);
        $guesser = new VersionGuesser($config, $process, new VersionParser());
        $versionArray = $guesser->guessVersion([], 'dummy/path');

        self::assertIsArray($versionArray);
        self::assertEquals("dev-".$branch, $versionArray['version']);
        self::assertEquals("dev-".$branch, $versionArray['pretty_version']);
        self::assertEmpty($versionArray['commit']);
    }

    public function testGuessVersionReturnsData(): void
    {
        $commitHash = '03a15d220da53c52eddd5f32ffca64a7b3801bea';
        $anotherCommitHash = '03a15d220da53c52eddd5f32ffca64a7b3801bea';

        $process = $this->getProcessExecutorMock();
        $process->expects([
            [
                'cmd' => ['git', 'branch', '-a', '--no-color', '--no-abbrev', '-v'],
                'stdout' => "* master $commitHash Commit message\n(no branch) $anotherCommitHash Commit message\n",
            ],
        ], true);

        $config = new Config;
        $config->merge(['repositories' => ['packagist' => false]]);
        $guesser = new VersionGuesser($config, $process, new VersionParser());
        $versionArray = $guesser->guessVersion([], 'dummy/path');

        self::assertIsArray($versionArray);
        self::assertEquals("dev-master", $versionArray['version']);
        self::assertEquals("dev-master", $versionArray['pretty_version']);
        self::assertArrayNotHasKey('feature_version', $versionArray);
        self::assertArrayNotHasKey('feature_pretty_version', $versionArray);
        self::assertEquals($commitHash, $versionArray['commit']);
    }

    public function testGuessVersionDoesNotSeeCustomDefaultBranchAsNonFeatureBranch(): void
    {
        $commitHash = '03a15d220da53c52eddd5f32ffca64a7b3801bea';
        $anotherCommitHash = '13a15d220da53c52eddd5f32ffca64a7b3801bea';

        $process = $this->getProcessExecutorMock();
        $process->expects([
            [
                'cmd' => ['git', 'branch', '-a', '--no-color', '--no-abbrev', '-v'],
                // Assumption here is that arbitrary would be the default branch
                'stdout' => "  arbitrary $commitHash Commit message\n* current $anotherCommitHash Another message\n",
            ],
        ], true);

        $config = new Config;
        $config->merge(['repositories' => ['packagist' => false]]);
        $guesser = new VersionGuesser($config, $process, new VersionParser());
        $versionArray = $guesser->guessVersion(['version' => 'self.version'], 'dummy/path');

        self::assertIsArray($versionArray);
        self::assertEquals("dev-current", $versionArray['version']);
        self::assertEquals($anotherCommitHash, $versionArray['commit']);
    }

    public function testGuessVersionReadsAndRespectsNonFeatureBranchesConfigurationForArbitraryNaming(): void
    {
        $commitHash = '03a15d220da53c52eddd5f32ffca64a7b3801bea';
        $anotherCommitHash = '13a15d220da53c52eddd5f32ffca64a7b3801bea';

        $process = $this->getProcessExecutorMock();
        $process->expects([
            [
                'cmd' => ['git', 'branch', '-a', '--no-color', '--no-abbrev', '-v'],
                'stdout' => "  arbitrary $commitHash Commit message\n* feature $anotherCommitHash Another message\n",
            ],
            [
                'cmd' => ['git', 'rev-list', 'arbitrary..feature'],
                'stdout' => "$anotherCommitHash\n",
            ],
        ], true);

        $config = new Config;
        $config->merge(['repositories' => ['packagist' => false]]);
        $guesser = new VersionGuesser($config, $process, new VersionParser());
        $versionArray = $guesser->guessVersion(['version' => 'self.version', 'non-feature-branches' => ['arbitrary']], 'dummy/path');

        self::assertIsArray($versionArray);
        self::assertEquals("dev-arbitrary", $versionArray['version']);
        self::assertEquals($anotherCommitHash, $versionArray['commit']);
        self::assertArrayHasKey('feature_version', $versionArray);
        self::assertEquals("dev-feature", $versionArray['feature_version']);
        self::assertEquals("dev-feature", $versionArray['feature_pretty_version']);
    }

    public function testGuessVersionReadsAndRespectsNonFeatureBranchesConfigurationForArbitraryNamingRegex(): void
    {
        $commitHash = '03a15d220da53c52eddd5f32ffca64a7b3801bea';
        $anotherCommitHash = '13a15d220da53c52eddd5f32ffca64a7b3801bea';

        $process = $this->getProcessExecutorMock();
        $process->expects([
            [
                'cmd' => ['git', 'branch', '-a', '--no-color', '--no-abbrev', '-v'],
                'stdout' => "  latest-testing $commitHash Commit message\n* feature $anotherCommitHash Another message\n",
            ],
            [
                'cmd' => ['git', 'rev-list', 'latest-testing..feature'],
                'stdout' => "$anotherCommitHash\n",
            ],
        ], true);

        $config = new Config;
        $config->merge(['repositories' => ['packagist' => false]]);
        $guesser = new VersionGuesser($config, $process, new VersionParser());
        $versionArray = $guesser->guessVersion(['version' => 'self.version', 'non-feature-branches' => ['latest-.*']], 'dummy/path');

        self::assertIsArray($versionArray);
        self::assertEquals("dev-latest-testing", $versionArray['version']);
        self::assertEquals($anotherCommitHash, $versionArray['commit']);
        self::assertArrayHasKey('feature_version', $versionArray);
        self::assertEquals("dev-feature", $versionArray['feature_version']);
        self::assertEquals("dev-feature", $versionArray['feature_pretty_version']);
    }

    public function testGuessVersionReadsAndRespectsNonFeatureBranchesConfigurationForArbitraryNamingWhenOnNonFeatureBranch(): void
    {
        $commitHash = '03a15d220da53c52eddd5f32ffca64a7b3801bea';
        $anotherCommitHash = '13a15d220da53c52eddd5f32ffca64a7b3801bea';

        $process = $this->getProcessExecutorMock();
        $process->expects([
            [
                'cmd' => ['git', 'branch', '-a', '--no-color', '--no-abbrev', '-v'],
                'stdout' => "* latest-testing $commitHash Commit message\n  current $anotherCommitHash Another message\n  master $anotherCommitHash Another message\n",
            ],
        ], true);

        $config = new Config;
        $config->merge(['repositories' => ['packagist' => false]]);
        $guesser = new VersionGuesser($config, $process, new VersionParser());
        $versionArray = $guesser->guessVersion(['version' => 'self.version', 'non-feature-branches' => ['latest-.*']], 'dummy/path');

        self::assertIsArray($versionArray);
        self::assertEquals("dev-latest-testing", $versionArray['version']);
        self::assertEquals($commitHash, $versionArray['commit']);
        self::assertArrayNotHasKey('feature_version', $versionArray);
        self::assertArrayNotHasKey('feature_pretty_version', $versionArray);
    }

    public function testDetachedHeadBecomesDevHash(): void
    {
        $commitHash = '03a15d220da53c52eddd5f32ffca64a7b3801bea';

        $process = $this->getProcessExecutorMock();
        $process->expects([
            [
                'cmd' => ['git', 'branch', '-a', '--no-color', '--no-abbrev', '-v'],
                'stdout' => "* (no branch) $commitHash Commit message\n",
            ],
            ['git', 'describe', '--exact-match', '--tags'],
        ], true);

        $config = new Config;
        $config->merge(['repositories' => ['packagist' => false]]);
        $guesser = new VersionGuesser($config, $process, new VersionParser());
        $versionData = $guesser->guessVersion([], 'dummy/path');

        self::assertIsArray($versionData);
        self::assertEquals("dev-$commitHash", $versionData['version']);
    }

    public function testDetachedFetchHeadBecomesDevHashGit2(): void
    {
        $commitHash = '03a15d220da53c52eddd5f32ffca64a7b3801bea';

        $process = $this->getProcessExecutorMock();
        $process->expects([
            [
                'cmd' => ['git', 'branch', '-a', '--no-color', '--no-abbrev', '-v'],
                'stdout' => "* (HEAD detached at FETCH_HEAD) $commitHash Commit message\n",
            ],
            ['git', 'describe', '--exact-match', '--tags'],
        ], true);

        $config = new Config;
        $config->merge(['repositories' => ['packagist' => false]]);
        $guesser = new VersionGuesser($config, $process, new VersionParser());
        $versionData = $guesser->guessVersion([], 'dummy/path');

        self::assertIsArray($versionData);
        self::assertEquals("dev-$commitHash", $versionData['version']);
    }

    public function testDetachedCommitHeadBecomesDevHashGit2(): void
    {
        $commitHash = '03a15d220da53c52eddd5f32ffca64a7b3801bea';

        $process = $this->getProcessExecutorMock();
        $process->expects([
            [
                'cmd' => ['git', 'branch', '-a', '--no-color', '--no-abbrev', '-v'],
                'stdout' => "* (HEAD detached at 03a15d220) $commitHash Commit message\n",
            ],
            ['git', 'describe', '--exact-match', '--tags'],
        ], true);

        $config = new Config;
        $config->merge(['repositories' => ['packagist' => false]]);
        $guesser = new VersionGuesser($config, $process, new VersionParser());
        $versionData = $guesser->guessVersion([], 'dummy/path');

        self::assertIsArray($versionData);
        self::assertEquals("dev-$commitHash", $versionData['version']);
    }

    public function testTagBecomesVersion(): void
    {
        $process = $this->getProcessExecutorMock();
        $process->expects([
            [
                'cmd' => ['git', 'branch', '-a', '--no-color', '--no-abbrev', '-v'],
                'stdout' => "* (HEAD detached at v2.0.5-alpha2) 433b98d4218c181bae01865901aac045585e8a1a Commit message\n",
            ],
            [
                'cmd' => ['git', 'describe', '--exact-match', '--tags'],
                'stdout' => "v2.0.5-alpha2",
            ],
        ], true);

        $config = new Config;
        $config->merge(['repositories' => ['packagist' => false]]);
        $guesser = new VersionGuesser($config, $process, new VersionParser());
        $versionData = $guesser->guessVersion([], 'dummy/path');

        self::assertIsArray($versionData);
        self::assertEquals("2.0.5.0-alpha2", $versionData['version']);
    }

    public function testTagBecomesPrettyVersion(): void
    {
        $process = $this->getProcessExecutorMock();
        $process->expects([
            [
                'cmd' => ['git', 'branch', '-a', '--no-color', '--no-abbrev', '-v'],
                'stdout' => "* (HEAD detached at 1.0.0) c006f0c12bbbf197b5c071ffb1c0e9812bb14a4d Commit message\n",
            ],
            [
                'cmd' => ['git', 'describe', '--exact-match', '--tags'],
                'stdout' => '1.0.0',
            ],
        ], true);

        $config = new Config;
        $config->merge(['repositories' => ['packagist' => false]]);
        $guesser = new VersionGuesser($config, $process, new VersionParser());
        $versionData = $guesser->guessVersion([], 'dummy/path');

        self::assertIsArray($versionData);
        self::assertEquals('1.0.0.0', $versionData['version']);
        self::assertEquals('1.0.0', $versionData['pretty_version']);
    }

    public function testInvalidTagBecomesVersion(): void
    {
        $process = $this->getProcessExecutorMock();
        $process->expects([
            [
                'cmd' => ['git', 'branch', '-a', '--no-color', '--no-abbrev', '-v'],
                'stdout' => "* foo 03a15d220da53c52eddd5f32ffca64a7b3801bea Commit message\n",
            ],
        ], true);

        $config = new Config;
        $config->merge(['repositories' => ['packagist' => false]]);
        $guesser = new VersionGuesser($config, $process, new VersionParser());
        $versionData = $guesser->guessVersion([], 'dummy/path');

        self::assertIsArray($versionData);
        self::assertEquals("dev-foo", $versionData['version']);
    }

    public function testNumericBranchesShowNicely(): void
    {
        $process = $this->getProcessExecutorMock();
        $process->expects([
            [
                'cmd' => ['git', 'branch', '-a', '--no-color', '--no-abbrev', '-v'],
                'stdout' => "* 1.5 03a15d220da53c52eddd5f32ffca64a7b3801bea Commit message\n",
            ],
        ], true);

        $config = new Config;
        $config->merge(['repositories' => ['packagist' => false]]);
        $guesser = new VersionGuesser($config, $process, new VersionParser());
        $versionData = $guesser->guessVersion([], 'dummy/path');

        self::assertIsArray($versionData);
        self::assertEquals("1.5.x-dev", $versionData['pretty_version']);
        self::assertEquals("1.5.9999999.9999999-dev", $versionData['version']);
    }

    public function testRemoteBranchesAreSelected(): void
    {
        $process = $this->getProcessExecutorMock();
        $process->expects([
            [
                'cmd' => ['git', 'branch', '-a', '--no-color', '--no-abbrev', '-v'],
                'stdout' => "* feature-branch 03a15d220da53c52eddd5f32ffca64a7b3801bea Commit message\n".
                        "remotes/origin/1.5 03a15d220da53c52eddd5f32ffca64a7b3801bea Commit message\n",
            ],
            [
                'cmd' => ['git', 'rev-list', 'remotes/origin/1.5..feature-branch'],
                'stdout' => "\n",
            ],
        ], true);

        $config = new Config;
        $config->merge(['repositories' => ['packagist' => false]]);
        $guesser = new VersionGuesser($config, $process, new VersionParser());
        $versionData = $guesser->guessVersion(['version' => 'self.version'], 'dummy/path');
        self::assertIsArray($versionData);
        self::assertEquals("1.5.x-dev", $versionData['pretty_version']);
        self::assertEquals("1.5.9999999.9999999-dev", $versionData['version']);
    }

    /**
     * @dataProvider rootEnvVersionsProvider
     */
    public function testGetRootVersionFromEnv(string $env, string $expectedVersion): void
    {
        Platform::putEnv('COMPOSER_ROOT_VERSION', $env);
        $guesser = new VersionGuesser(new Config, $this->getProcessExecutorMock(), new VersionParser());
        self::assertSame($expectedVersion, $guesser->getRootVersionFromEnv());
        Platform::clearEnv('COMPOSER_ROOT_VERSION');
    }

    /**
     * @return array<array{string, string}>
     */
    public function rootEnvVersionsProvider(): array
    {
        return [
            ['1.0-dev', '1.0.x-dev'],
            ['1.0.x-dev', '1.0.x-dev'],
            ['1-dev', '1.x-dev'],
            ['1.x-dev', '1.x-dev'],
            ['1.0.0', '1.0.0'],
        ];
    }
}
