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

namespace Composer\Package\Loader;

use Composer\Json\JsonFile;

/**
 * @author Konstantin Kudryashiv <ever.zet@gmail.com>
 */
class JsonLoader extends ArrayLoader
{
    public function load($json)
    {
        if ($json instanceof JsonFile) {
            $config = $json->read();
        } elseif (file_exists($json)) {
            $config = JsonFile::parseJson(file_get_contents($json));
        } elseif (is_string($json)) {
            $config = JsonFile::parseJson($json);
        }

        return parent::load($config);
    }
}
