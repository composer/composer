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
        throw new \InvalidArgumentException('The PEAR repository has been removed from Composer 2.x');
    }
}
