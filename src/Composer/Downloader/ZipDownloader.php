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
    protected static $hasSystemUnzip;
    private static $hasZipArchive;
    private static $isWindows;

    protected $process;
    private $zipArchiveObject;

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

        if (null === self::$hasZipArchive) {
            self::$hasZipArchive = class_exists('ZipArchive');
        }

        if (!self::$hasZipArchive && !self::$hasSystemUnzip) {
            // php.ini path is added to the error message to help users find the correct file
            $iniMessage = IniHelper::getMessage();
            $error = "The zip extension and unzip command are both missing, skipping.\n" . $iniMessage;

            throw new \RuntimeException($error);
        }

        if (null === self::$isWindows) {
            self::$isWindows = Platform::isWindows();

            if (!self::$isWindows && !self::$hasSystemUnzip) {
                $this->io->writeError("<warning>As there is no 'unzip' command installed zip files are being unpacked using the PHP zip extension.</warning>");
                $this->io->writeError("<warning>This may cause invalid reports of corrupted archives. Besides, any UNIX permissions (e.g. executable) defined in the archives will be lost.</warning>");
                $this->io->writeError("<warning>Installing 'unzip' may remediate them.</warning>");
            }
        }

        return parent::download($package, $path, $output);
    }

    /**
     * extract $file to $path with "unzip" command
     *
     * @param  string $file         File to extract
     * @param  string $path         Path where to extract file
     * @param  bool   $isLastChance If true it is called as a fallback and should throw an exception
     * @return bool   Success status
     */
    protected function extractWithSystemUnzip($file, $path, $isLastChance)
    {
        if (!self::$hasZipArchive) {
            // Force Exception throwing if the Other alternative is not available
            $isLastChance = true;
        }

        if (!self::$hasSystemUnzip && !$isLastChance) {
            // This was call as the favorite extract way, but is not available
            // We switch to the alternative
            return $this->extractWithZipArchive($file, $path, true);
        }

        $processError = null;
        // When called after a ZipArchive failed, perhaps there is some files to overwrite
        $overwrite = $isLastChance ? '-o' : '';

        $command = 'unzip -qq '.$overwrite.' '.ProcessExecutor::escape($file).' -d '.ProcessExecutor::escape($path);

        try {
            if (0 === $this->process->execute($command, $ignoredOutput)) {
                return true;
            }

            $processError = new \RuntimeException('Failed to execute ' . $command . "\n\n" . $this->process->getErrorOutput());
        } catch (\Exception $e) {
            $processError = $e;
        }

        if ($isLastChance) {
            throw $processError;
        }

        $this->io->writeError('    '.$processError->getMessage());
        $this->io->writeError('    The archive may contain identical file names with different capitalization (which fails on case insensitive filesystems)');
        $this->io->writeError('    Unzip with unzip command failed, falling back to ZipArchive class');

        return $this->extractWithZipArchive($file, $path, true);
    }

    /**
     * extract $file to $path with ZipArchive
     *
     * @param  string $file         File to extract
     * @param  string $path         Path where to extract file
     * @param  bool   $isLastChance If true it is called as a fallback and should throw an exception
     * @return bool   Success status
     */
    protected function extractWithZipArchive($file, $path, $isLastChance)
    {
        if (!self::$hasSystemUnzip) {
            // Force Exception throwing if the Other alternative is not available
            $isLastChance = true;
        }

        if (!self::$hasZipArchive && !$isLastChance) {
            // This was call as the favorite extract way, but is not available
            // We switch to the alternative
            return $this->extractWithSystemUnzip($file, $path, true);
        }

        $processError = null;
        $zipArchive = $this->zipArchiveObject ?: new ZipArchive();

        try {
            if (true === ($retval = $zipArchive->open($file))) {
                $extractResult = $zipArchive->extractTo($path);

                if (true === $extractResult) {
                    $zipArchive->close();

                    return true;
                }

                $processError = new \RuntimeException(rtrim("There was an error extracting the ZIP file, it is either corrupted or using an invalid format.\n"));
            } else {
                $processError = new \UnexpectedValueException(rtrim($this->getErrorMessage($retval, $file)."\n"), $retval);
            }
        } catch (\ErrorException $e) {
            $processError = new \RuntimeException('The archive may contain identical file names with different capitalization (which fails on case insensitive filesystems): '.$e->getMessage(), 0, $e);
        } catch (\Exception $e) {
            $processError = $e;
        }

        if ($isLastChance) {
            throw $processError;
        }

        $this->io->writeError('    '.$processError->getMessage());
        $this->io->writeError('    Unzip with ZipArchive class failed, falling back to unzip command');

        return $this->extractWithSystemUnzip($file, $path, true);
    }

    /**
     * extract $file to $path
     *
     * @param string $file File to extract
     * @param string $path Path where to extract file
     */
    public function extract($file, $path)
    {
        // Each extract calls its alternative if not available or fails
        if (self::$isWindows) {
            $this->extractWithZipArchive($file, $path, false);
        } else {
            $this->extractWithSystemUnzip($file, $path, false);
        }
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
