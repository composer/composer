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
use Composer\Package\CompletePackage;
use Composer\Package\CompleteAliasPackage;

/**
 * @author Konstantin Kudryashiv <ever.zet@gmail.com>
 */
class JsonLoader
{
    private $loader;

    public function __construct(LoaderInterface $loader)
    {
        $this->loader = $loader;
    }

    /**
     * @param  string|JsonFile                      $json A filename, json string or JsonFile instance to load the package from
     * @return CompletePackage|CompleteAliasPackage
     */
    public function load($json)
    {
        if ($json instanceof JsonFile) {
            $config = $json->read();
        } elseif (file_exists($json)) {
            $config = JsonFile::parseJson(file_get_contents($json), $json);
        } elseif (is_string($json)) {
            $config = JsonFile::parseJson($json);
        } else {
            throw new \InvalidArgumentException(sprintf(
                "JsonLoader: Unknown \$json parameter %s. Please report at https://github.com/composer/composer/issues/new.",
                gettype($json)
            ));
        }

        return $this->loader->load($config);
    }
}
