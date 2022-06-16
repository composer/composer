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

namespace Composer\Package\Version;

use Composer\Config;
use Composer\Pcre\Preg;
use Composer\Repository\Vcs\HgDriver;
use Composer\IO\NullIO;
use Composer\Semver\VersionParser as SemverVersionParser;
use Composer\Util\Git as GitUtil;
use Composer\Util\HttpDownloader;
use Composer\Util\Platform;
use Composer\Util\ProcessExecutor;
use Composer\Util\Svn as SvnUtil;
use React\Promise\CancellablePromiseInterface;
use Symfony\Component\Process\Process;

/**
 * Try to guess the current version number based on different VCS configuration.
 *
 * @author Jordi Boggiano <j.boggiano@seld.be>
 * @author Samuel Roze <samuel.roze@gmail.com>
 *
 * @phpstan-type Version array{version: string, commit: string|null, pretty_version: string|null, feature_version?: string|null, feature_pretty_version?: string|null}
 */
class VersionGuesser
{
    /**
     * @var Config
     */
    private $config;

    /**
     * @var ProcessExecutor
     */
    private $process;

    /**
     * @var SemverVersionParser
     */
    private $versionParser;

    /**
     * @param Config              $config
     * @param ProcessExecutor     $process
     * @param SemverVersionParser $versionParser
     */
    public function __construct(Config $config, ProcessExecutor $process, SemverVersionParser $versionParser)
    {
        $this->config = $config;
        $this->process = $process;
        $this->versionParser = $versionParser;
    }

    /**
     * @param array<string, mixed> $packageConfig
     * @param string               $path Path to guess into
     *
     * @return array|null
     * @phpstan-return Version|null
     */
    public function guessVersion(array $packageConfig, string $path): ?array
    {
        if (!function_exists('proc_open')) {
            return null;
        }

        // bypass version guessing in bash completions as it takes time to create
        // new processes and the root version is usually not that important
        if (Platform::isInputCompletionProcess()) {
            return null;
        }

        $versionData = $this->guessGitVersion($packageConfig, $path);
        if (null !== $versionData && null !== $versionData['version']) {
            return $this->postprocess($versionData);
        }

        $versionData = $this->guessHgVersion($packageConfig, $path);
        if (null !== $versionData && null !== $versionData['version']) {
            return $this->postprocess($versionData);
        }

        $versionData = $this->guessFossilVersion($path);
        if (null !== $versionData && null !== $versionData['version']) {
            return $this->postprocess($versionData);
        }

        $versionData = $this->guessSvnVersion($packageConfig, $path);
        if (null !== $versionData && null !== $versionData['version']) {
            return $this->postprocess($versionData);
        }

        return null;
    }

    /**
     * @param array $versionData
     *
     * @phpstan-param Version $versionData
     *
     * @return array
     * @phpstan-return Version
     */
    private function postprocess(array $versionData): array
    {
        if (!empty($versionData['feature_version']) && $versionData['feature_version'] === $versionData['version'] && $versionData['feature_pretty_version'] === $versionData['pretty_version']) {
            unset($versionData['feature_version'], $versionData['feature_pretty_version']);
        }

        if ('-dev' === substr($versionData['version'], -4) && Preg::isMatch('{\.9{7}}', $versionData['version'])) {
            $versionData['pretty_version'] = Preg::replace('{(\.9{7})+}', '.x', $versionData['version']);
        }

        if (!empty($versionData['feature_version']) && '-dev' === substr($versionData['feature_version'], -4) && Preg::isMatch('{\.9{7}}', $versionData['feature_version'])) {
            $versionData['feature_pretty_version'] = Preg::replace('{(\.9{7})+}', '.x', $versionData['feature_version']);
        }

        return $versionData;
    }

