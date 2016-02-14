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
use Composer\Util\Platform;
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
        $url = $package->getDistUrl();
        $realUrl = realpath($url);
        if (false === $realUrl || !file_exists($realUrl) || !is_dir($realUrl)) {
            throw new \RuntimeException(sprintf(
                'Source path "%s" is not found for package %s', $url, $package->getName()
            ));
        }

        if (strpos(realpath($path) . DIRECTORY_SEPARATOR, $realUrl . DIRECTORY_SEPARATOR) === 0) {
            throw new \RuntimeException(sprintf(
                'Package %s cannot install to "%s" inside its source at "%s"',
                $package->getName(), realpath($path), $realUrl
            ));
        }

        $fileSystem = new Filesystem();
        $this->filesystem->removeDirectory($path);

        $this->io->writeError(sprintf(
            '  - Installing <info>%s</info> (<comment>%s</comment>)',
            $package->getName(),
            $package->getFullPrettyVersion()
        ));

        try {
            if (Platform::isWindows()) {
                // Implement symlinks as NTFS junctions on Windows
                $this->filesystem->junction($realUrl, $path);
                $this->io->writeError(sprintf('    Junctioned from %s', $url));

            } else {
                $shortestPath = $this->filesystem->findShortestPath($path, $realUrl);
                $fileSystem->symlink($shortestPath, $path);
                $this->io->writeError(sprintf('    Symlinked from %s', $url));
            }
        } catch (IOException $e) {
            $fileSystem->mirror($realUrl, $path);
            $this->io->writeError(sprintf('    Mirrored from %s', $url));
        }

        $this->io->writeError('');
    }
}
