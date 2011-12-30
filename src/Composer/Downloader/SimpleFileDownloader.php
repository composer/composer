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

/**
 * Downloader for any simple files
 *
 * @author Martin Hasoň <martin.hason@gmail.com>
 */
class SimpleFileDownloader extends FileDownloader
{
    private $filename;

    /**
     * {@inheritDoc}
     */
    public function download(PackageInterface $package, $path)
    {
        $this->filename = pathinfo($package->getDistUrl(), PATHINFO_BASENAME);
        parent::download($package, $path);
    }

    /**
     * {@inheritDoc}
     */
    protected function extract($file, $path)
    {
        copy($file, $path.'/'.$this->filename);
    }
}
