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
use Composer\Util\Svn as SvnUtil;

/**
 * @author Ben Bieker <mail@ben-bieker.de>
 * @author Till Klampaeckel <till@php.net>
 */
class SvnDownloader extends VcsDownloader
{
    /**
     * {@inheritDoc}
     */
    public function doDownload(PackageInterface $package, $path)
    {
        $url =  $package->getSourceUrl();
        $ref =  $package->getSourceReference();

        $this->io->write("    Checking out ".$package->getSourceReference());
        $this->execute($url, "svn co", sprintf("%s/%s", $url, $ref), null, $path);
    }

    /**
     * {@inheritDoc}
     */
    public function doUpdate(PackageInterface $initial, PackageInterface $target, $path)
    {
        $url = $target->getSourceUrl();
        $ref = $target->getSourceReference();

        $this->io->write("    Checking out " . $ref);
        $this->execute($url, "svn switch", sprintf("%s/%s", $url, $ref), $path);
    }

    /**
     * {@inheritDoc}
     */
    protected function enforceCleanDirectory($path)
    {
        $this->process->execute('svn status --ignore-externals', $output, $path);
        if (preg_match('{^ *[^X ] +}m', $output)) {
            throw new \RuntimeException('Source directory ' . $path . ' has uncommitted changes:'."\n\n".rtrim($output));
        }
    }

    /**
     * Execute an SVN command and try to fix up the process with credentials
     * if necessary.
     *
     * @param string $baseUrl Base URL of the repository
     * @param string $command SVN command to run
     * @param string $url     SVN url
     * @param string $cwd     Working directory
     * @param string $path    Target for a checkout
     *
     * @return string
     */
    protected function execute($baseUrl, $command, $url, $cwd = null, $path = null)
    {
        $util = new SvnUtil($baseUrl, $this->io);
        try {
            return $util->execute($command, $url, $cwd, $path, $this->io->isVerbose());
        } catch (\RuntimeException $e) {
            throw new \RuntimeException(
                'Package could not be downloaded, '.$e->getMessage()
            );
        }
    }

    protected function getCommitLogs($sourceReference, $targetReference, $path)
    {
        $command = sprintf('cd %s && svn log -r%s:%s --incremental', escapeshellarg($path), $sourceReference, $targetReference);

        if (0 !== $this->process->execute($command, $output)) {
            throw new \RuntimeException('Failed to execute ' . $command . "\n\n" . $this->process->getErrorOutput());
        }

        return $output;
    }
}
