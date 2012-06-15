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

namespace Composer\Package\Dumper;

use Composer\Package\Dumper\BaseDumper;
use Composer\Package\Dumper\DumperInterface;
use Composer\Package\PackageInterface;
use Composer\Util\ProcessExecutor;
use Composer\Downloader\GitDownloader;
use Composer\IO\NullIO;

/**
 * @author Till Klampaeckel <till@php.net>
 */
class ZipDumper extends BaseDumper implements DumperInterface
{
    protected $format = 'zip';

    public function dump(PackageInterface $package)
    {
        $workDir = sprintf('%s/zip/%s', $this->temp, $package->getName());
        if (!file_exists($workDir)) {
            mkdir($workDir, 0777, true);
        }

        $fileName   = $this->getFilename($package, 'zip');
        $process    = new ProcessExecutor;
        $sourceType = $package->getSourceType();
        $sourceRef  = $package->getSourceReference();

        switch ($sourceType) {
        case 'git':
            $downloader = new GitDownloader(
                new NullIO(),
                $process
            );
            $downloader->download($package, $workDir);

            $command = sprintf(
                'git archive --format %s --output %s %s',
                $this->format,
                sprintf('%s/%s', $cwd, $fileName),
                $sourceRef
            );

            $process->execute($command, $output, $workDir);

            break;

        default:
            throw new \InvalidArgumentException("Unable to handle repos of type {$sourceType} currently");
        }
    }
}