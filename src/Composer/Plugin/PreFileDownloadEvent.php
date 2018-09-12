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
use Composer\Util\HttpDownloader;

/**
 * The pre file download event.
 *
 * @author Nils Adermann <naderman@naderman.de>
 */
class PreFileDownloadEvent extends Event
{
    /**
     * @var HttpDownloader
     */
    private $rfs;

    /**
     * @var string
     */
    private $processedUrl;

    /**
     * Constructor.
     *
     * @param string           $name         The event name
     * @param HttpDownloader $rfs
     * @param string           $processedUrl
     */
    public function __construct($name, HttpDownloader $rfs, $processedUrl)
    {
        parent::__construct($name);
        $this->rfs = $rfs;
        $this->processedUrl = $processedUrl;
    }

    /**
     * @return HttpDownloader
     */
    public function getHttpDownloader()
    {
        return $this->rfs;
    }

    /**
     * @param HttpDownloader $rfs
     */
    public function setHttpDownloader(HttpDownloader $rfs)
    {
        $this->rfs = $rfs;
    }

    /**
     * Retrieves the processed URL this remote filesystem will be used for
     *
     * @return string
     */
    public function getProcessedUrl()
    {
        return $this->processedUrl;
    }
}
