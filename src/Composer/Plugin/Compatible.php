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

namespace Composer\Plugin;

/**
 * Implement this interface on your plugin class to specify minimum composer version required to work with.
 *
 * @author Sullivan Senechal <soullivaneuh@gmail.com>
 */
interface Compatible
{
    /**
     * @return string
     */
    public function getMinimumComposerVersion();
}
