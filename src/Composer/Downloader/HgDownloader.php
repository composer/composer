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
class HgDownloader implements DownloaderInterface
{
    protected $process;

    public function __construct(ProcessExecutor $process = null)
    {
        $this->process = $process ?: new ProcessExecutor;
    }

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
        $this->process->execute(sprintf('(hg clone %s %s  2> /dev/null) && cd %2$s && hg up %s', $url, $path, $ref));
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
        $this->process->execute(sprintf('cd %s && hg pull && hg up %s', $path, escapeshellarg($target->getSourceReference())));
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
        $this->process->execute(sprintf('cd %s && hg st', $path), $output);
        if (trim($output)) {
            throw new \RuntimeException('Source directory has uncommitted changes');
        }
    }
}
