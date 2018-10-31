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

use Composer\Util\HttpDownloader;
use Composer\Util\Http\Response;
use Composer\Downloader\TransportException;

class HttpDownloaderMock extends HttpDownloader
{
    protected $contentMap;

    /**
     * @param array $contentMap associative array of locations and content
     */
    public function __construct(array $contentMap)
    {
        $this->contentMap = $contentMap;
    }

    public function get($fileUrl, $options = array())
    {
        if (!empty($this->contentMap[$fileUrl])) {
            return new Response(array('url' => $fileUrl), 200, array(), $this->contentMap[$fileUrl]);
        }

        throw new TransportException('The "'.$fileUrl.'" file could not be downloaded (NOT FOUND)', 404);
    }
}
