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
use Composer\Util\Svn as SvnUtil;

/**
 * @author Ben Bieker <mail@ben-bieker.de>
 */
class SvnDownloader extends VcsDownloader
{
    /**
     * @var \Composer\Util\Svn $util
     */
    protected $util;

    /**
     * {@inheritDoc}
     */
    public function doDownload(PackageInterface $package, $path)
    {
        $url =  $package->getSourceUrl();
        $ref =  $package->getSourceReference();

        $util = $this->getUtil($url);

        $command = $util->getCommand("svn co", sprintf("%s/%s", $url, $ref), $path);

        $this->io->write("    Checking out ".$package->getSourceReference());
        $this->process->execute($command);
    }

    /**
     * {@inheritDoc}
     */
    public function doUpdate(PackageInterface $initial, PackageInterface $target, $path)
    {
        $url = $target->getSourceUrl();
        $ref = $target->getSourceReference();

        $util    = $this->getUtil($url);
        $command = $util->getCommand("svn switch", sprintf("%s/%s", $url, $ref));

        $this->io->write("    Checking out " . $ref);
        $this->process->execute(sprintf('cd %s && %s', $path, $command));
    }

    /**
     * {@inheritDoc}
     */
    protected function enforceCleanDirectory($path)
    {
        $this->process->execute(sprintf('cd %s && svn status', escapeshellarg($path)), $output);
        if (trim($output)) {
            throw new \RuntimeException('Source directory ' . $path . ' has uncommitted changes');
        }
    }

    /**
     * This is heavy - recreating Util often.
     *
     * @param string $url
     *
     * @return \Composer\Util\Svn
     */
    protected function getUtil($url)
    {
        $util = new SvnUtil($url, $this->io);
        return $util;
    }
}
