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
 * Downloader for pear packages
 *
 * @author Jordi Boggiano <j.boggiano@seld.be>
 * @author Kirill chEbba Chebunin <iam@chebba.org>
 */
class PearDownloader extends FileDownloader
{
    /**
     * {@inheritDoc}
     */
    public function download(PackageInterface $package, $path)
    {
        parent::download($package, $path);

        $fileName = $this->getFileName($package, $path);
        if ($this->io->isVerbose()) {
            $this->io->write('    Installing PEAR package');
        }
        try {
            $pearExtractor = new PearPackageExtractor($fileName);
            $pearExtractor->extractTo($path);

            if ($this->io->isVerbose()) {
                $this->io->write('    Cleaning up');
            }
            unlink($fileName);
        } catch (\Exception $e) {
            // clean up
            $this->filesystem->removeDirectory($path);
            throw $e;
        }

        $this->io->write('');
    }
}
