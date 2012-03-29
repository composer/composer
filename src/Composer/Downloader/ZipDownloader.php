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
class ZipDownloader extends ArchiveDownloader
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
            $this->handleZipError($retval, $file);
        }

        $zipArchive->extractTo($path);
        $zipArchive->close();
    }

    /**
     * Handle the error and give a meaningful error message to the user.
     *
     * @param int $retval
     * @param string $file
     *
     * @throws \UnexpectedValueException
     */
    protected function handleZipError($retval, $file)
    {
        switch ($retval) {
        case \ZIPARCHIVE::ER_EXISTS:
            throw new \UnexpectedValueException(sprintf("File '%s' already exists.", $file));
        case \ZIPARCHIVE::ER_INCONS:
            throw new \UnexpectedValueException(sprintf("Zip archive '%s' is inconsistent.", $file));
        case \ZIPARCHIVE::ER_INVAL:
            throw new \UnexpectedValueException(sprintf("Invalid argument. (%s)", $file));
        case \ZIPARCHIVE::ER_MEMORY:
            throw new \UnexpectedValueException(sprintf("Malloc failure. (%s)", $file));
        case \ZIPARCHIVE::ER_NOENT:
            throw new \UnexpectedValueException(sprintf("No such file: '%s'", $file));
        case \ZIPARCHIVE::ER_NOZIP:
            throw new \UnexpectedValueException(sprintf("'%s' is not a zip archive.", $file));
        case \ZIPARCHIVE::ER_OPEN:
            throw new \UnexpectedValueException(sprintf("Can't open file: %s", $file));
        case \ZIPARCHIVE::ER_READ:
            throw new \UnexpectedValueException(sprintf("Read error. (%s)", $file));
        case \ZIPARCHIVE::ER_SEEK:
            throw new \UnexpectedValueException(sprintf("Seek error. (%s)", $file));
        default:
            throw new \UnexpectedValueException(
                sprintf("'%s' is not a valid zip archive, got error code: %s", $file, $retval)
            );
        }
    }
}
