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
        $ref = $package->getSourceReference();
        $command = 'git clone %s %s && cd %2$s && git remote add composer %1$s && git fetch composer';
        $this->io->write("    Cloning ".$ref);

        // added in git 1.7.1, prevents prompting the user
        putenv('GIT_ASKPASS=echo');
        $commandCallable = function($url) use ($ref, $path, $command) {
            return sprintf($command, escapeshellarg($url), escapeshellarg($path), escapeshellarg($ref));
        };

        $this->runCommand($commandCallable, $package->getSourceUrl(), $path);
        $this->setPushUrl($package, $path);

        $this->updateToCommit($path, $ref, $package->getPrettyVersion(), $package->getReleaseDate());
    }

    /**
     * {@inheritDoc}
     */
    public function doUpdate(PackageInterface $initial, PackageInterface $target, $path)
    {
        $ref = $target->getSourceReference();
        $this->io->write("    Checking out ".$ref);
        $command = 'cd %s && git remote set-url composer %s && git fetch composer && git fetch --tags composer';

        if (!$this->io->hasAuthorization('github.com')) {
            // capture username/password from github URL if there is one
            $this->process->execute(sprintf('cd %s && git remote -v', escapeshellarg($path)), $output);
            if (preg_match('{^composer\s+https://(.+):(.+)@github.com/}im', $output, $match)) {
                $this->io->setAuthorization('github.com', $match[1], $match[2]);
            }
        }

        $commandCallable = function($url) use ($ref, $path, $command) {
            return sprintf($command, escapeshellarg($path), escapeshellarg($url), escapeshellarg($ref));
        };

        $this->runCommand($commandCallable, $target->getSourceUrl());
        $this->updateToCommit($path, $ref, $target->getPrettyVersion(), $target->getReleaseDate());
    }

    /**
     * {@inheritDoc}
     */
    public function getLocalChanges($path)
    {
        $command = sprintf('cd %s && git status --porcelain --untracked-files=no', escapeshellarg($path));
        if (0 !== $this->process->execute($command, $output)) {
            throw new \RuntimeException('Failed to execute ' . $command . "\n\n" . $this->process->getErrorOutput());
        }

        return trim($output) ?: null;
    }

    /**
     * {@inheritDoc}
     */
    protected function cleanChanges($path, $update)
    {
        if (!$this->io->isInteractive()) {
            return parent::cleanChanges($path, $update);
        }

        if (!$changes = $this->getLocalChanges($path)) {
            return;
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
                    if (0 !== $this->process->execute('git reset --hard', $output, $path)) {
                        throw new \RuntimeException("Could not reset changes\n\n:".$this->process->getErrorOutput());
                    }
                    break 2;

                case 's':
                    if (!$update) {
                        goto help;
                    }

                    if (0 !== $this->process->execute('git stash', $output, $path)) {
                        throw new \RuntimeException("Could not stash changes\n\n:".$this->process->getErrorOutput());
                    }

                    $this->hasStashedChanges = true;
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
        if ($this->hasStashedChanges) {
            $this->io->write('    <info>Re-applying stashed changes');
            if (0 !== $this->process->execute('git stash pop', $output, $path)) {
                throw new \RuntimeException("Failed to apply stashed changes:\n\n".$this->process->getErrorOutput());
            }
        }
    }

    protected function updateToCommit($path, $reference, $branch, $date)
    {
        $template = 'git checkout %s && git reset --hard %1$s';
        $branch = preg_replace('{(?:^dev-|(?:\.x)?-dev$)}i', '', $branch);

        // check whether non-commitish are branches or tags, and fetch branches with the remote name
        $gitRef = $reference;
        if (!preg_match('{^[a-f0-9]{40}$}', $reference)
            && 0 === $this->process->execute('git branch -r', $output, $path)
            && preg_match('{^\s+composer/'.preg_quote($reference).'$}m', $output)
        ) {
            $command = sprintf('git checkout -B %s %s && git reset --hard %2$s', escapeshellarg($branch), escapeshellarg('composer/'.$reference));
            if (0 === $this->process->execute($command, $output, $path)) {
                return;
            }
        }

        // try to checkout branch by name and then reset it so it's on the proper branch name
        if (preg_match('{^[a-f0-9]{40}$}', $reference)) {
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
            $command = sprintf($template, escapeshellarg($reference));
            if (0 === $this->process->execute($command, $output, $path)) {
                $this->io->write('    '.$reference.' is gone (history was rewritten?), recovered by checking out '.$newReference);

                return;
            }
        }

        throw new \RuntimeException('Failed to execute ' . $this->sanitizeUrl($command) . "\n\n" . $this->process->getErrorOutput());
    }

    /**
     * Runs a command doing attempts for each protocol supported by github.
     *
     * @param  callable          $commandCallable A callable building the command for the given url
     * @param  string            $url
     * @param  string            $path            The directory to remove for each attempt (null if not needed)
     * @throws \RuntimeException
     */
    protected function runCommand($commandCallable, $url, $path = null)
    {
        $handler = array($this, 'outputHandler');

        // public github, autoswitch protocols
        if (preg_match('{^(?:https?|git)(://github.com/.*)}', $url, $match)) {
            $protocols = $this->config->get('github-protocols');
            if (!is_array($protocols)) {
                throw new \RuntimeException('Config value "github-protocols" must be an array, got '.gettype($protocols));
            }
            $messages = array();
            foreach ($protocols as $protocol) {
                $url = $protocol . $match[1];
                if (0 === $this->process->execute(call_user_func($commandCallable, $url), $handler)) {
                    return;
                }
                $messages[] = '- ' . $url . "\n" . preg_replace('#^#m', '  ', $this->process->getErrorOutput());
                if (null !== $path) {
                    $this->filesystem->removeDirectory($path);
                }
            }

            // failed to checkout, first check git accessibility
            $this->throwException('Failed to clone ' . $this->sanitizeUrl($url) .' via git, https and http protocols, aborting.' . "\n\n" . implode("\n", $messages), $url);
        }

        $command = call_user_func($commandCallable, $url);
        if (0 !== $this->process->execute($command, $handler)) {
            if (preg_match('{^git@github.com:(.+?)\.git$}i', $url, $match) && $this->io->isInteractive()) {
                // private github repository without git access, try https with auth
                $retries = 3;
                $retrying = false;
                do {
                    if ($retrying) {
                        $this->io->write('Invalid credentials');
                    }
                    if (!$this->io->hasAuthorization('github.com') || $retrying) {
                        $username = $this->io->ask('Username: ');
                        $password = $this->io->askAndHideAnswer('Password: ');
                        $this->io->setAuthorization('github.com', $username, $password);
                    }

                    $auth = $this->io->getAuthorization('github.com');
                    $url = 'https://'.$auth['username'] . ':' . $auth['password'] . '@github.com/'.$match[1].'.git';

                    $command = call_user_func($commandCallable, $url);
                    if (0 === $this->process->execute($command, $handler)) {
                        return;
                    }
                    if (null !== $path) {
                        $this->filesystem->removeDirectory($path);
                    }
                    $retrying = true;
                } while (--$retries);
            }

            if (null !== $path) {
                $this->filesystem->removeDirectory($path);
            }
            $this->throwException('Failed to execute ' . $this->sanitizeUrl($command) . "\n\n" . $this->process->getErrorOutput(), $url);
        }
    }

    public function outputHandler($type, $buffer)
    {
        if ($type !== 'out') {
            return;
        }
        if ($this->io->isVerbose()) {
            $this->io->write($buffer, false);
        }
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
        return preg_replace('{://(.+?):.+?@}', '://$1:***@', $message);
    }

    protected function setPushUrl(PackageInterface $package, $path)
    {
        // set push url for github projects
        if (preg_match('{^(?:https?|git)://github.com/([^/]+)/([^/]+?)(?:\.git)?$}', $package->getSourceUrl(), $match)) {
            $pushUrl = 'git@github.com:'.$match[1].'/'.$match[2].'.git';
            $cmd = sprintf('git remote set-url --push origin %s', escapeshellarg($pushUrl));
            $this->process->execute($cmd, $ignoredOutput, $path);
        }
    }

    /**
     * {@inheritDoc}
     */
    protected function getCommitLogs($fromReference, $toReference, $path)
    {
        $command = sprintf('cd %s && git log %s..%s --pretty=format:"%%h - %%an: %%s"', escapeshellarg($path), $fromReference, $toReference);

        if (0 !== $this->process->execute($command, $output)) {
            throw new \RuntimeException('Failed to execute ' . $command . "\n\n" . $this->process->getErrorOutput());
        }

        return $output;
    }
}
