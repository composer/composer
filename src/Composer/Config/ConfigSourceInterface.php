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

namespace Composer\Config;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
interface ConfigSourceInterface
{
    public function addRepository($name, $config);

    public function removeRepository($name);

    public function addConfigSetting($name, $value);

    public function removeConfigSetting($name);
}
