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

namespace Composer\Storage;

/**
 * Package Distribution
 *
 * @author Kirill chEbba Chebunin <iam@chebba.org>
 */
class PackageDistribution
{
    private $type;
    private $url;
    private $sha1Checksum;

    /**
     * Constructor for distribution
     *
     * @param string $type         Distribution type, ex. zip, pear
     * @param string $url          Distribution url
     * @param string $sha1Checksum Distribution sha1 checksum
     */
    public function __construct($type, $url, $sha1Checksum)
    {
        $this->type = $type;
        $this->url = $url;
        $this->sha1Checksum = $sha1Checksum;
    }

    /**
     * Get type
     *
     * @return string
     */
    public function getType()
    {
        return $this->type;
    }

    /**
     * Get url
     *
     * @return string
     */
    public function getUrl()
    {
        return $this->url;
    }

    /**
     * Get sha1 checksum
     *
     * @return string
     */
    public function getSha1Checksum()
    {
        return $this->sha1Checksum;
    }
}
