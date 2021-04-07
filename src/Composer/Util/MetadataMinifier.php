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

@trigger_error('Composer\Util\MetadataMinifier is deprecated, use Composer\MetadataMinifier\MetadataMinifier from composer/metadata-minifier instead.', E_USER_DEPRECATED);

/**
 * @deprecated Use Composer\MetadataMinifier\MetadataMinifier instead
 */
class MetadataMinifier extends \Composer\MetadataMinifier\MetadataMinifier
{
}
