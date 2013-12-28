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

use Composer\Config;
use Composer\Cache;
use Composer\EventDispatcher\EventDispatcher;
use Composer\Util\ProcessExecutor;
use Composer\IO\IOInterface;
use ZipArchive;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class ZipDownloader extends ArchiveDownloader
{
    protected $process;

    public function __construct(IOInterface $io, Config $config, EventDispatcher $eventDispatcher = null, Cache $cache = null, ProcessExecutor $process = null)
    {
        $this->process = $process ?: new ProcessExecutor($io);
        parent::__construct($io, $config, $eventDispatcher, $cache);
    }

    protected function extract($file, $path)
    {
        $processError = null;

        // try to use unzip on *nix
        if (!defined('PHP_WINDOWS_VERSION_BUILD')) {
            $command = 'unzip '.escapeshellarg($file).' -d '.escapeshellarg($path) . ' && chmod -R u+w ' . escapeshellarg($path);
            if (0 === $this->process->execute($command, $ignoredOutput)) {
                return;
            }

            $processError = 'Failed to execute ' . $command . "\n\n" . $this->process->getErrorOutput();
        }

        if (!class_exists('ZipArchive')) {
            // php.ini path is added to the error message to help users find the correct file
            $iniPath = php_ini_loaded_file();

            if ($iniPath) {
                $iniMessage = 'The php.ini used by your command-line PHP is: ' . $iniPath;
            } else {
                $iniMessage = 'A php.ini file does not exist. You will have to create one.';
            }

            $error = "Could not decompress the archive, enable the PHP zip extension or install unzip.\n"
                . $iniMessage . "\n" . $processError;

            if (!defined('PHP_WINDOWS_VERSION_BUILD')) {
                $error = "Could not decompress the archive, enable the PHP zip extension.\n" . $iniMessage;
            }

            throw new \RuntimeException($error);
        }

        $zipArchive = new ZipArchive();

        if (true !== ($retval = $zipArchive->open($file))) {
            throw new \UnexpectedValueException($this->getErrorMessage($retval, $file), $retval);
        }

        if (true !== $zipArchive->extractTo($path)) {
            throw new \RuntimeException("There was an error extracting the ZIP file. Corrupt file?");
        }

        $zipArchive->close();
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
