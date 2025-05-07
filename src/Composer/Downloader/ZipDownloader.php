<?php declare(strict_types=1);

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
use Composer\Pcre\Preg;
use Composer\Util\IniHelper;
use Composer\Util\Platform;
use Composer\Util\ProcessExecutor;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use React\Promise\PromiseInterface;
use ZipArchive;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class ZipDownloader extends ArchiveDownloader
{
    /** @var array<int, non-empty-list<string>> */
    private static $unzipCommands;
    /** @var bool */
    private static $hasZipArchive;
    /** @var bool */
    private static $isWindows;

    /** @var ZipArchive|null */
    private $zipArchiveObject; // @phpstan-ignore property.onlyRead (helper property that is set via reflection for testing purposes)

    /**
     * @inheritDoc
     */
    public function download(PackageInterface $package, string $path, ?PackageInterface $prevPackage = null, bool $output = true): PromiseInterface
    {
        if (null === self::$unzipCommands) {
            self::$unzipCommands = [];
            $finder = new ExecutableFinder;
            if (Platform::isWindows() && ($cmd = $finder->find('7z', null, ['C:\Program Files\7-Zip']))) {
                self::$unzipCommands[] = ['7z', $cmd, 'x', '-bb0', '-y', '%file%', '-o%path%'];
            }
            if ($cmd = $finder->find('unzip')) {
                self::$unzipCommands[] = ['unzip', $cmd, '-qq', '%file%', '-d', '%path%'];
            }
            if (!Platform::isWindows() && ($cmd = $finder->find('7z'))) { // 7z linux/macOS support is only used if unzip is not present
                self::$unzipCommands[] = ['7z', $cmd, 'x', '-bb0', '-y', '%file%', '-o%path%'];
            }
            if (!Platform::isWindows() && ($cmd = $finder->find('7zz'))) { // 7zz linux/macOS support is only used if unzip is not present
                self::$unzipCommands[] = ['7zz', $cmd, 'x', '-bb0', '-y', '%file%', '-o%path%'];
            }
        }

        $procOpenMissing = false;
        if (!function_exists('proc_open')) {
            self::$unzipCommands = [];
            $procOpenMissing = true;
        }

        if (null === self::$hasZipArchive) {
            self::$hasZipArchive = class_exists('ZipArchive');
        }

        if (!self::$hasZipArchive && !self::$unzipCommands) {
            // php.ini path is added to the error message to help users find the correct file
            $iniMessage = IniHelper::getMessage();
            if ($procOpenMissing) {
                $error = "The zip extension is missing and unzip/7z commands cannot be called as proc_open is disabled, skipping.\n" . $iniMessage;
            } else {
                $error = "The zip extension and unzip/7z commands are both missing, skipping.\n" . $iniMessage;
            }

            throw new \RuntimeException($error);
        }

        if (null === self::$isWindows) {
            self::$isWindows = Platform::isWindows();

            if (!self::$isWindows && !self::$unzipCommands) {
                if ($procOpenMissing) {
                    $this->io->writeError("<warning>proc_open is disabled so 'unzip' and '7z' commands cannot be used, zip files are being unpacked using the PHP zip extension.</warning>");
                    $this->io->writeError("<warning>This may cause invalid reports of corrupted archives. Besides, any UNIX permissions (e.g. executable) defined in the archives will be lost.</warning>");
                    $this->io->writeError("<warning>Enabling proc_open and installing 'unzip' or '7z' (21.01+) may remediate them.</warning>");
                } else {
                    $this->io->writeError("<warning>As there is no 'unzip' nor '7z' command installed zip files are being unpacked using the PHP zip extension.</warning>");
                    $this->io->writeError("<warning>This may cause invalid reports of corrupted archives. Besides, any UNIX permissions (e.g. executable) defined in the archives will be lost.</warning>");
                    $this->io->writeError("<warning>Installing 'unzip' or '7z' (21.01+) may remediate them.</warning>");
                }
            }
        }

        return parent::download($package, $path, $prevPackage, $output);
    }

    /**
     * extract $file to $path with "unzip" command
     *
     * @param  string           $file File to extract
     * @param  string           $path Path where to extract file
     * @phpstan-return PromiseInterface<void|null>
     */
    private function extractWithSystemUnzip(PackageInterface $package, string $file, string $path): PromiseInterface
    {
        static $warned7ZipLinux = false;

        // Force Exception throwing if the other alternative extraction method is not available
        $isLastChance = !self::$hasZipArchive;

        if (0 === \count(self::$unzipCommands)) {
            // This was call as the favorite extract way, but is not available
            // We switch to the alternative
            return $this->extractWithZipArchive($package, $file, $path);
        }

        $commandSpec = reset(self::$unzipCommands);
        $executable = $commandSpec[0];
        $command = array_slice($commandSpec, 1);
        $map = [
            // normalize separators to backslashes to avoid problems with 7-zip on windows
            // see https://github.com/composer/composer/issues/10058
            '%file%' => strtr($file, '/', DIRECTORY_SEPARATOR),
            '%path%' => strtr($path, '/', DIRECTORY_SEPARATOR),
        ];
        $command = array_map(static function ($value) use ($map) {
            return strtr($value, $map);
        }, $command);

        if (!$warned7ZipLinux && !Platform::isWindows() && in_array($executable, ['7z', '7zz'], true)) {
            $warned7ZipLinux = true;
            if (0 === $this->process->execute([$commandSpec[1]], $output)) {
                if (Preg::isMatchStrictGroups('{^\s*7-Zip(?: \[64\])? ([0-9.]+)}', $output, $match) && version_compare($match[1], '21.01', '<')) {
                    $this->io->writeError('    <warning>Unzipping using '.$executable.' '.$match[1].' may result in incorrect file permissions. Install '.$executable.' 21.01+ or unzip to ensure you get correct permissions.</warning>');
                }
            }
        }

        $io = $this->io;
        $tryFallback = function (\Throwable $processError) use ($isLastChance, $io, $file, $path, $package, $executable): \React\Promise\PromiseInterface {
            if ($isLastChance) {
                throw $processError;
            }

            if (str_contains($processError->getMessage(), 'zip bomb')) {
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

                // additional debug data to try to figure out GH actions issues https://github.com/composer/composer/issues/11148
                if (Platform::getEnv('GITHUB_ACTIONS') !== false && Platform::getEnv('COMPOSER_TESTS_ARE_RUNNING') === false) {
                    $io->writeError('    <warning>Additional debug info, please report to https://github.com/composer/composer/issues/11148 if you see this:</warning>');
                    $io->writeError('File size: '.@filesize($file));
                    $io->writeError('File SHA1: '.hash_file('sha1', $file));
                    $io->writeError('First 100 bytes (hex): '.bin2hex(substr((string) file_get_contents($file), 0, 100)));
                    $io->writeError('Last 100 bytes (hex): '.bin2hex(substr((string) file_get_contents($file), -100)));
                    if (strlen((string) $package->getDistUrl()) > 0) {
                        $io->writeError('Origin URL: '.$this->processUrl($package, (string) $package->getDistUrl()));
                        $io->writeError('Response Headers: '.json_encode(FileDownloader::$responseHeaders[$package->getName()] ?? []));
                    }
                }
            }

            return $this->extractWithZipArchive($package, $file, $path);
        };

        try {
            $promise = $this->process->executeAsync($command);

            return $promise->then(function (Process $process) use ($tryFallback, $command, $package, $file) {
                if (!$process->isSuccessful()) {
                    if (isset($this->cleanupExecuted[$package->getName()])) {
                        throw new \RuntimeException('Failed to extract '.$package->getName().' as the installation was aborted by another package operation.');
                    }

                    $output = $process->getErrorOutput();
                    $output = str_replace(', '.$file.'.zip or '.$file.'.ZIP', '', $output);

                    return $tryFallback(new \RuntimeException('Failed to extract '.$package->getName().': ('.$process->getExitCode().') '.implode(' ', $command)."\n\n".$output));
                }
            });
        } catch (\Throwable $e) {
            return $tryFallback($e);
        }
    }

    /**
     * extract $file to $path with ZipArchive
     *
     * @param  string           $file File to extract
     * @param  string           $path Path where to extract file
     * @phpstan-return PromiseInterface<void|null>
     */
    private function extractWithZipArchive(PackageInterface $package, string $file, string $path): PromiseInterface
    {
        $processError = null;
        $zipArchive = $this->zipArchiveObject ?: new ZipArchive();

        try {
            if (!file_exists($file) || ($filesize = filesize($file)) === false || $filesize === 0) {
                $retval = -1;
            } else {
                $retval = $zipArchive->open($file);
            }

            if (true === $retval) {
                $totalSize = 0;
                $archiveSize = filesize($file);
                $totalFiles = $zipArchive->count();
                if ($totalFiles > 0) {
                    for ($i = 0; $i < min($totalFiles, 5); $i++) {
                        $stat = $zipArchive->statIndex(random_int(0, $totalFiles - 1));
                        if ($stat === false) {
                            continue;
                        }
                        $totalSize += $stat['size'];
                        if ($stat['size'] > $stat['comp_size'] * 200) {
                            throw new \RuntimeException('Invalid zip file "'.$stat['name'].'" for "'.$package->getName().'" with compression ratio >99% (possible zip bomb)');
                        }
                    }
                    if ($archiveSize !== false && $totalSize > $archiveSize * 100 && $totalSize > 50*1024*1024) {
                        throw new \RuntimeException('Invalid zip file for "'.$package->getName().'" with compression ratio >99% (possible zip bomb)');
                    }
                }

                $extractResult = $zipArchive->extractTo($path);

                if (true === $extractResult) {
                    $zipArchive->close();

                    return \React\Promise\resolve(null);
                }

                $processError = new \RuntimeException(rtrim("There was an error extracting the ZIP file for \"{$package->getName()}\", it is either corrupted or using an invalid format.\n"));
            } else {
                $processError = new \UnexpectedValueException(rtrim($this->getErrorMessage($retval, $file)."\n"), $retval);
            }
        } catch (\ErrorException $e) {
            $processError = new \RuntimeException('The archive for "'.$package->getName().'" may contain identical file names with different capitalization (which fails on case insensitive filesystems): '.$e->getMessage(), 0, $e);
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
     */
    protected function extract(PackageInterface $package, string $file, string $path): PromiseInterface
    {
        return $this->extractWithSystemUnzip($package, $file, $path);
    }

    /**
     * Give a meaningful error message to the user.
     */
    protected function getErrorMessage(int $retval, string $file): string
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
            case -1:
                return sprintf("'%s' is a corrupted zip archive (0 bytes), try again.", $file);
            default:
                return sprintf("'%s' is not a valid zip archive, got error code: %s", $file, $retval);
        }
    }
}
