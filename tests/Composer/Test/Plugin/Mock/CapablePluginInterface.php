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

namespace Composer\Test\Plugin\Mock;

use Composer\Plugin\Capable;
use Composer\Plugin\PluginInterface;

interface CapablePluginInterface extends PluginInterface, Capable
{
}
