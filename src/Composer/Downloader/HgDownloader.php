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
 * @author Per Bernhardt <plb@webfactory.de>
 */
class HgDownloader extends VcsDownloader
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
        $command = sprintf('hg clone %s %s && cd %2$s && hg up %s', $url, $path, $ref);
        if (0 !== $this->process->execute($command, $ignoredOutput)) {
            throw new \RuntimeException('Failed to execute ' . $command . "\n\n" . $this->process->getErrorOutput());
        }
    }

    /**
     * {@inheritDoc}
     */
    public function doUpdate(PackageInterface $initial, PackageInterface $target, $path)
    {
        $url = escapeshellarg($target->getSourceUrl());
        $ref = escapeshellarg($target->getSourceReference());
        $path = escapeshellarg($path);
        $this->io->write("    Updating to ".$target->getSourceReference());
        $command = sprintf('cd %s && hg pull %s && hg up %s', $path, $url, $ref);
        if (0 !== $this->process->execute($command, $ignoredOutput)) {
            throw new \RuntimeException('Failed to execute ' . $command . "\n\n" . $this->process->getErrorOutput());
        }
    }

    /**
     * {@inheritDoc}
     */
    public function getLocalChanges($path)
    {
        if (!is_dir($path.'/.hg')) {
            return;
        }

        $this->process->execute(sprintf('cd %s && hg st', escapeshellarg($path)), $output);

        return trim($output) ?: null;
    }

    /**
     * {@inheritDoc}
     */
    protected function getCommitLogs($fromReference, $toReference, $path)
    {
        $command = sprintf('cd %s && hg log -r %s:%s --style compact', escapeshellarg($path), $fromReference, $toReference);

        if (0 !== $this->process->execute($command, $output)) {
            throw new \RuntimeException('Failed to execute ' . $command . "\n\n" . $this->process->getErrorOutput());
        }

        return $output;
    }
}