    /**
     * @param array<string, mixed> $packageConfig
     * @param string               $path
     *
     * @return array{version: string|null, commit: string|null, pretty_version: string|null, feature_version?: string|null, feature_pretty_version?: string|null}
     */
    private function guessGitVersion(array $packageConfig, string $path): array
    {
        GitUtil::cleanEnv();
        $commit = null;
        $version = null;
        $prettyVersion = null;
        $featureVersion = null;
        $featurePrettyVersion = null;
        $isDetached = false;

        // try to fetch current version from git branch
        if (0 === $this->process->execute(['git', 'branch', '-a', '--no-color', '--no-abbrev', '-v'], $output, $path)) {
            $branches = array();
            $isFeatureBranch = false;

            // find current branch and collect all branch names
            foreach ($this->process->splitLines($output) as $branch) {
                if ($branch && Preg::isMatch('{^(?:\* ) *(\(no branch\)|\(detached from \S+\)|\(HEAD detached at \S+\)|\S+) *([a-f0-9]+) .*$}', $branch, $match)) {
                    if (
                        $match[1] === '(no branch)'
                        || str_starts_with($match[1], '(detached ')  
                        || str_starts_with($match[1], '(HEAD detached at')  
                    ) {
                        $version = 'dev-' . $match[2];
                        $prettyVersion = $version;
                        $isFeatureBranch = true;
                        $isDetached = true;
                    } else {
                        $version = $this->versionParser->normalizeBranch($match[1]);
                        $prettyVersion = 'dev-' . $match[1];
                        $isFeatureBranch = $this->isFeatureBranch($packageConfig, $match[1]);
                    }

                    if ($match[2]) {
                        $commit = $match[2];
                    }
                }

                if ($branch && !Preg::isMatch('{^ *.+/HEAD }', $branch)) {
                    if (Preg::isMatch('{^(?:\* )? *((?:remotes/(?:origin|upstream)/)?[^\s/]+) *([a-f0-9]+) .*$}', $branch, $match)) {
                        $branches[] = $match[1];
                    }
                }
            }

            if ($isFeatureBranch) {
                $featureVersion = $version;
                $featurePrettyVersion = $prettyVersion;

                // try to find the best (nearest) version branch to assume this feature's version
                $result = $this->guessFeatureVersion($packageConfig, $version, $branches, 'git rev-list %candidate%..%branch%', $path);
                $version = $result['version'];
                $prettyVersion = $result['pretty_version'];
            }
        }

        if (!$version || $isDetached) {
            $result = $this->versionFromGitTags($path);
            if ($result) {
                $version = $result['version'];
                $prettyVersion = $result['pretty_version'];
                $featureVersion = null;
                $featurePrettyVersion = null;
            }
        }

        if (!$commit) {
            $command = 'git log --pretty="%H" -n1 HEAD'.GitUtil::getNoShowSignatureFlag($this->process);
            if (0 === $this->process->execute($command, $output, $path)) {
                $commit = trim($output) ?: null;
            }
        }

        if ($featureVersion) {
            return array('version' => $version, 'commit' => $commit, 'pretty_version' => $prettyVersion, 'feature_version' => $featureVersion, 'feature_pretty_version' => $featurePrettyVersion);
        }

        return array('version' => $version, 'commit' => $commit, 'pretty_version' => $prettyVersion);
    }

