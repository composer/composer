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

namespace Composer\Util;

use Composer\Spdx\SpdxLicenses;

/**
 * Supports composer array and SPDX tag notation for disjunctive/conjunctive
 * licenses.
 *
 * @author Tom Klingenberg <tklingenberg@lastflood.net>
 *
 * @deprecated use Composer\Spdx\SpdxLicenses instead
 */
class SpdxLicense extends SpdxLicenses
{
    public function __construct()
    {
        parent::__construct();

        trigger_error(__CLASS__ . ' is deprecated, use Composer\\Spdx\\SpdxLicenses instead', E_USER_DEPRECATED);
    }
}
