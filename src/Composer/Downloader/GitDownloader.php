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

namespace Composer\Downloader;

use Composer\Package\PackageInterface;
use Composer\Util\Git as GitUtil;
use Composer\Util\ProcessExecutor;
use Composer\IO\IOInterface;
use Composer\Util\Filesystem;
use Composer\Config;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class GitDownloader extends VcsDownloader
{
    private $hasStashedChanges = false;
    private $hasDiscardedChanges = false;
    private $gitUtil;

    public function __construct(IOInterface $io, Config $config, ProcessExecutor $process = null, Filesystem $fs = null)
    {
        parent::__construct($io, $config, $process, $fs);
        $this->gitUtil = new GitUtil($this->io, $this->config, $this->process, $this->filesystem);
    }

    /**
     * {@inheritDoc}
     */
    public function doDownload(PackageInterface $package, $path, $url)
    {
        GitUtil::cleanEnv();
        $path = $this->normalizePath($path);

        $ref = $package->getSourceReference();
        $flag = defined('PHP_WINDOWS_VERSION_MAJOR') ? '/D ' : '';
        $command = 'git clone --no-checkout %s %s && cd '.$flag.'%2$s && git remote add composer %1$s && git fetch composer';
        $this->io->writeError("    Cloning ".$ref);

        $commandCallable = function ($url) use ($ref, $path, $command) {
            return sprintf($command, ProcessExecutor::escape($url), ProcessExecutor::escape($path), ProcessExecutor::escape($ref));
        };

        $this->gitUtil->runCommand($commandCallable, $url, $path, true);
        if ($url !== $package->getSourceUrl()) {
            $url = $package->getSourceUrl();
            $this->process->execute(sprintf('git remote set-url origin %s', ProcessExecutor::escape($url)), $output, $path);
        }
        $this->setPushUrl($path, $url);

        if ($newRef = $this->updateToCommit($path, $ref, $package->getPrettyVersion(), $package->getReleaseDate())) {
            if ($package->getDistReference() === $package->getSourceReference()) {
                $package->setDistReference($newRef);
            }
            $package->setSourceReference($newRef);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function doUpdate(PackageInterface $initial, PackageInterface $target, $path, $url)
    {
        GitUtil::cleanEnv();
        $path = $this->normalizePath($path);
        if (!is_dir($path.'/.git')) {
            throw new \RuntimeException('The .git directory is missing from '.$path.', see https://getcomposer.org/commit-deps for more information');
        }

        $ref = $target->getSourceReference();
        $this->io->writeError("    Checking out ".$ref);
        $command = 'git remote set-url composer %s && git fetch composer && git fetch --tags composer';

        $commandCallable = function ($url) use ($command) {
            return sprintf($command, ProcessExecutor::escape($url));
        };

        $this->gitUtil->runCommand($commandCallable, $url, $path);
        if ($newRef =  $this->updateToCommit($path, $ref, $target->getPrettyVersion(), $target->getReleaseDate())) {
            if ($target->getDistReference() === $target->getSourceReference()) {
                $target->setDistReference($newRef);
            }
            $target->setSourceReference($newRef);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function getLocalChanges(PackageInterface $package, $path)
    {
        GitUtil::cleanEnv();
        $path = $this->normalizePath($path);
        if (!is_dir($path.'/.git')) {
            return;
        }

        $command = 'git status --porcelain --untracked-files=no';
        if (0 !== $this->process->execute($command, $output, $path)) {
            throw new \RuntimeException('Failed to execute ' . $command . "\n\n" . $this->process->getErrorOutput());
        }

        return trim($output) ?: null;
    }

    /**
     * {@inheritDoc}
     */
    protected function cleanChanges(PackageInterface $package, $path, $update)
    {
        GitUtil::cleanEnv();
        $path = $this->normalizePath($path);
        if (!$changes = $this->getLocalChanges($package, $path)) {
            return;
        }

        if (!$this->io->isInteractive()) {
            $discardChanges = $this->config->get('discard-changes');
            if (true === $discardChanges) {
                return $this->discardChanges($path);
            }
            if ('stash' === $discardChanges) {
                if (!$update) {
                    return parent::cleanChanges($package, $path, $update);
                }

                return $this->stashChanges($path);
            }

            return parent::cleanChanges($package, $path, $update);
        }

        $changes = array_map(function ($elem) {
            return '    '.$elem;
        }, preg_split('{\s*\r?\n\s*}', $changes));
        $this->io->writeError('    <error>The package has modified files:</error>');
        $this->io->writeError(array_slice($changes, 0, 10));
        if (count($changes) > 10) {
            $this->io->writeError('    <info>'.count($changes) - 10 . ' more files modified, choose "v" to view the full list</info>');
        }

        while (true) {
            switch ($this->io->ask('    <info>Discard changes [y,n,v,d,'.($update ? 's,' : '').'?]?</info> ', '?')) {
                case 'y':
                    $this->discardChanges($path);
                    break 2;

                case 's':
                    if (!$update) {
                        goto help;
                    }

                    $this->stashChanges($path);
                    break 2;

                case 'n':
                    throw new \RuntimeException('Update aborted');

                case 'v':
                    $this->io->writeError($changes);
                    break;

                case 'd':
                    $this->viewDiff($path);
                    break;

                case '?':
                default:
                    help:
                    $this->io->writeError(array(
                        '    y - discard changes and apply the '.($update ? 'update' : 'uninstall'),
                        '    n - abort the '.($update ? 'update' : 'uninstall').' and let you manually clean things up',
                        '    v - view modified files',
                        '    d - view local modifications (diff)',
                    ));
                    if ($update) {
                        $this->io->writeError('    s - stash changes and try to reapply them after the update');
                    }
                    $this->io->writeError('    ? - print help');
                    break;
            }
        }
    }

    /**
     * {@inheritDoc}
     */
    protected function reapplyChanges($path)
    {
        $path = $this->normalizePath($path);
        if ($this->hasStashedChanges) {
            $this->hasStashedChanges = false;
            $this->io->writeError('    <info>Re-applying stashed changes</info>');
            if (0 !== $this->process->execute('git stash pop', $output, $path)) {
                throw new \RuntimeException("Failed to apply stashed changes:\n\n".$this->process->getErrorOutput());
            }
        }

        $this->hasDiscardedChanges = false;
    }

    /**
     * Updates the given path to the given commit ref
     *
     * @param  string            $path
     * @param  string            $reference
     * @param  string            $branch
     * @param  \DateTime         $date
     * @throws \RuntimeException
     * @return null|string       if a string is returned, it is the commit reference that was checked out if the original could not be found
     */
    protected function updateToCommit($path, $reference, $branch, $date)
    {
        $force = $this->hasDiscardedChanges || $this->hasStashedChanges ? '-f ' : '';

        // This uses the "--" sequence to separate branch from file parameters.
        //
        // Otherwise git tries the branch name as well as file name.
        // If the non-existent branch is actually the name of a file, the file
        // is checked out.
        $template = 'git checkout '.$force.'%s -- && git reset --hard %1$s --';
        $branch = preg_replace('{(?:^dev-|(?:\.x)?-dev$)}i', '', $branch);

        $branches = null;
        if (0 === $this->process->execute('git branch -r', $output, $path)) {
            $branches = $output;
        }

        // check whether non-commitish are branches or tags, and fetch branches with the remote name
        $gitRef = $reference;
        if (!preg_match('{^[a-f0-9]{40}$}', $reference)
            && $branches
            && preg_match('{^\s+composer/'.preg_quote($reference).'$}m', $branches)
        ) {
            $command = sprintf('git checkout '.$force.'-B %s %s -- && git reset --hard %2$s --', ProcessExecutor::escape($branch), ProcessExecutor::escape('composer/'.$reference));
            if (0 === $this->process->execute($command, $output, $path)) {
                return;
            }
        }

        // try to checkout branch by name and then reset it so it's on the proper branch name
        if (preg_match('{^[a-f0-9]{40}$}', $reference)) {
            // add 'v' in front of the branch if it was stripped when generating the pretty name
            if (!preg_match('{^\s+composer/'.preg_quote($branch).'$}m', $branches) && preg_match('{^\s+composer/v'.preg_quote($branch).'$}m', $branches)) {
                $branch = 'v' . $branch;
            }

            $command = sprintf('git checkout %s --', ProcessExecutor::escape($branch));
            $fallbackCommand = sprintf('git checkout '.$force.'-B %s %s --', ProcessExecutor::escape($branch), ProcessExecutor::escape('composer/'.$branch));
            if (0 === $this->process->execute($command, $output, $path)
                || 0 === $this->process->execute($fallbackCommand, $output, $path)
            ) {
                $command = sprintf('git reset --hard %s --', ProcessExecutor::escape($reference));
                if (0 === $this->process->execute($command, $output, $path)) {
                    return;
                }
            }
        }

        $command = sprintf($template, ProcessExecutor::escape($gitRef));
        if (0 === $this->process->execute($command, $output, $path)) {
            return;
        }

        // reference was not found (prints "fatal: reference is not a tree: $ref")
        if (false !== strpos($this->process->getErrorOutput(), $reference)) {
            $this->io->writeError('    <warning>'.$reference.' is gone (history was rewritten?)</warning>');
        }

        throw new \RuntimeException('Failed to execute ' . GitUtil::sanitizeUrl($command) . "\n\n" . $this->process->getErrorOutput());
    }

    protected function setPushUrl($path, $url)
    {
        // set push url for github projects
        if (preg_match('{^(?:https?|git)://'.GitUtil::getGitHubDomainsRegex($this->config).'/([^/]+)/([^/]+?)(?:\.git)?$}', $url, $match)) {
            $protocols = $this->config->get('github-protocols');
            $pushUrl = 'git@'.$match[1].':'.$match[2].'/'.$match[3].'.git';
            if ($protocols[0] !== 'git') {
                $pushUrl = 'https://' . $match[1] . '/'.$match[2].'/'.$match[3].'.git';
            }
            $cmd = sprintf('git remote set-url --push origin %s', ProcessExecutor::escape($pushUrl));
            $this->process->execute($cmd, $ignoredOutput, $path);
        }
    }

    /**
     * {@inheritDoc}
     */
    protected function getCommitLogs($fromReference, $toReference, $path)
    {
        $path = $this->normalizePath($path);
        $command = sprintf('git log %s..%s --pretty=format:"%%h - %%an: %%s"', $fromReference, $toReference);

        if (0 !== $this->process->execute($command, $output, $path)) {
            throw new \RuntimeException('Failed to execute ' . $command . "\n\n" . $this->process->getErrorOutput());
        }

        return $output;
    }

    /**
     * @param $path
     * @throws \RuntimeException
     */
    protected function discardChanges($path)
    {
        $path = $this->normalizePath($path);
        if (0 !== $this->process->execute('git reset --hard', $output, $path)) {
            throw new \RuntimeException("Could not reset changes\n\n:".$this->process->getErrorOutput());
        }

        $this->hasDiscardedChanges = true;
    }

    /**
     * @param $path
     * @throws \RuntimeException
     */
    protected function stashChanges($path)
    {
        $path = $this->normalizePath($path);
        if (0 !== $this->process->execute('git stash --include-untracked', $output, $path)) {
            throw new \RuntimeException("Could not stash changes\n\n:".$this->process->getErrorOutput());
        }

        $this->hasStashedChanges = true;
    }

    /**
     * @param $path
     * @throws \RuntimeException
     */
    protected function viewDiff($path)
    {
        $path = $this->normalizePath($path);
        if (0 !== $this->process->execute('git diff HEAD', $output, $path)) {
            throw new \RuntimeException("Could not view diff\n\n:".$this->process->getErrorOutput());
        }

        $this->io->writeError($output);
    }

    protected function normalizePath($path)
    {
        if (defined('PHP_WINDOWS_VERSION_MAJOR') && strlen($path) > 0) {
            $basePath = $path;
            $removed = array();

            while (!is_dir($basePath) && $basePath !== '\\') {
                array_unshift($removed, basename($basePath));
                $basePath = dirname($basePath);
            }

            if ($basePath === '\\') {
                return $path;
            }

            $path = rtrim(realpath($basePath) . '/' . implode('/', $removed), '/');
        }

        return $path;
    }
}
