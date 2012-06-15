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

/**
 * @author Till Klampaeckel <till@php.net>
 */
class ZipDumper extends BaseDumper implements DumperInterface
{
    protected $format = 'zip';

    public function dump(PackageInterface $package)
    {
        $workDir = $this->getAndEnsureWorkDirectory($package);

        $fileName   = $this->getFilename($package, 'zip');
        $sourceType = $package->getSourceType();
        $sourceRef  = $package->getSourceReference();

        switch ($sourceType) {
        case 'git':
            $this->downloadGit($package, $workDir);
            $this->packageGit($fileName, $sourceRef, $workDir);
            break;
        default:
            throw new \InvalidArgumentException("Unable to handle repos of type {$sourceType} currently");
        }
    }
}