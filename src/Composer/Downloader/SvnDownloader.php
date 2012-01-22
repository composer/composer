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
 * @author Ben Bieker <mail@ben-bieker.de>
 */
class SvnDownloader extends VcsDownloader
{
    /**
     * {@inheritDoc}
     */
    public function doDownload(PackageInterface $package, $path)
    {
        $url = escapeshellarg($package->getSourceUrl());
        $ref = escapeshellarg($package->getSourceReference());
        $path = escapeshellarg($path);
        $this->io->write("    Checking out ".$package->getSourceReference());
        $this->process->execute(sprintf('svn co %s/%s %s', $url, $ref, $path));
    }

    /**
     * {@inheritDoc}
     */
    public function doUpdate(PackageInterface $initial, PackageInterface $target, $path)
    {
        $ref = escapeshellarg($target->getSourceReference());
        $path = escapeshellarg($path);
        $url = escapeshellarg($target->getSourceUrl());
        $this->io->write("    Checking out ".$target->getSourceReference());
        $this->process->execute(sprintf('cd %s && svn switch %s/%s', $path, $url, $ref));
    }

    /**
     * {@inheritDoc}
     */
    protected function enforceCleanDirectory($path)
    {
        $this->process->execute(sprintf('cd %s && svn status', escapeshellarg($path)), $output);
        if (trim($output)) {
            throw new \RuntimeException('Source directory has uncommitted changes');
        }
    }
}