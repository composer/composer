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
 * @author Per Bernhardt <plb@webfactory.de>
 */
class HgDownloader extends VcsDownloader
{
    /**
     * {@inheritDoc}
     */
    public function doDownload(PackageInterface $package, $path, $url)
    {
        // Ensure we are allowed to use this URL by config
        $this->config->prohibitUrlByConfig($url);

        $url = ProcessExecutor::escape($url);
        $ref = ProcessExecutor::escape($package->getSourceReference());
        $this->io->writeError("    Cloning ".$package->getSourceReference());
        $command = sprintf('hg clone %s %s', $url, ProcessExecutor::escape($path));
        if (0 !== $this->process->execute($command, $ignoredOutput)) {
            throw new \RuntimeException('Failed to execute ' . $command . "\n\n" . $this->process->getErrorOutput());
        }
        $command = sprintf('hg up %s', $ref);
        if (0 !== $this->process->execute($command, $ignoredOutput, realpath($path))) {
            throw new \RuntimeException('Failed to execute ' . $command . "\n\n" . $this->process->getErrorOutput());
        }
    }

    /**
     * {@inheritDoc}
     */
    public function doUpdate(PackageInterface $initial, PackageInterface $target, $path, $url)
    {
        // Ensure we are allowed to use this URL by config
        $this->config->prohibitUrlByConfig($url);

        $url = ProcessExecutor::escape($url);
        $ref = ProcessExecutor::escape($target->getSourceReference());
        $this->io->writeError("    Updating to ".$target->getSourceReference());

        if (!$this->hasMetadataRepository($path)) {
            throw new \RuntimeException('The .hg directory is missing from '.$path.', see https://getcomposer.org/commit-deps for more information');
        }

        $command = sprintf('hg pull %s && hg up %s', $url, $ref);
        if (0 !== $this->process->execute($command, $ignoredOutput, realpath($path))) {
            throw new \RuntimeException('Failed to execute ' . $command . "\n\n" . $this->process->getErrorOutput());
        }
    }

    /**
     * {@inheritDoc}
     */
    public function getLocalChanges(PackageInterface $package, $path)
    {
        if (!is_dir($path.'/.hg')) {
            return null;
        }

        $this->process->execute('hg st', $output, realpath($path));

        return trim($output) ?: null;
    }

    /**
     * {@inheritDoc}
     */
    protected function getCommitLogs($fromReference, $toReference, $path)
    {
        $command = sprintf('hg log -r %s:%s --style compact', $fromReference, $toReference);

        if (0 !== $this->process->execute($command, $output, realpath($path))) {
            throw new \RuntimeException('Failed to execute ' . $command . "\n\n" . $this->process->getErrorOutput());
        }

        return $output;
    }

    /**
     * {@inheritDoc}
     */
    protected function hasMetadataRepository($path)
    {
        return is_dir($path . '/.hg');
    }
}
