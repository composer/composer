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
use Composer\Util\IniHelper;
use Composer\Util\Platform;
use Composer\Util\ProcessExecutor;
use Symfony\Component\Process\ExecutableFinder;
use React\Promise\PromiseInterface;
use ZipArchive;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class ZipDownloader extends ArchiveDownloader
{
    private static $unzipCommands;
    private static $hasZipArchive;
    private static $isWindows;

    /** @var ZipArchive|null */
    private $zipArchiveObject;

    /**
     * {@inheritDoc}
     */
    public function download(PackageInterface $package, $path, PackageInterface $prevPackage = null, $output = true)
    {
        if (null === self::$unzipCommands) {
            self::$unzipCommands = array();
            $finder = new ExecutableFinder;
            if (Platform::isWindows() && ($cmd = $finder->find('7z', null, array('C:\Program Files\7-Zip')))) {
                self::$unzipCommands[] = array('7z', ProcessExecutor::escape($cmd).' x -bb0 -y %s -o%s');
            }
            if ($cmd = $finder->find('unzip')) {
                self::$unzipCommands[] = array('unzip', ProcessExecutor::escape($cmd).' -qq %s -d %s');
            }
            if (!Platform::isWindows() && ($cmd = $finder->find('7z'))) { // 7z linux/macOS support is only used if unzip is not present
                self::$unzipCommands[] = array('7z', ProcessExecutor::escape($cmd).' x -bb0 -y %s -o%s');
            }
            if (!Platform::isWindows() && ($cmd = $finder->find('7zz'))) { // 7zz linux/macOS support is only used if unzip is not present
                self::$unzipCommands[] = array('7zz', ProcessExecutor::escape($cmd).' x -bb0 -y %s -o%s');
            }
        }

        if (null === self::$hasZipArchive) {
            self::$hasZipArchive = class_exists('ZipArchive');
        }

        if (!self::$hasZipArchive && !self::$unzipCommands) {
            // php.ini path is added to the error message to help users find the correct file
            $iniMessage = IniHelper::getMessage();
            $error = "The zip extension and unzip/7z commands are both missing, skipping.\n" . $iniMessage;

            throw new \RuntimeException($error);
        }

        if (null === self::$isWindows) {
            self::$isWindows = Platform::isWindows();

            if (!self::$isWindows && !self::$unzipCommands) {
                $this->io->writeError("<warning>As there is no 'unzip' nor '7z' command installed zip files are being unpacked using the PHP zip extension.</warning>");
                $this->io->writeError("<warning>This may cause invalid reports of corrupted archives. Besides, any UNIX permissions (e.g. executable) defined in the archives will be lost.</warning>");
                $this->io->writeError("<warning>Installing 'unzip' or '7z' may remediate them.</warning>");
            }
        }

        return parent::download($package, $path, $prevPackage, $output);
    }

    /**
     * extract $file to $path with "unzip" command
     *
     * @param  string           $file File to extract
     * @param  string           $path Path where to extract file
     * @return PromiseInterface
     */
    private function extractWithSystemUnzip(PackageInterface $package, $file, $path)
    {
        // Force Exception throwing if the other alternative extraction method is not available
        $isLastChance = !self::$hasZipArchive;

        if (!self::$unzipCommands) {
            // This was call as the favorite extract way, but is not available
            // We switch to the alternative
            return $this->extractWithZipArchive($package, $file, $path);
        }

        $commandSpec = reset(self::$unzipCommands);
        $command = sprintf($commandSpec[1], ProcessExecutor::escape($file), ProcessExecutor::escape($path));
        $executable = $commandSpec[0];

        $self = $this;
        $io = $this->io;
        $tryFallback = function ($processError) use ($isLastChance, $io, $self, $file, $path, $package, $executable) {
            if ($isLastChance) {
                throw $processError;
            }

            if (!is_file($file)) {
                $io->writeError('    <warning>'.$processError->getMessage().'</warning>');
                $io->writeError('    <warning>This most likely is due to a custom installer plugin not handling the returned Promise from the downloader</warning>');
                $io->writeError('    <warning>See https://github.com/composer/installers/commit/5006d0c28730ade233a8f42ec31ac68fb1c5c9bb for an example fix</warning>');
            } else {
                $io->writeError('    <warning>'.$processError->getMessage().'</warning>');
                $io->writeError('    The archive may contain identical file names with different capitalization (which fails on case insensitive filesystems)');
                $io->writeError('    Unzip with '.$executable.' command failed, falling back to ZipArchive class');
            }

            return $self->extractWithZipArchive($package, $file, $path);
        };

        try {
            $promise = $this->process->executeAsync($command);

            return $promise->then(function ($process) use ($tryFallback, $command, $package, $file) {
                if (!$process->isSuccessful()) {
                    $output = $process->getErrorOutput();
                    $output = str_replace(', '.$file.'.zip or '.$file.'.ZIP', '', $output);

                    return $tryFallback(new \RuntimeException('Failed to extract '.$package->getName().': ('.$process->getExitCode().') '.$command."\n\n".$output));
                }
            });
        } catch (\Exception $e) {
            return $tryFallback($e);
        } catch (\Throwable $e) {
            return $tryFallback($e);
        }
    }

    /**
     * extract $file to $path with ZipArchive
     *
     * @param  string           $file File to extract
     * @param  string           $path Path where to extract file
     * @return PromiseInterface
     *
     * TODO v3 should make this private once we can drop PHP 5.3 support
     * @protected
     */
    public function extractWithZipArchive(PackageInterface $package, $file, $path)
    {
        $processError = null;
        $zipArchive = $this->zipArchiveObject ?: new ZipArchive();

        try {
            if (true === ($retval = $zipArchive->open($file))) {
                $extractResult = $zipArchive->extractTo($path);

                if (true === $extractResult) {
                    $zipArchive->close();

                    return \React\Promise\resolve();
                }

                $processError = new \RuntimeException(rtrim("There was an error extracting the ZIP file, it is either corrupted or using an invalid format.\n"));
            } else {
                $processError = new \UnexpectedValueException(rtrim($this->getErrorMessage($retval, $file)."\n"), $retval);
            }
        } catch (\ErrorException $e) {
            $processError = new \RuntimeException('The archive may contain identical file names with different capitalization (which fails on case insensitive filesystems): '.$e->getMessage(), 0, $e);
        } catch (\Exception $e) {
            $processError = $e;
        } catch (\Throwable $e) {
            $processError = $e;
        }

        throw $processError;
    }

    /**
     * extract $file to $path
     *
     * @param  string                $file File to extract
     * @param  string                $path Path where to extract file
     * @return PromiseInterface|null
     *
     * TODO v3 should make this private once we can drop PHP 5.3 support
     * @protected
     */
    public function extract(PackageInterface $package, $file, $path)
    {
        return $this->extractWithSystemUnzip($package, $file, $path);
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
