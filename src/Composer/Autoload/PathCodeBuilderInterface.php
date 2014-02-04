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

namespace Composer\Autoload;

/**
 * @author Andreas hennings <andreas@dqxtech.net>
 */
interface PathCodeBuilderInterface
{
    /**
     * @param string $path
     * @param BuildDataInterface $buildData
     *
     * @return string
     */
    public function getPathCode($path, BuildDataInterface $buildData);
}
