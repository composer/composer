<?php

namespace Composer\Package\Version;

use Composer\Config;
use Composer\Repository\Vcs\HgDriver;
use Composer\IO\NullIO;
use Composer\Util\Git as GitUtil;
use Composer\Util\ProcessExecutor;
use Composer\Util\Svn as SvnUtil;

class VersionGuesser
{
    /**
     * @var ProcessExecutor
     */
    private $process;

    /**
     * @var VersionParser
     */
    private $versionParser;

    /**
     * @var null|string
     */
    private $cwd;

    /**
     * @param ProcessExecutor $process
     * @param VersionParser   $versionParser
     * @param string          $cwd
     */
    public function __construct(ProcessExecutor $process, VersionParser $versionParser, $cwd = null)
    {
        $this->process = $process;
        $this->versionParser = $versionParser;
        $this->cwd = $cwd ?: getcwd();
    }

    public function guessVersion(Config $config, array $packageConfig)
    {
        if (function_exists('proc_open')) {
            $version = $this->guessGitVersion($packageConfig);
            if (null !== $version) {
                return $version;
            }

            $version = $this->guessHgVersion($config, $packageConfig);
            if (null !== $version) {
                return $version;
            }

            return $this->guessSvnVersion($packageConfig);
        }
    }

    private function guessGitVersion(array $config)
    {
        GitUtil::cleanEnv();

        // try to fetch current version from git tags
        if (0 === $this->process->execute('git describe --exact-match --tags', $output, $this->cwd)) {
            try {
                return $this->versionParser->normalize(trim($output));
            } catch (\Exception $e) {
            }
        }

        // try to fetch current version from git branch
        if (0 === $this->process->execute('git branch --no-color --no-abbrev -v', $output, $this->cwd)) {
            $branches = array();
            $isFeatureBranch = false;
            $version = null;

            // find current branch and collect all branch names
            foreach ($this->process->splitLines($output) as $branch) {
                if ($branch && preg_match('{^(?:\* ) *(\(no branch\)|\(detached from \S+\)|\S+) *([a-f0-9]+) .*$}', $branch, $match)) {
                    if ($match[1] === '(no branch)' || substr($match[1], 0, 10) === '(detached ') {
                        $version = 'dev-'.$match[2];
                        $isFeatureBranch = true;
                    } else {
                        $version = $this->versionParser->normalizeBranch($match[1]);
                        $isFeatureBranch = 0 === strpos($version, 'dev-');
                        if ('9999999-dev' === $version) {
                            $version = 'dev-'.$match[1];
                        }
                    }
                }

                if ($branch && !preg_match('{^ *[^/]+/HEAD }', $branch)) {
                    if (preg_match('{^(?:\* )? *(\S+) *([a-f0-9]+) .*$}', $branch, $match)) {
                        $branches[] = $match[1];
                    }
                }
            }

            if (!$isFeatureBranch) {
                return $version;
            }

            // try to find the best (nearest) version branch to assume this feature's version
            $version = $this->guessFeatureVersion($config, $version, $branches, 'git rev-list %candidate%..%branch%');

            return $version;
        }
    }

    private function guessHgVersion(Config $config, array $packageConfig)
    {
        // try to fetch current version from hg branch
        if (0 === $this->process->execute('hg branch', $output, $this->cwd)) {
            $branch = trim($output);
            $version = $this->versionParser->normalizeBranch($branch);
            $isFeatureBranch = 0 === strpos($version, 'dev-');

            if ('9999999-dev' === $version) {
                $version = 'dev-'.$branch;
            }

            if (!$isFeatureBranch) {
                return $version;
            }

            // re-use the HgDriver to fetch branches (this properly includes bookmarks)
            $packageConfig = array('url' => $this->cwd);
            $driver = new HgDriver($packageConfig, new NullIO(), $config, $this->process);
            $branches = array_keys($driver->getBranches());

            // try to find the best (nearest) version branch to assume this feature's version
            $version = $this->guessFeatureVersion($config, $version, $branches, 'hg log -r "not ancestors(\'%candidate%\') and ancestors(\'%branch%\')" --template "{node}\\n"');

            return $version;
        }
    }

    private function guessFeatureVersion(array $config, $version, array $branches, $scmCmdline)
    {
        // ignore feature branches if they have no branch-alias or self.version is used
        // and find the branch they came from to use as a version instead
        if ((isset($config['extra']['branch-alias']) && !isset($config['extra']['branch-alias'][$version]))
            || strpos(json_encode($config), '"self.version"')
        ) {
            $branch = preg_replace('{^dev-}', '', $version);
            $length = PHP_INT_MAX;

            $nonFeatureBranches = '';
            if (!empty($config['non-feature-branches'])) {
                $nonFeatureBranches = implode('|', $config['non-feature-branches']);
            }

            foreach ($branches as $candidate) {
                // return directly, if branch is configured to be non-feature branch
                if ($candidate === $branch && preg_match('{^(' . $nonFeatureBranches . ')$}', $candidate)) {
                    return $version;
                }

                // do not compare against other feature branches
                if ($candidate === $branch || !preg_match('{^(master|trunk|default|develop|\d+\..+)$}', $candidate, $match)) {
                    continue;
                }

                $cmdLine = str_replace(array('%candidate%', '%branch%'), array($candidate, $branch), $scmCmdline);
                if (0 !== $this->process->execute($cmdLine, $output, $this->cwd)) {
                    continue;
                }

                if (strlen($output) < $length) {
                    $length = strlen($output);
                    $version = $this->versionParser->normalizeBranch($candidate);
                    if ('9999999-dev' === $version) {
                        $version = 'dev-'.$match[1];
                    }
                }
            }
        }

        return $version;
    }

    private function guessSvnVersion(array $config)
    {
        SvnUtil::cleanEnv();

        // try to fetch current version from svn
        if (0 === $this->process->execute('svn info --xml', $output, $this->cwd)) {
            $trunkPath = isset($config['trunk-path']) ? preg_quote($config['trunk-path'], '#') : 'trunk';
            $branchesPath = isset($config['branches-path']) ? preg_quote($config['branches-path'], '#') : 'branches';
            $tagsPath = isset($config['tags-path']) ? preg_quote($config['tags-path'], '#') : 'tags';

            $urlPattern = '#<url>.*/('.$trunkPath.'|('.$branchesPath.'|'. $tagsPath .')/(.*))</url>#';

            if (preg_match($urlPattern, $output, $matches)) {
                if (isset($matches[2]) && ($branchesPath === $matches[2] || $tagsPath === $matches[2])) {
                    // we are in a branches path
                    $version = $this->versionParser->normalizeBranch($matches[3]);
                    if ('9999999-dev' === $version) {
                        $version = 'dev-'.$matches[3];
                    }

                    return $version;
                }

                return $this->versionParser->normalize(trim($matches[1]));
            }
        }
    }
}
