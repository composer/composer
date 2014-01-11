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
use Composer\Util\GitHub;
use Composer\Util\Git as GitUtil;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class GitDownloader extends VcsDownloader
{
    private $hasStashedChanges = false;

    /**
     * {@inheritDoc}
     */
    public function doDownload(PackageInterface $package, $path)
    {
        $this->cleanEnv();
        $path = $this->normalizePath($path);

        $ref = $package->getSourceReference();
        $flag = defined('PHP_WINDOWS_VERSION_MAJOR') ? '/D ' : '';
        $command = 'git clone %s %s && cd '.$flag.'%2$s && git remote add composer %1$s && git fetch composer';
        $this->io->write("    Cloning ".$ref);

        $commandCallable = function($url) use ($ref, $path, $command) {
            return sprintf($command, escapeshellarg($url), escapeshellarg($path), escapeshellarg($ref));
        };

        $this->runCommand($commandCallable, $package->getSourceUrl(), $path, true);
        $this->setPushUrl($package, $path);

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
    public function doUpdate(PackageInterface $initial, PackageInterface $target, $path)
    {
        $this->cleanEnv();
        $path = $this->normalizePath($path);
        if (!is_dir($path.'/.git')) {
            throw new \RuntimeException('The .git directory is missing from '.$path.', see http://getcomposer.org/commit-deps for more information');
        }

        $ref = $target->getSourceReference();
        $this->io->write("    Checking out ".$ref);
        $command = 'git remote set-url composer %s && git fetch composer && git fetch --tags composer';

        // capture username/password from URL if there is one
        $this->process->execute('git remote -v', $output, $path);
        if (preg_match('{^(?:composer|origin)\s+https?://(.+):(.+)@([^/]+)}im', $output, $match)) {
            $this->io->setAuthentication($match[3], urldecode($match[1]), urldecode($match[2]));
        }

        $commandCallable = function($url) use ($command) {
            return sprintf($command, escapeshellarg($url));
        };

        $this->runCommand($commandCallable, $target->getSourceUrl(), $path);
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
        $this->cleanEnv();
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
        $this->cleanEnv();
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
        $this->io->write('    <error>The package has modified files:</error>');
        $this->io->write(array_slice($changes, 0, 10));
        if (count($changes) > 10) {
            $this->io->write('    <info>'.count($changes) - 10 . ' more files modified, choose "v" to view the full list</info>');
        }

        while (true) {
            switch ($this->io->ask('    <info>Discard changes [y,n,v,'.($update ? 's,' : '').'?]?</info> ', '?')) {
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
                    $this->io->write($changes);
                    break;

                case '?':
                default:
                    help:
                    $this->io->write(array(
                        '    y - discard changes and apply the '.($update ? 'update' : 'uninstall'),
                        '    n - abort the '.($update ? 'update' : 'uninstall').' and let you manually clean things up',
                        '    v - view modified files',
                    ));
                    if ($update) {
                        $this->io->write('    s - stash changes and try to reapply them after the update');
                    }
                    $this->io->write('    ? - print help');
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
            $this->io->write('    <info>Re-applying stashed changes');
            if (0 !== $this->process->execute('git stash pop', $output, $path)) {
                throw new \RuntimeException("Failed to apply stashed changes:\n\n".$this->process->getErrorOutput());
            }
        }
    }

    /**
     * Updates the given apth to the given commit ref
     *
     * @param string $path
     * @param string $reference
     * @param string $branch
     * @param DateTime $date
     * @return null|string if a string is returned, it is the commit reference that was checked out if the original could not be found
     */
    protected function updateToCommit($path, $reference, $branch, $date)
    {
        $template = 'git checkout %s && git reset --hard %1$s';
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
            $command = sprintf('git checkout -B %s %s && git reset --hard %2$s', escapeshellarg($branch), escapeshellarg('composer/'.$reference));
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

            $command = sprintf('git checkout %s', escapeshellarg($branch));
            $fallbackCommand = sprintf('git checkout -B %s %s', escapeshellarg($branch), escapeshellarg('composer/'.$branch));
            if (0 === $this->process->execute($command, $output, $path)
                || 0 === $this->process->execute($fallbackCommand, $output, $path)
            ) {
                $command = sprintf('git reset --hard %s', escapeshellarg($reference));
                if (0 === $this->process->execute($command, $output, $path)) {
                    return;
                }
            }
        }

        $command = sprintf($template, escapeshellarg($gitRef));
        if (0 === $this->process->execute($command, $output, $path)) {
            return;
        }

        // reference was not found (prints "fatal: reference is not a tree: $ref")
        if ($date && false !== strpos($this->process->getErrorOutput(), $reference)) {
            $date = $date->format('U');

            // guess which remote branch to look at first
            $command = 'git branch -r';
            if (0 !== $this->process->execute($command, $output, $path)) {
                throw new \RuntimeException('Failed to execute ' . $command . "\n\n" . $this->process->getErrorOutput());
            }

            $guessTemplate = 'git log --until=%s --date=raw -n1 --pretty=%%H %s';
            foreach ($this->process->splitLines($output) as $line) {
                if (preg_match('{^composer/'.preg_quote($branch).'(?:\.x)?$}i', trim($line))) {
                    // find the previous commit by date in the given branch
                    if (0 === $this->process->execute(sprintf($guessTemplate, $date, escapeshellarg(trim($line))), $output, $path)) {
                        $newReference = trim($output);
                    }

                    break;
                }
            }

            if (empty($newReference)) {
                // no matching branch found, find the previous commit by date in all commits
                if (0 !== $this->process->execute(sprintf($guessTemplate, $date, '--all'), $output, $path)) {
                    throw new \RuntimeException('Failed to execute ' . $this->sanitizeUrl($command) . "\n\n" . $this->process->getErrorOutput());
                }
                $newReference = trim($output);
            }

            // checkout the new recovered ref
            $command = sprintf($template, escapeshellarg($newReference));
            if (0 === $this->process->execute($command, $output, $path)) {
                $this->io->write('    '.$reference.' is gone (history was rewritten?), recovered by checking out '.$newReference);

                return $newReference;
            }
        }

        throw new \RuntimeException('Failed to execute ' . $this->sanitizeUrl($command) . "\n\n" . $this->process->getErrorOutput());
    }

    /**
     * Runs a command doing attempts for each protocol supported by github.
     *
     * @param  callable                  $commandCallable A callable building the command for the given url
     * @param  string                    $url
     * @param  string                    $cwd
     * @param  bool                      $initialClone    If true, the directory if cleared between every attempt
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     */
    protected function runCommand($commandCallable, $url, $cwd, $initialClone = false)
    {
        if ($initialClone) {
            $origCwd = $cwd;
            $cwd = null;
        }

        if (preg_match('{^ssh://[^@]+@[^:]+:[^0-9]+}', $url)) {
            throw new \InvalidArgumentException('The source URL '.$url.' is invalid, ssh URLs should have a port number after ":".'."\n".'Use ssh://git@example.com:22/path or just git@example.com:path if you do not want to provide a password or custom port.');
        }

        // public github, autoswitch protocols
        if (preg_match('{^(?:https?|git)(://'.$this->getGitHubDomainsRegex().'/.*)}', $url, $match)) {
            $protocols = $this->config->get('github-protocols');
            if (!is_array($protocols)) {
                throw new \RuntimeException('Config value "github-protocols" must be an array, got '.gettype($protocols));
            }
            $messages = array();
            foreach ($protocols as $protocol) {
                $url = $protocol . $match[1];
                if (0 === $this->process->execute(call_user_func($commandCallable, $url), $ignoredOutput, $cwd)) {
                    return;
                }
                $messages[] = '- ' . $url . "\n" . preg_replace('#^#m', '  ', $this->process->getErrorOutput());
                if ($initialClone) {
                    $this->filesystem->removeDirectory($origCwd);
                }
            }

            // failed to checkout, first check git accessibility
            $this->throwException('Failed to clone ' . $this->sanitizeUrl($url) .' via '.implode(', ', $protocols).' protocols, aborting.' . "\n\n" . implode("\n", $messages), $url);
        }

        $command = call_user_func($commandCallable, $url);
        if (0 !== $this->process->execute($command, $ignoredOutput, $cwd)) {
            // private github repository without git access, try https with auth
            if (preg_match('{^git@'.$this->getGitHubDomainsRegex().':(.+?)\.git$}i', $url, $match)) {
                if (!$this->io->hasAuthentication($match[1])) {
                    $gitHubUtil = new GitHub($this->io, $this->config, $this->process);
                    $message = 'Cloning failed using an ssh key for authentication, enter your GitHub credentials to access private repos';

                    if (!$gitHubUtil->authorizeOAuth($match[1]) && $this->io->isInteractive()) {
                        $gitHubUtil->authorizeOAuthInteractively($match[1], $message);
                    }
                }

                if ($this->io->hasAuthentication($match[1])) {
                    $auth = $this->io->getAuthentication($match[1]);
                    $url = 'https://'.urlencode($auth['username']) . ':' . urlencode($auth['password']) . '@'.$match[1].'/'.$match[2].'.git';

                    $command = call_user_func($commandCallable, $url);
                    if (0 === $this->process->execute($command, $ignoredOutput, $cwd)) {
                        return;
                    }
                }
            } elseif ( // private non-github repo that failed to authenticate
                $this->io->isInteractive() &&
                preg_match('{(https?://)([^/]+)(.*)$}i', $url, $match) &&
                strpos($this->process->getErrorOutput(), 'fatal: Authentication failed') !== false
            ) {
                // TODO this should use an auth manager class that prompts and stores in the config
                if ($this->io->hasAuthentication($match[2])) {
                    $auth = $this->io->getAuthentication($match[2]);
                } else {
                    $this->io->write($url.' requires Authentication');
                    $auth = array(
                        'username'  => $this->io->ask('Username: '),
                        'password'  => $this->io->askAndHideAnswer('Password: '),
                    );
                }

                $url = $match[1].urlencode($auth['username']).':'.urlencode($auth['password']).'@'.$match[2].$match[3];

                $command = call_user_func($commandCallable, $url);
                if (0 === $this->process->execute($command, $ignoredOutput, $cwd)) {
                    $this->io->setAuthentication($match[2], $auth['username'], $auth['password']);

                    return;
                }
            }

            if ($initialClone) {
                $this->filesystem->removeDirectory($origCwd);
            }
            $this->throwException('Failed to execute ' . $this->sanitizeUrl($command) . "\n\n" . $this->process->getErrorOutput(), $url);
        }
    }

    protected function getGitHubDomainsRegex()
    {
        return '('.implode('|', array_map('preg_quote', $this->config->get('github-domains'))).')';
    }

    protected function throwException($message, $url)
    {
        if (0 !== $this->process->execute('git --version', $ignoredOutput)) {
            throw new \RuntimeException('Failed to clone '.$this->sanitizeUrl($url).', git was not found, check that it is installed and in your PATH env.' . "\n\n" . $this->process->getErrorOutput());
        }

        throw new \RuntimeException($message);
    }

    protected function sanitizeUrl($message)
    {
        return preg_replace('{://([^@]+?):.+?@}', '://$1:***@', $message);
    }

    protected function setPushUrl(PackageInterface $package, $path)
    {
        // set push url for github projects
        if (preg_match('{^(?:https?|git)://'.$this->getGitHubDomainsRegex().'/([^/]+)/([^/]+?)(?:\.git)?$}', $package->getSourceUrl(), $match)) {
            $protocols = $this->config->get('github-protocols');
            $pushUrl = 'git@'.$match[1].':'.$match[2].'/'.$match[3].'.git';
            if ($protocols[0] !== 'git') {
                $pushUrl = 'https://' . $match[1] . '/'.$match[2].'/'.$match[3].'.git';
            }
            $cmd = sprintf('git remote set-url --push origin %s', escapeshellarg($pushUrl));
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
    }

    /**
     * @param $path
     * @throws \RuntimeException
     */
    protected function stashChanges($path)
    {
        $path = $this->normalizePath($path);
        if (0 !== $this->process->execute('git stash', $output, $path)) {
            throw new \RuntimeException("Could not stash changes\n\n:".$this->process->getErrorOutput());
        }

        $this->hasStashedChanges = true;
    }

    protected function cleanEnv()
    {
        $util = new GitUtil;
        $util->cleanEnv();
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
