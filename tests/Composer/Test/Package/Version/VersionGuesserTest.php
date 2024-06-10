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

namespace Composer\Test\Package\Version;

use Composer\Config;
use Composer\Package\Version\VersionGuesser;
use Composer\Semver\VersionParser;
use Composer\Test\TestCase;
use Composer\Util\Git as GitUtil;
use Composer\Util\ProcessExecutor;
use Composer\Test\Mock\ProcessExecutorMock;

class VersionGuesserTest extends TestCase
{
    public function setUp()
    {
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('proc_open() is not available');
        }
    }

    public function testHgGuessVersionReturnsData()
    {
        $branch = 'default';

        $process = new ProcessExecutorMock;
        $process->expects(array(
            array('cmd' => 'git branch -a --no-color --no-abbrev -v', 'return' => 128),
            array('cmd' => 'git describe --exact-match --tags', 'return' => 128),
            array('cmd' => 'git log --pretty="%H" -n1 HEAD'.GitUtil::getNoShowSignatureFlag($process), 'return' => 128),
            array('cmd' => 'hg branch', 'return' => 0, 'stdout' => $branch),
            array('cmd' => 'hg branches', 'return' => 0),
            array('cmd' => 'hg bookmarks', 'return' => 0),
        ), true);

        GitUtil::getVersion(new ProcessExecutor);

        $config = new Config;
        $config->merge(array('repositories' => array('packagist' => false)));
        $guesser = new VersionGuesser($config, $process, new VersionParser());
        $versionArray = $guesser->guessVersion(array(), 'dummy/path');

        $this->assertEquals("dev-".$branch, $versionArray['version']);
        $this->assertEquals("dev-".$branch, $versionArray['pretty_version']);
        $this->assertEmpty($versionArray['commit']);

        $process->assertComplete($this);
    }

    public function testGuessVersionReturnsData()
    {
        $commitHash = '03a15d220da53c52eddd5f32ffca64a7b3801bea';
        $anotherCommitHash = '03a15d220da53c52eddd5f32ffca64a7b3801bea';

        $process = new ProcessExecutorMock;
        $process->expects(array(
            array(
                'cmd' => 'git branch -a --no-color --no-abbrev -v',
                'stdout' => "* master $commitHash Commit message\n(no branch) $anotherCommitHash Commit message\n",
            ),
        ), true);

        $config = new Config;
        $config->merge(array('repositories' => array('packagist' => false)));
        $guesser = new VersionGuesser($config, $process, new VersionParser());
        $versionArray = $guesser->guessVersion(array(), 'dummy/path');

        $this->assertEquals("dev-master", $versionArray['version']);
        $this->assertEquals("dev-master", $versionArray['pretty_version']);
        $this->assertArrayNotHasKey('feature_version', $versionArray);
        $this->assertArrayNotHasKey('feature_pretty_version', $versionArray);
        $this->assertEquals($commitHash, $versionArray['commit']);

        $process->assertComplete($this);
    }

    public function testGuessVersionDoesNotSeeCustomDefaultBranchAsNonFeatureBranch()
    {
        $commitHash = '03a15d220da53c52eddd5f32ffca64a7b3801bea';
        $anotherCommitHash = '13a15d220da53c52eddd5f32ffca64a7b3801bea';

        $process = new ProcessExecutorMock;
        $process->expects(array(
            array(
                'cmd' => 'git branch -a --no-color --no-abbrev -v',
                // Assumption here is that arbitrary would be the default branch
                'stdout' => "  arbitrary $commitHash Commit message\n* current $anotherCommitHash Another message\n",
            ),
        ), true);

        $config = new Config;
        $config->merge(array('repositories' => array('packagist' => false)));
        $guesser = new VersionGuesser($config, $process, new VersionParser());
        $versionArray = $guesser->guessVersion(array('version' => 'self.version'), 'dummy/path');

        $this->assertEquals("dev-current", $versionArray['version']);
        $this->assertEquals($anotherCommitHash, $versionArray['commit']);

        $process->assertComplete($this);
    }

    public function testGuessVersionReadsAndRespectsNonFeatureBranchesConfigurationForArbitraryNaming()
    {
        $commitHash = '03a15d220da53c52eddd5f32ffca64a7b3801bea';
        $anotherCommitHash = '13a15d220da53c52eddd5f32ffca64a7b3801bea';

        $process = new ProcessExecutorMock;
        $process->expects(array(
            array(
                'cmd' => 'git branch -a --no-color --no-abbrev -v',
                'stdout' => "  arbitrary $commitHash Commit message\n* feature $anotherCommitHash Another message\n",
            ),
            array(
                'cmd' => 'git rev-list -- arbitrary..feature',
                'stdout' => "$anotherCommitHash\n",
            ),
        ), true);

        $config = new Config;
        $config->merge(array('repositories' => array('packagist' => false)));
        $guesser = new VersionGuesser($config, $process, new VersionParser());
        $versionArray = $guesser->guessVersion(array('version' => 'self.version', 'non-feature-branches' => array('arbitrary')), 'dummy/path');

        $this->assertEquals("dev-arbitrary", $versionArray['version']);
        $this->assertEquals($anotherCommitHash, $versionArray['commit']);
        $this->assertEquals("dev-feature", $versionArray['feature_version']);
        $this->assertEquals("dev-feature", $versionArray['feature_pretty_version']);

        $process->assertComplete($this);
    }

    public function testGuessVersionReadsAndRespectsNonFeatureBranchesConfigurationForArbitraryNamingRegex()
    {
        $commitHash = '03a15d220da53c52eddd5f32ffca64a7b3801bea';
        $anotherCommitHash = '13a15d220da53c52eddd5f32ffca64a7b3801bea';

        $process = new ProcessExecutorMock;
        $process->expects(array(
            array(
                'cmd' => 'git branch -a --no-color --no-abbrev -v',
                'stdout' => "  latest-testing $commitHash Commit message\n* feature $anotherCommitHash Another message\n",
            ),
            array(
                'cmd' => 'git rev-list -- latest-testing..feature',
                'stdout' => "$anotherCommitHash\n",
            ),
        ), true);

        $config = new Config;
        $config->merge(array('repositories' => array('packagist' => false)));
        $guesser = new VersionGuesser($config, $process, new VersionParser());
        $versionArray = $guesser->guessVersion(array('version' => 'self.version', 'non-feature-branches' => array('latest-.*')), 'dummy/path');

        $this->assertEquals("dev-latest-testing", $versionArray['version']);
        $this->assertEquals($anotherCommitHash, $versionArray['commit']);
        $this->assertEquals("dev-feature", $versionArray['feature_version']);
        $this->assertEquals("dev-feature", $versionArray['feature_pretty_version']);

        $process->assertComplete($this);
    }

    public function testGuessVersionReadsAndRespectsNonFeatureBranchesConfigurationForArbitraryNamingWhenOnNonFeatureBranch()
    {
        $commitHash = '03a15d220da53c52eddd5f32ffca64a7b3801bea';
        $anotherCommitHash = '13a15d220da53c52eddd5f32ffca64a7b3801bea';

        $process = new ProcessExecutorMock;
        $process->expects(array(
            array(
                'cmd' => 'git branch -a --no-color --no-abbrev -v',
                'stdout' => "* latest-testing $commitHash Commit message\n  current $anotherCommitHash Another message\n  master $anotherCommitHash Another message\n",
            ),
        ), true);

        $config = new Config;
        $config->merge(array('repositories' => array('packagist' => false)));
        $guesser = new VersionGuesser($config, $process, new VersionParser());
        $versionArray = $guesser->guessVersion(array('version' => 'self.version', 'non-feature-branches' => array('latest-.*')), 'dummy/path');

        $this->assertEquals("dev-latest-testing", $versionArray['version']);
        $this->assertEquals($commitHash, $versionArray['commit']);
        $this->assertArrayNotHasKey('feature_version', $versionArray);
        $this->assertArrayNotHasKey('feature_pretty_version', $versionArray);

        $process->assertComplete($this);
    }

    public function testDetachedHeadBecomesDevHash()
    {
        $commitHash = '03a15d220da53c52eddd5f32ffca64a7b3801bea';

        $process = new ProcessExecutorMock;
        $process->expects(array(
            array(
                'cmd' => 'git branch -a --no-color --no-abbrev -v',
                'stdout' => "* (no branch) $commitHash Commit message\n",
            ),
            'git describe --exact-match --tags',
        ), true);

        $config = new Config;
        $config->merge(array('repositories' => array('packagist' => false)));
        $guesser = new VersionGuesser($config, $process, new VersionParser());
        $versionData = $guesser->guessVersion(array(), 'dummy/path');

        $this->assertEquals("dev-$commitHash", $versionData['version']);

        $process->assertComplete($this);
    }

    public function testDetachedFetchHeadBecomesDevHashGit2()
    {
        $commitHash = '03a15d220da53c52eddd5f32ffca64a7b3801bea';

        $process = new ProcessExecutorMock;
        $process->expects(array(
            array(
                'cmd' => 'git branch -a --no-color --no-abbrev -v',
                'stdout' => "* (HEAD detached at FETCH_HEAD) $commitHash Commit message\n",
            ),
            'git describe --exact-match --tags',
        ), true);

        $config = new Config;
        $config->merge(array('repositories' => array('packagist' => false)));
        $guesser = new VersionGuesser($config, $process, new VersionParser());
        $versionData = $guesser->guessVersion(array(), 'dummy/path');

        $this->assertEquals("dev-$commitHash", $versionData['version']);

        $process->assertComplete($this);
    }

    public function testDetachedCommitHeadBecomesDevHashGit2()
    {
        $commitHash = '03a15d220da53c52eddd5f32ffca64a7b3801bea';

        $process = new ProcessExecutorMock;
        $process->expects(array(
            array(
                'cmd' => 'git branch -a --no-color --no-abbrev -v',
                'stdout' => "* (HEAD detached at 03a15d220) $commitHash Commit message\n",
            ),
            'git describe --exact-match --tags',
        ), true);

        $config = new Config;
        $config->merge(array('repositories' => array('packagist' => false)));
        $guesser = new VersionGuesser($config, $process, new VersionParser());
        $versionData = $guesser->guessVersion(array(), 'dummy/path');

        $this->assertEquals("dev-$commitHash", $versionData['version']);

        $process->assertComplete($this);
    }

    public function testTagBecomesVersion()
    {
        $process = new ProcessExecutorMock;
        $process->expects(array(
            array(
                'cmd' => 'git branch -a --no-color --no-abbrev -v',
                'stdout' => "* (HEAD detached at v2.0.5-alpha2) 433b98d4218c181bae01865901aac045585e8a1a Commit message\n",
            ),
            array(
                'cmd' => 'git describe --exact-match --tags',
                'stdout' => "v2.0.5-alpha2",
            ),
        ), true);

        $config = new Config;
        $config->merge(array('repositories' => array('packagist' => false)));
        $guesser = new VersionGuesser($config, $process, new VersionParser());
        $versionData = $guesser->guessVersion(array(), 'dummy/path');

        $this->assertEquals("2.0.5.0-alpha2", $versionData['version']);

        $process->assertComplete($this);
    }

    public function testTagBecomesPrettyVersion()
    {
        $process = new ProcessExecutorMock;
        $process->expects(array(
            array(
                'cmd' => 'git branch -a --no-color --no-abbrev -v',
                'stdout' => "* (HEAD detached at 1.0.0) c006f0c12bbbf197b5c071ffb1c0e9812bb14a4d Commit message\n",
            ),
            array(
                'cmd' => 'git describe --exact-match --tags',
                'stdout' => '1.0.0',
            ),
        ), true);

        $config = new Config;
        $config->merge(array('repositories' => array('packagist' => false)));
        $guesser = new VersionGuesser($config, $process, new VersionParser());
        $versionData = $guesser->guessVersion(array(), 'dummy/path');

        $this->assertEquals('1.0.0.0', $versionData['version']);
        $this->assertEquals('1.0.0', $versionData['pretty_version']);

        $process->assertComplete($this);
    }

    public function testInvalidTagBecomesVersion()
    {
        $process = new ProcessExecutorMock;
        $process->expects(array(
            array(
                'cmd' => 'git branch -a --no-color --no-abbrev -v',
                'stdout' => "* foo 03a15d220da53c52eddd5f32ffca64a7b3801bea Commit message\n",
            ),
        ), true);

        $config = new Config;
        $config->merge(array('repositories' => array('packagist' => false)));
        $guesser = new VersionGuesser($config, $process, new VersionParser());
        $versionData = $guesser->guessVersion(array(), 'dummy/path');

        $this->assertEquals("dev-foo", $versionData['version']);

        $process->assertComplete($this);
    }

    public function testNumericBranchesShowNicely()
    {
        $process = new ProcessExecutorMock;
        $process->expects(array(
            array(
                'cmd' => 'git branch -a --no-color --no-abbrev -v',
                'stdout' => "* 1.5 03a15d220da53c52eddd5f32ffca64a7b3801bea Commit message\n",
            ),
        ), true);

        $config = new Config;
        $config->merge(array('repositories' => array('packagist' => false)));
        $guesser = new VersionGuesser($config, $process, new VersionParser());
        $versionData = $guesser->guessVersion(array(), 'dummy/path');

        $this->assertEquals("1.5.x-dev", $versionData['pretty_version']);
        $this->assertEquals("1.5.9999999.9999999-dev", $versionData['version']);

        $process->assertComplete($this);
    }

    public function testRemoteBranchesAreSelected()
    {
        $process = new ProcessExecutorMock;
        $process->expects(array(
            array(
                'cmd' => 'git branch -a --no-color --no-abbrev -v',
                'stdout' => "* feature-branch 03a15d220da53c52eddd5f32ffca64a7b3801bea Commit message\n".
                        "remotes/origin/1.5 03a15d220da53c52eddd5f32ffca64a7b3801bea Commit message\n",
            ),
            array(
                'cmd' => 'git rev-list -- remotes/origin/1.5..feature-branch',
                'stdout' => "\n",
            ),
        ), true);

        $config = new Config;
        $config->merge(array('repositories' => array('packagist' => false)));
        $guesser = new VersionGuesser($config, $process, new VersionParser());
        $versionData = $guesser->guessVersion(array('version' => 'self.version'), 'dummy/path');
        $this->assertEquals("1.5.x-dev", $versionData['pretty_version']);
        $this->assertEquals("1.5.9999999.9999999-dev", $versionData['version']);

        $process->assertComplete($this);
    }
}