    /**
     * @param string $path
     *
     * @return array{version: string, pretty_version: string}|null
     */
    private function versionFromGitTags(string $path): ?array
    {
        // try to fetch current version from git tags
        if (0 === $this->process->execute('git describe --exact-match --tags', $output, $path)) {
            try {
                $version = $this->versionParser->normalize(trim($output));

                return array('version' => $version, 'pretty_version' => trim($output));
            } catch (\Exception $e) {
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $packageConfig
     * @param string               $path
     *
     * @return array{version: string|null, commit: ''|null, pretty_version: string|null, feature_version?: string|null, feature_pretty_version?: string|null}|null
     */
    private function guessHgVersion(array $packageConfig, string $path): ?array
    {
        // try to fetch current version from hg branch
        if (0 === $this->process->execute('hg branch', $output, $path)) {
            $branch = trim($output);
            $version = $this->versionParser->normalizeBranch($branch);
            $isFeatureBranch =   str_starts_with($version, 'dev-');

            if (VersionParser::DEFAULT_BRANCH_ALIAS === $version) {
                return array('version' => $version, 'commit' => null, 'pretty_version' => 'dev-'.$branch);
            }

            if (!$isFeatureBranch) {
                return array('version' => $version, 'commit' => null, 'pretty_version' => $version);
            }

            // re-use the HgDriver to fetch branches (this properly includes bookmarks)
            $io = new NullIO();
            $driver = new HgDriver(array('url' => $path), $io, $this->config, new HttpDownloader($io, $this->config), $this->process);
            $branches = array_map('strval', array_keys($driver->getBranches()));

            // try to find the best (nearest) version branch to assume this feature's version
            $result = $this->guessFeatureVersion($packageConfig, $version, $branches, 'hg log -r "not ancestors(\'%candidate%\') and ancestors(\'%branch%\')" --template "{node}\\n"', $path);
            $result['commit'] = '';
            $result['feature_version'] = $version;
            $result['feature_pretty_version'] = $version;

            return $result;
        }

        return null;
    }

    /**
     * @param array<string, mixed>     $packageConfig
     * @param string|null              $version
     * @param string[]                 $branches
     * @param string                   $scmCmdline
     * @param string                   $path
     *
     * @phpstan-param non-empty-string $scmCmdline
     *
     * @return array{version: string|null, pretty_version: string|null}
     */
    private function guessFeatureVersion(array $packageConfig, ?string $version, array $branches, string $scmCmdline, string $path): array
    {
        $prettyVersion = $version;

        // ignore feature branches if they have no branch-alias or self.version is used
        // and find the branch they came from to use as a version instead
        if (!isset($packageConfig['extra']['branch-alias'][$version])
            || strpos(json_encode($packageConfig), '"self.version"')
        ) {
            $branch = Preg::replace('{^dev-}', '', $version);
            $length = PHP_INT_MAX;

            // return directly, if branch is configured to be non-feature branch
            if (!$this->isFeatureBranch($packageConfig, $branch)) {
                return array('version' => $version, 'pretty_version' => $prettyVersion);
            }

            // sort local branches first then remote ones
            // and sort numeric branches below named ones, to make sure if the branch has the same distance from main and 1.10 and 1.9 for example, main is picked
            // and sort using natural sort so that 1.10 will appear before 1.9
            usort($branches, function ($a, $b): int {
                $aRemote =   str_starts_with($a, 'remotes/');
                $bRemote =   str_starts_with($b, 'remotes/');

                if ($aRemote !== $bRemote) {
                    return $aRemote ? 1 : -1;
                }

                return strnatcasecmp($b, $a);
            });

            $promises = [];
            $this->process->setMaxJobs(30);
            try {
                foreach ($branches as $candidate) {
                    $candidateVersion = Preg::replace('{^remotes/\S+/}', '', $candidate);

                    // do not compare against itself or other feature branches
                    if ($candidate === $branch || $this->isFeatureBranch($packageConfig, $candidateVersion)) {
                        continue;
                    }

                    $cmdLine = str_replace(array('%candidate%', '%branch%'), array($candidate, $branch), $scmCmdline);
                    $promises[] = $this->process->executeAsync($cmdLine, $path)->then(function (Process $process) use (&$length, &$version, &$prettyVersion, $candidateVersion, &$promises): void {
                        if (!$process->isSuccessful()) {
                            return;
                        }

                        $output = $process->getOutput();
                        if (strlen($output) < $length) {
                            $length = strlen($output);
                            $version = $this->versionParser->normalizeBranch($candidateVersion);
                            $prettyVersion = 'dev-' . $candidateVersion;
                            if ($length === 0) {
                                foreach ($promises as $promise) {
                                    if ($promise instanceof CancellablePromiseInterface) {
                                        $promise->cancel();
                                    }
                                }
                            }
                        }
                    });
                }

                $this->process->wait();
            } finally {
                $this->process->resetMaxJobs();
            }
        }

        return array('version' => $version, 'pretty_version' => $prettyVersion);
    }

    /**
     * @param array<string, mixed> $packageConfig
     * @param string|null          $branchName
     *
     * @return bool
     */
    private function isFeatureBranch(array $packageConfig, ?string $branchName): bool
    {
        $nonFeatureBranches = '';
        if (!empty($packageConfig['non-feature-branches'])) {
            $nonFeatureBranches = implode('|', $packageConfig['non-feature-branches']);
        }

        return !Preg::isMatch('{^(' . $nonFeatureBranches . '|master|main|latest|next|current|support|tip|trunk|default|develop|\d+\..+)$}', $branchName, $match);
    }

    /**
     * @param string $path
     *
     * @return array{version: string|null, commit: '', pretty_version: string|null}
     */
    private function guessFossilVersion(string $path): array
    {
        $version = null;
        $prettyVersion = null;

        // try to fetch current version from fossil
        if (0 === $this->process->execute('fossil branch list', $output, $path)) {
            $branch = trim($output);
            $version = $this->versionParser->normalizeBranch($branch);
            $prettyVersion = 'dev-' . $branch;
        }

        // try to fetch current version from fossil tags
        if (0 === $this->process->execute('fossil tag list', $output, $path)) {
            try {
                $version = $this->versionParser->normalize(trim($output));
                $prettyVersion = trim($output);
            } catch (\Exception $e) {
            }
        }

        return array('version' => $version, 'commit' => '', 'pretty_version' => $prettyVersion);
    }

    /**
     * @param array<string, mixed> $packageConfig
     * @param string               $path
     *
     * @return array{version: string, commit: '', pretty_version: string}|null
     */
    private function guessSvnVersion(array $packageConfig, string $path): ?array
    {
        SvnUtil::cleanEnv();

        // try to fetch current version from svn
        if (0 === $this->process->execute('svn info --xml', $output, $path)) {
            $trunkPath = isset($packageConfig['trunk-path']) ? preg_quote($packageConfig['trunk-path'], '#') : 'trunk';
            $branchesPath = isset($packageConfig['branches-path']) ? preg_quote($packageConfig['branches-path'], '#') : 'branches';
            $tagsPath = isset($packageConfig['tags-path']) ? preg_quote($packageConfig['tags-path'], '#') : 'tags';

            $urlPattern = '#<url>.*/(' . $trunkPath . '|(' . $branchesPath . '|' . $tagsPath . ')/(.*))</url>#';

            if (Preg::isMatch($urlPattern, $output, $matches)) {
                if (isset($matches[2]) && ($branchesPath === $matches[2] || $tagsPath === $matches[2])) {
                    // we are in a branches path
                    $version = $this->versionParser->normalizeBranch($matches[3]);
                    $prettyVersion = 'dev-' . $matches[3];

                    return array('version' => $version, 'commit' => '', 'pretty_version' => $prettyVersion);
                }

                $prettyVersion = trim($matches[1]);
                if ($prettyVersion === 'trunk') {
                    $version = 'dev-trunk';
                } else {
                    $version = $this->versionParser->normalize($prettyVersion);
                }

                return array('version' => $version, 'commit' => '', 'pretty_version' => $prettyVersion);
            }
        }

        return null;
    }
}
