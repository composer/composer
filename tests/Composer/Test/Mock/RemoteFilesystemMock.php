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

namespace Composer\Test\Mock;

use Composer\Util\RemoteFilesystem;
use Composer\Downloader\TransportException;

/**
 * Remote filesystem mock
 */
class RemoteFilesystemMock extends RemoteFilesystem
{
    /**
     * @param array $contentMap associative array of locations and content
     */
    public function __construct(array $contentMap)
    {
        $this->contentMap = $contentMap;
    }

    public function getContents($originUrl, $fileUrl, $progress = true, $options = array())
    {
        if (!empty($this->contentMap[$fileUrl])) {
            return $this->contentMap[$fileUrl];
        }

        throw new TransportException('The "'.$fileUrl.'" file could not be downloaded (NOT FOUND)', 404);
    }
}
