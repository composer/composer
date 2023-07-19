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

namespace Composer\Util;

use Composer\Downloader\DownloaderInterface;
use Composer\Downloader\DownloadManager;
use Composer\Package\PackageInterface;
use React\Promise\PromiseInterface;

class SyncHelper
{
    /**
     * Helps you download + install a single package in a synchronous way
     *
     * This executes all the required steps and waits for promises to complete
     *
     * @param Loop                                $loop        Loop instance which you can get from $composer->getLoop()
     * @param DownloaderInterface|DownloadManager $downloader  DownloadManager instance or Downloader instance you can get from $composer->getDownloadManager()->getDownloader('zip') for example
     * @param string                              $path        The installation path for the package
     * @param PackageInterface                    $package     The package to install
     * @param PackageInterface|null               $prevPackage The previous package if this is an update and not an initial installation
     */
    public static function downloadAndInstallPackageSync(Loop $loop, $downloader, string $path, PackageInterface $package, ?PackageInterface $prevPackage = null): void
    {
        assert($downloader instanceof DownloaderInterface || $downloader instanceof DownloadManager);

        $type = $prevPackage !== null ? 'update' : 'install';

        try {
            self::await($loop, $downloader->download($package, $path, $prevPackage));

            self::await($loop, $downloader->prepare($type, $package, $path, $prevPackage));

            if ($type === 'update' && $prevPackage !== null) {
                self::await($loop, $downloader->update($package, $prevPackage, $path));
            } else {
                self::await($loop, $downloader->install($package, $path));
            }
        } catch (\Exception $e) {
            self::await($loop, $downloader->cleanup($type, $package, $path, $prevPackage));
            throw $e;
        }

        self::await($loop, $downloader->cleanup($type, $package, $path, $prevPackage));
    }

    /**
     * Waits for a promise to resolve
     *
     * @param Loop                  $loop    Loop instance which you can get from $composer->getLoop()
     * @phpstan-param PromiseInterface<mixed>|null $promise
     */
    public static function await(Loop $loop, ?PromiseInterface $promise = null): void
    {
        if ($promise !== null) {
            $loop->wait([$promise]);
        }
    }
}
