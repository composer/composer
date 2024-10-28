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

use React\Promise\PromiseInterface;
use Composer\Util\IniHelper;
use Composer\Util\Platform;
use Composer\Util\ProcessExecutor;
use Composer\Package\PackageInterface;
use RarArchive;

/**
 * RAR archive downloader.
 *
 * Based on previous work by Jordi Boggiano ({@see ZipDownloader}).
 *
 * @author Derrick Nelson <drrcknlsn@gmail.com>
 */
class RarDownloader extends ArchiveDownloader
{
    protected function extract(PackageInterface $package, string $file, string $path): PromiseInterface
    {
        $processError = null;

        // Try to use unrar on *nix
        if (!Platform::isWindows()) {
            $command = ['sh', '-c', 'unrar x -- "$0" "$1" >/dev/null && chmod -R u+w "$1"', $file, $path];

            if (0 === $this->process->execute($command, $ignoredOutput)) {
                return \React\Promise\resolve(null);
            }

            $processError = 'Failed to execute ' . implode(' ', $command) . "\n\n" . $this->process->getErrorOutput();
        }

        if (!class_exists('RarArchive')) {
            // php.ini path is added to the error message to help users find the correct file
            $iniMessage = IniHelper::getMessage();

            $error = "Could not decompress the archive, enable the PHP rar extension or install unrar.\n"
                . $iniMessage . "\n" . $processError;

            if (!Platform::isWindows()) {
                $error = "Could not decompress the archive, enable the PHP rar extension.\n" . $iniMessage;
            }

            throw new \RuntimeException($error);
        }

        $rarArchive = RarArchive::open($file);

        if (false === $rarArchive) {
            throw new \UnexpectedValueException('Could not open RAR archive: ' . $file);
        }

        $entries = $rarArchive->getEntries();

        if (false === $entries) {
            throw new \RuntimeException('Could not retrieve RAR archive entries');
        }

        foreach ($entries as $entry) {
            if (false === $entry->extract($path)) {
                throw new \RuntimeException('Could not extract entry');
            }
        }

        $rarArchive->close();

        return \React\Promise\resolve(null);
    }
}
