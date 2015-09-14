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
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Download a package from a local path.
 *
 * @author Samuel Roze <samuel.roze@gmail.com>
 * @author Johann Reinke <johann.reinke@gmail.com>
 */
class PathDownloader extends FileDownloader
{
    /**
     * {@inheritdoc}
     */
    public function download(PackageInterface $package, $path)
    {
        $fileSystem = new Filesystem();
        $this->filesystem->removeDirectory($path);

        $this->io->writeError(sprintf(
            '  - Installing <info>%s</info> (<comment>%s</comment>) from %s',
            $package->getName(),
            $package->getFullPrettyVersion(),
            $package->getDistUrl()
        ));

        if (!file_exists($path) || !is_dir($path)) {
            throw new \RuntimeException(sprintf(
                'Path "%s" is not found',
                $path
            ));
        }

        try {
            $fileSystem->symlink($package->getDistUrl(), $path);
        } catch (IOException $e) {
            $fileSystem->mirror($package->getDistUrl(), $path);
        }
    }
}
