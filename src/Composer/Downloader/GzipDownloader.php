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
use Composer\Util\ProcessExecutor;
use Composer\IO\IOInterface;

/**
 * GZip archive downloader.
 *
 * @author Pavel Puchkin <i@neoascetic.me>
 */
class GzipDownloader extends ArchiveDownloader
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

        // Try to use gunzip on *nix
        if (!defined('PHP_WINDOWS_VERSION_BUILD')) {
            $targetFile = $path . '/' . basename(substr($file, 0, -3));
            $command = 'gzip -cd ' . escapeshellarg($file) . ' > ' . escapeshellarg($targetFile);

            if (0 === $this->process->execute($command, $ignoredOutput)) {
                return;
            }

            $processError = 'Failed to execute ' . $command . "\n\n" . $this->process->getErrorOutput();
            throw new \RuntimeException($processError);
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function getFileName(PackageInterface $package, $path)
    {
        return $path.'/'.pathinfo(parse_url($package->getDistUrl(), PHP_URL_PATH), PATHINFO_BASENAME);
    }
}

