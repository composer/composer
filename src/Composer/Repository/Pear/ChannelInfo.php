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

namespace Composer\Repository\Pear;

/**
 * PEAR channel info
 *
 * @author Alexey Prilipko <palex@farpost.com>
 */
class ChannelInfo
{
    private $name;
    private $alias;
    private $packages;

    /**
     * @param string        $name
     * @param string        $alias
     * @param PackageInfo[] $packages
     */
    public function __construct($name, $alias, array $packages)
    {
        $this->name = $name;
        $this->alias = $alias;
        $this->packages = $packages;
    }

    /**
     * Name of the channel
     *
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * Alias of the channel
     *
     * @return string
     */
    public function getAlias()
    {
        return $this->alias;
    }

    /**
     * List of channel packages
     *
     * @return PackageInfo[]
     */
    public function getPackages()
    {
        return $this->packages;
    }
}
