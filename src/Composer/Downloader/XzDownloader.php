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
 * Xz archive downloader.
 *
 * @author Pavel Puchkin <i@neoascetic.me>
 * @author Pierre Rudloff <contact@rudloff.pro>
 */
class XzDownloader extends ArchiveDownloader
{
    protected $process;

    public function __construct(IOInterface $io, Config $config, EventDispatcher $eventDispatcher = null, Cache $cache = null, ProcessExecutor $process = null)
    {
        $this->process = $process ?: new ProcessExecutor($io);

        parent::__construct($io, $config, $eventDispatcher, $cache);
    }

    protected function extract($file, $path)
    {
        $command = 'tar -xJf ' . ProcessExecutor::escape($file) . ' -C ' . ProcessExecutor::escape($path);

        if (0 === $this->process->execute($command, $ignoredOutput)) {
            return;
        }

        $processError = 'Failed to execute ' . $command . "\n\n" . $this->process->getErrorOutput();

        throw new \RuntimeException($processError);
    }

    /**
     * {@inheritdoc}
     */
    protected function getFileName(PackageInterface $package, $path)
    {
        return $path.'/'.pathinfo(parse_url($package->getDistUrl(), PHP_URL_PATH), PATHINFO_BASENAME);
    }
}
