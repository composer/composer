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

use Composer\Util\ProcessExecutor;
use Composer\IO\IOInterface;
use ZipArchive;

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
            $error = 'You need the zip extension enabled to use the ZipDownloader';

            // try to use unzip on *nix
            if (!defined('PHP_WINDOWS_VERSION_BUILD')) {
                $command = 'unzip '.escapeshellarg($file).' -d '.escapeshellarg($path);
                if (0 === $this->process->execute($command, $ignoredOutput)) {
                    return;
                }

                $error = "Could not decompress the archive, enable the PHP zip extension or install unzip.\n".
                    'Failed to execute ' . $command . "\n\n" . $this->process->getErrorOutput();
            }

            throw new \RuntimeException($error);
        }

        $zipArchive = new ZipArchive();

        if (true !== ($retval = $zipArchive->open($file))) {
            throw new \UnexpectedValueException($this->getErrorMessage($retval, $file));
        }

        $zipArchive->extractTo($path);
        $zipArchive->close();

        parent::extract($file, $path);
    }

    /**
     * Give a meaningful error message to the user.
     *
     * @param  int    $retval
     * @param  string $file
     * @return string
     */
    protected function getErrorMessage($retval, $file)
    {
        switch ($retval) {
            case ZipArchive::ER_EXISTS:
                return sprintf("File '%s' already exists.", $file);
            case ZipArchive::ER_INCONS:
                return sprintf("Zip archive '%s' is inconsistent.", $file);
            case ZipArchive::ER_INVAL:
                return sprintf("Invalid argument (%s)", $file);
            case ZipArchive::ER_MEMORY:
                return sprintf("Malloc failure (%s)", $file);
            case ZipArchive::ER_NOENT:
                return sprintf("No such zip file: '%s'", $file);
            case ZipArchive::ER_NOZIP:
                return sprintf("'%s' is not a zip archive.", $file);
            case ZipArchive::ER_OPEN:
                return sprintf("Can't open zip file: %s", $file);
            case ZipArchive::ER_READ:
                return sprintf("Zip read error (%s)", $file);
            case ZipArchive::ER_SEEK:
                return sprintf("Zip seek error (%s)", $file);
            default:
                return sprintf("'%s' is not a valid zip archive, got error code: %s", $file, $retval);
        }
    }
}
