<?php

namespace Composer\Downloader;

use Composer\Package\PackageInterface;
use Composer\Downloader\Util\Filesystem;

/**
 * @author Ben Bieker <mail@ben-bieker.de>
 */
class SvnDownloader implements DownloaderInterface
{
    /**
     * {@inheritDoc}
     */
    public function getInstallationSource()
    {
        return 'source';
    }

    /**
     * {@inheritDoc}
     */
    public function download(PackageInterface $package, $path)
    {
        if(!$package->getSourceReference()) {
            throw new \InvalidArgumentException('The given package is missing reference information');
        }

        $url = escapeshellarg($package->getSourceUrl());
        $ref = escapeshellarg($package->getSourceReference());
        Filesystem::runProcess(sprintf('svn co %s/%s %s', $url, $ref, $path));
    }

    /**
     * {@inheritDoc}
     */
    public function update(PackageInterface $initial, PackageInterface $target, $path)
    {
        if(!$target->getSourceReference()) {
            throw new \InvalidArgumentException('The given package is missing reference information');
        }

        $url = escapeshellarg($target->getSourceUrl());
        $ref = escapeshellarg($target->getSourceReference());
        Filesystem::runProcess(sprintf('cd %s && svn switch %s/%s', $path, $url, $ref));
    }

    /**
     * {@inheritDoc}
     */
    public function remove(PackageInterface $package, $path)
    {
        $fs = new Util\Filesystem();
        $fs->removeDirectory($path);
    }
}