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

        // github, autoswitch protocols
        if (preg_match('{^(?:https?|git)(://github.com/.*)}', $package->getSourceUrl(), $match)) {
            $protocols = array('git', 'https', 'http');
            foreach ($protocols as $protocol) {
                $url = escapeshellarg($protocol . $match[1]);
                if (0 === $this->process->execute(sprintf($command, $url, escapeshellarg($path), $ref), $ignoredOutput)) {
                    return;
                }
                $this->filesystem->removeDirectory($path);
            }
            throw new \RuntimeException('Failed to checkout ' . $url .' via git, https and http protocols, aborting.' . "\n\n" . $this->process->getErrorOutput());
        } else {
            $url = escapeshellarg($package->getSourceUrl());
            $command = sprintf($command, $url, escapeshellarg($path), $ref);
            if (0 !== $this->process->execute($command, $ignoredOutput)) {
                throw new \RuntimeException('Failed to execute ' . $command . "\n\n" . $this->process->getErrorOutput());
            }
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
        $command = sprintf('cd %s && git fetch && git checkout %2$s && git reset --hard %2$s', $path, $ref);
        if (0 !== $this->process->execute($command, $ignoredOutput)) {
            throw new \RuntimeException('Failed to execute ' . $command . "\n\n" . $this->process->getErrorOutput());
        }
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
}
