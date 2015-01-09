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

namespace Composer\Util\Transfer;

/**
 *
 * @author Alexander Goryachev <mail@a-goryachev.ru>
 */
interface DriverInterface {
    public function get($originUrl, $fileUrl, $additionalOptions = array(), $fileName = null, $progress = true);
    public function getOptions();
    public function getLastHeaders();
}
