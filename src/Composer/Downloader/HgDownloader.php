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
    public function doDownload(PackageInterface $package, $path)
    {
        $url = escapeshellarg($package->getSourceUrl());
        $ref = escapeshellarg($package->getSourceReference());
        $path = escapeshellarg($path);
        $this->io->write("    Cloning ".$package->getSourceReference());
        $this->process->execute(sprintf('hg clone %s %s && cd %2$s && hg up %s', $url, $path, $ref), $ignoredOutput);
    }

    /**
     * {@inheritDoc}
     */
    public function doUpdate(PackageInterface $initial, PackageInterface $target, $path)
    {
        $ref = escapeshellarg($target->getSourceReference());
        $path = escapeshellarg($path);
        $this->io->write("    Updating to ".$target->getSourceReference());
        $this->process->execute(sprintf('cd %s && hg pull && hg up %s', $path, $ref), $ignoredOutput);
    }

    /**
     * {@inheritDoc}
     */
    protected function enforceCleanDirectory($path)
    {
        $this->process->execute(sprintf('cd %s && hg st', escapeshellarg($path)), $output);
        if (trim($output)) {
            throw new \RuntimeException('Source directory ' . $path . ' has uncommitted changes');
        }
    }
}
