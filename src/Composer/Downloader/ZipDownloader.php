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
use Composer\IO\IOInterface;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class ZipDownloader extends FileDownloader
{
    protected $process;

    public function __construct(IOInterface $io, ProcessExecutor $process = null)
    {
        $this->process = $process ?: new ProcessExecutor;
        parent::__construct($io);
    }

    protected function extract($file, $path)
    {
        if (!class_exists('ZipArchive')) {
            // try to use unzip on *nix
            if (!defined('PHP_WINDOWS_VERSION_BUILD')) {
                $result = $this->process->execute('unzip '.escapeshellarg($file).' -d '.escapeshellarg($path));
                if (0 == $result) {
                    return;
                }
            }

            throw new \RuntimeException('You need the zip extension enabled to use the ZipDownloader');
        }

        $zipArchive = new \ZipArchive();

        if (true !== ($retval = $zipArchive->open($file))) {
            throw new \UnexpectedValueException($file.' is not a valid zip archive, got error code '.$retval);
        }

        $zipArchive->extractTo($path);
        $zipArchive->close();
    }
}
