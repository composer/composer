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
use Composer\Util\Process;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class GitDownloader implements DownloaderInterface
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
        if (!$package->getSourceReference()) {
            throw new \InvalidArgumentException('The given package is missing reference information');
        }

        $url = escapeshellarg($package->getSourceUrl());
        $ref = escapeshellarg($package->getSourceReference());
        Process::execute(sprintf('git clone %s %s && cd %2$s && git checkout %3$s && git reset --hard %3$s', $url, $path, $ref));
    }

    /**
     * {@inheritDoc}
     */
    public function update(PackageInterface $initial, PackageInterface $target, $path)
    {
        if (!$target->getSourceReference()) {
            throw new \InvalidArgumentException('The given package is missing reference information');
        }

        $this->enforceCleanDirectory($path);
        Process::execute(sprintf('cd %s && git fetch && git checkout %2$s && git reset --hard %2$s', $path, $target->getSourceReference()));
    }

    /**
     * {@inheritDoc}
     */
    public function remove(PackageInterface $package, $path)
    {
        $this->enforceCleanDirectory($path);
        $fs = new Util\Filesystem();
        $fs->removeDirectory($path);
    }

    private function enforceCleanDirectory($path)
    {
        Process::execute(sprintf('cd %s && git status --porcelain', $path),$output);
        if (implode('', $output)) {
            throw new \RuntimeException('Source directory has uncommitted changes');
        }
    }
}
