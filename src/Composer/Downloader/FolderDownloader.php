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
use Composer\Package\Version\VersionParser;

/**
 * Downloader for folders
 *
 * @author Johann Reinke <johann.reinke@gmail.com>
 */
class FolderDownloader extends FileDownloader
{
    /**
     * {@inheritDoc}
     */
    public function download(PackageInterface $package, $path)
    {
        if (is_dir($path)) {
            rmdir($path);
        }
        if (false === symlink($package->getDistUrl(), $path)) {
            throw new \ErrorException();
        }
        $this->io->writeError("  - Installing <info>" . $package->getName() . "</info> (<comment>" . VersionParser::formatVersion($package) . "</comment>)");
        $this->io->writeError('');
    }
}
