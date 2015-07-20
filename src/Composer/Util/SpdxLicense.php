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

@trigger_error('The ' . __NAMESPACE__ . '\SpdxLicense class is deprecated, use Composer\Spdx\SpdxLicenses instead.', E_USER_DEPRECATED);

/**
 * @deprecated use Composer\Spdx\SpdxLicenses instead
 */
class SpdxLicense extends SpdxLicenses
{
}
