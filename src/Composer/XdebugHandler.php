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

namespace Composer;

trigger_error('The ' . __NAMESPACE__ . '\XdebugHandler class is deprecated, use Composer\XdebugHandler\XdebugHandler instead.', E_USER_DEPRECATED);

/**
 * @deprecated use Composer\XdebugHandler\XdebugHandler instead
 */
class XdebugHandler
{
}
