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
use Composer\Util\Platform;
use Composer\Util\ProcessExecutor;
use React\Promise\PromiseInterface;

/**
 * GZip archive downloader.
 *
 * @author Pavel Puchkin <i@neoascetic.me>
 */
class GzipDownloader extends ArchiveDownloader
{
    protected function extract(PackageInterface $package, string $file, string $path): PromiseInterface
    {
        $filename = pathinfo(parse_url(strtr((string) $package->getDistUrl(), '\\', '/'), PHP_URL_PATH), PATHINFO_FILENAME);
        $targetFilepath = $path . DIRECTORY_SEPARATOR . $filename;

        // Try to use gunzip on *nix
        if (!Platform::isWindows()) {
            $command = 'gzip -cd -- ' . ProcessExecutor::escape($file) . ' > ' . ProcessExecutor::escape($targetFilepath);

            if (0 === $this->process->execute($command, $ignoredOutput)) {
                return \React\Promise\resolve(null);
            }

            if (extension_loaded('zlib')) {
                // Fallback to using the PHP extension.
                $this->extractUsingExt($file, $targetFilepath);

                return \React\Promise\resolve(null);
            }

            $processError = 'Failed to execute ' . $command . "\n\n" . $this->process->getErrorOutput();
            throw new \RuntimeException($processError);
        }

        // Windows version of PHP has built-in support of gzip functions
        $this->extractUsingExt($file, $targetFilepath);

        return \React\Promise\resolve(null);
    }

    /**
     * @param string $file
     * @param string $targetFilepath
     *
     * @return void
     */
    private function extractUsingExt(string $file, string $targetFilepath): void
    {
        $archiveFile = gzopen($file, 'rb');
        $targetFile = fopen($targetFilepath, 'wb');
        while ($string = gzread($archiveFile, 4096)) {
            fwrite($targetFile, $string, Platform::strlen($string));
        }
        gzclose($archiveFile);
        fclose($targetFile);
    }
}
