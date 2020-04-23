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

namespace Composer\Repository;

use Composer\IO\IOInterface;
use Composer\Semver\VersionParser as SemverVersionParser;
use Composer\Package\Version\VersionParser;
use Composer\Package\CompletePackage;
use Composer\EventDispatcher\EventDispatcher;
use Composer\Package\Link;
use Composer\Semver\Constraint\Constraint;
use Composer\Util\HttpDownloader;
use Composer\Config;
use Composer\Factory;

/**
 * Builds list of package from PEAR channel.
 *
 * Packages read from channel are named as 'pear-{channelName}/{packageName}'
 * and has aliased as 'pear-{channelAlias}/{packageName}'
 *
 * @author Benjamin Eberlei <kontakt@beberlei.de>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 * @deprecated
 * @private
 */
class PearRepository extends ArrayRepository
{
    public function __construct()
    {
        throw new \RuntimeException('The PEAR repository has been removed from Composer 2.0');
    }
}
