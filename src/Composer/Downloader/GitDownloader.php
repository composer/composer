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
use Composer\Util\ProcessExecutor;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class GitDownloader extends VcsDownloader
{
    /**
     * {@inheritDoc}
     */
    public function doDownload(PackageInterface $package, $path)
    {
        $ref = escapeshellarg($package->getSourceReference());
        $command = 'git clone %s %s && cd %2$s && git checkout %3$s && git reset --hard %3$s';
        $this->io->write("    Cloning ".$package->getSourceReference());

        $commandCallable = function($url) use ($ref, $path, $command) {
            return sprintf($command, $url, escapeshellarg($path), $ref);
        };

        $this->runCommand($commandCallable, $package->getSourceUrl(), $path);

        // set push url for github projects
        if (preg_match('{^(?:https?|git)://github.com/([^/]+)/([^/]+?)(?:\.git)?$}', $package->getSourceUrl(), $match)) {
            $pushUrl = 'git@github.com:'.$match[1].'/'.$match[2].'.git';
            $cmd = sprintf('cd %s && git remote set-url --push origin %s', escapeshellarg($path), escapeshellarg($pushUrl));
            $this->process->execute($cmd, $ignoredOutput);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function doUpdate(PackageInterface $initial, PackageInterface $target, $path)
    {
        $ref = escapeshellarg($target->getSourceReference());
        $path = escapeshellarg($path);
        $this->io->write("    Checking out ".$target->getSourceReference());
        $command = 'cd %s && git remote set-url origin %s && git fetch && git checkout %3$s && git reset --hard %3$s';

        $commandCallable = function($url) use ($ref, $path, $command) {
            return sprintf($command, $path, $url, $ref);
        };

        $this->runCommand($commandCallable, $target->getSourceUrl());
    }

    /**
     * {@inheritDoc}
     */
    protected function enforceCleanDirectory($path)
    {
        $command = sprintf('cd %s && git status --porcelain', escapeshellarg($path));
        if (0 !== $this->process->execute($command, $output)) {
            throw new \RuntimeException('Failed to execute ' . $command . "\n\n" . $this->process->getErrorOutput());
        }

        if (trim($output)) {
            throw new \RuntimeException('Source directory ' . $path . ' has uncommitted changes');
        }
    }

    /**
     * Runs a command doing attempts for each protocol supported by github.
     *
     * @param callable $commandCallable A callable building the command for the given url
     * @param string $url
     * @param string $path The directory to remove for each attempt (null if not needed)
     * @throws \RuntimeException
     */
    protected function runCommand($commandCallable, $url, $path = null)
    {
        // github, autoswitch protocols
        if (preg_match('{^(?:https?|git)(://github.com/.*)}', $url, $match)) {
            $protocols = array('git', 'https', 'http');
            foreach ($protocols as $protocol) {
                $url = escapeshellarg($protocol . $match[1]);
                if (0 === $this->process->execute(call_user_func($commandCallable, $url), $ignoredOutput)) {
                    return;
                }
                if (null !== $path) {
                    $this->filesystem->removeDirectory($path);
                }
            }
            throw new \RuntimeException('Failed to checkout ' . $url .' via git, https and http protocols, aborting.' . "\n\n" . $this->process->getErrorOutput());
        }

        $url = escapeshellarg($url);
        $command = call_user_func($commandCallable, $url);
        if (0 !== $this->process->execute($command, $ignoredOutput)) {
            throw new \RuntimeException('Failed to execute ' . $command . "\n\n" . $this->process->getErrorOutput());
        }
    }
}
