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

use Composer\EventDispatcher\Event;
use Composer\Util\RemoteFilesystem;

/**
 * The post file download event.
 *
 * @author Nils Adermann <naderman@naderman.de>
 */
class PostFileDownloadEvent extends Event
{

    /**
     * @var string
     */
    private $fileName;

    /**
     * @var string
     */
    private $checksum;

    /**
     * @var array
     */
    private $url;

    /**
     * Constructor.
     *
     * @param string           $name         The event name
     * @param string           $fileName     The file name
     * @param string           $checksum     The checksum
     * @param array            $url          The url
     */
    public function __construct($name, $fileName, $checksum, $url)
    {
        parent::__construct($name);
        $this->fileName = $fileName;
        $this->checksum = $checksum;
        $this->url = $url;
    }

    /**
     * Retrieves the target file name location.
     *
     * @return string
     */
    public function getFileName()
    {
        return $this->fileName;
    }

    /**
     * Gets the checksum.
     *
     * @return string
     */
    public function getChecksum() {
        return $this->checksum;
    }

    /**
     * Gets the URL.
     *
     * The URL array has keys of base, processed and cacheKey.
     *
     * @return array
     */
    public function getUrl() {
        return $this->url;
    }

}
