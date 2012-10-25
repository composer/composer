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
 * PEAR Package info
 *
 * @author Alexey Prilipko <palex@farpost.com>
 */
class PackageInfo
{
    private $channelName;
    private $packageName;
    private $license;
    private $shortDescription;
    private $description;
    private $releases;

    /**
     * @param string        $channelName
     * @param string        $packageName
     * @param string        $license
     * @param string        $shortDescription
     * @param string        $description
     * @param ReleaseInfo[] $releases         associative array maps release version to release info
     */
    public function __construct($channelName, $packageName, $license, $shortDescription, $description, $releases)
    {
        $this->channelName = $channelName;
        $this->packageName = $packageName;
        $this->license = $license;
        $this->shortDescription = $shortDescription;
        $this->description = $description;
        $this->releases = $releases;
    }

    /**
     * @return string the package channel name
     */
    public function getChannelName()
    {
        return $this->channelName;
    }

    /**
     * @return string the package name
     */
    public function getPackageName()
    {
        return $this->packageName;
    }

    /**
     * @return string the package description
     */
    public function getDescription()
    {
        return $this->description;
    }

    /**
     * @return string the package short description
     */
    public function getShortDescription()
    {
        return $this->shortDescription;
    }

    /**
     * @return string the package licence
     */
    public function getLicense()
    {
        return $this->license;
    }

    /**
     * @return ReleaseInfo[]
     */
    public function getReleases()
    {
        return $this->releases;
    }
}
