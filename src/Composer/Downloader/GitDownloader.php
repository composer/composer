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
        $url = escapeshellarg($package->getSourceUrl());
        $ref = escapeshellarg($package->getSourceReference());
        $path = escapeshellarg($path);
        $this->io->write("    Cloning ".$package->getSourceReference());
        $command = sprintf('git clone %s %s && cd %2$s && git checkout %3$s && git reset --hard %3$s', $url, $path, $ref);
        if (0 !== $this->process->execute($command, $ignoredOutput)) {
            throw new \RuntimeException('Failed to execute ' . $command . "\n\n" . $this->process->getErrorOutput());
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
            throw new \RuntimeException('Source directory has uncommitted changes');
        }
    }
}
