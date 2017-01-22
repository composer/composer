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
use Composer\Package\PackageInterface;
use Composer\Util\IniHelper;
use Composer\Util\Platform;
use Composer\Util\ProcessExecutor;
use Composer\Util\RemoteFilesystem;
use Composer\IO\IOInterface;
use Symfony\Component\Process\ExecutableFinder;
use ZipArchive;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class ZipDownloader extends ArchiveDownloader
{
    protected $process;
    protected static $hasSystemUnzip;

    public function __construct(IOInterface $io, Config $config, EventDispatcher $eventDispatcher = null, Cache $cache = null, ProcessExecutor $process = null, RemoteFilesystem $rfs = null)
    {
        $this->process = $process ?: new ProcessExecutor($io);
        parent::__construct($io, $config, $eventDispatcher, $cache, $rfs);
    }

    /**
     * {@inheritDoc}
     */
    public function download(PackageInterface $package, $path, $output = true)
    {
        if (null === self::$hasSystemUnzip) {
            $finder = new ExecutableFinder;
            self::$hasSystemUnzip = (bool) $finder->find('unzip');
        }

        if (!class_exists('ZipArchive') && !self::$hasSystemUnzip) {
            // php.ini path is added to the error message to help users find the correct file
            $iniMessage = IniHelper::getMessage();
            $error = "The zip extension and unzip command are both missing, skipping.\n" . $iniMessage;

            throw new \RuntimeException($error);
        }

        return parent::download($package, $path, $output);
    }

    protected function extract($file, $path)
    {
        $processError = null;

        if (self::$hasSystemUnzip && !(class_exists('ZipArchive') && Platform::isWindows())) {
            $command = 'unzip -qq '.ProcessExecutor::escape($file).' -d '.ProcessExecutor::escape($path);
            if (!Platform::isWindows()) {
                $command .= ' && chmod -R u+w ' . ProcessExecutor::escape($path);
            }

            try {
                if (0 === $this->process->execute($command, $ignoredOutput)) {
                    return;
                }

                $processError = 'Failed to execute ' . $command . "\n\n" . $this->process->getErrorOutput();
            } catch (\Exception $e) {
                $processError = 'Failed to execute ' . $command . "\n\n" . $e->getMessage();
            }

            if (!class_exists('ZipArchive')) {
                throw new \RuntimeException($processError);
            }
        }

        $zipArchive = new ZipArchive();

        if (true !== ($retval = $zipArchive->open($file))) {
            throw new \UnexpectedValueException(rtrim($this->getErrorMessage($retval, $file)."\n".$processError), $retval);
        }

        if (true !== $zipArchive->extractTo($path)) {
            throw new \RuntimeException(rtrim("There was an error extracting the ZIP file, it is either corrupted or using an invalid format.\n".$processError));
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
