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
 * @author Ulf Härnhammar <ulfharn@gmail.com>
 */
class TarDumper extends BaseDumper
{
    protected $format = 'tar';

    public function dump(PackageInterface $package)
    {
        $workDir = $this->getAndEnsureWorkDirectory($package);

        $fileName   = $this->getFilename($package, 'tar');
        $sourceType = $package->getSourceType();
        $sourceRef  = $package->getSourceReference();

        switch ($sourceType) {
        case 'git':
            $this->downloadGit($package, $workDir);
            $this->packageGit($fileName, $sourceRef, $workDir);
            break;
        case 'hg':
            $this->downloadHg($package, $workDir);
            $this->packageHg($fileName, $sourceRef, $workDir);
            break;
        case 'svn':
            $dir = $workDir . (substr($sourceRef, 0, 1) !== '/')?'/':'' . $sourceRef;
            $this->downloadSvn($package, $workDir);
            $this->package($fileName, $dir, \Phar::TAR);
            break;
        default:
            throw new \InvalidArgumentException("Unable to handle repositories of type '{$sourceType}'.");
        }
    }
}
