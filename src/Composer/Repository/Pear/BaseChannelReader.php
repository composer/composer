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

use Composer\Util\RemoteFilesystem;

/**
 * Base PEAR Channel reader.
 *
 * Provides xml namespaces and red
 *
 * @author Alexey Prilipko <palex@farpost.com>
 */
abstract class BaseChannelReader
{
    /**
     * PEAR REST Interface namespaces
     */
    const channelNS =               'http://pear.php.net/channel-1.0';
    const allCategoriesNS =         'http://pear.php.net/dtd/rest.allcategories';
    const categoryPackagesInfoNS =  'http://pear.php.net/dtd/rest.categorypackageinfo';
    const allPackagesNS =           'http://pear.php.net/dtd/rest.allpackages';
    const allReleasesNS =           'http://pear.php.net/dtd/rest.allreleases';
    const packageInfoNS =           'http://pear.php.net/dtd/rest.package';

    /** @var RemoteFilesystem */
    private $rfs;

    protected function __construct($rfs)
    {
        $this->rfs = $rfs;
    }

    /**
     * Read content from remote filesystem.
     *
     * @param $origin string server
     * @param $path   string relative path to content
     * @return \SimpleXMLElement
     */
    protected function requestContent($origin, $path)
    {
        $url = rtrim($origin, '/') . '/' . ltrim($path, '/');
        $content = $this->rfs->getContents($origin, $url, false);
        if (!$content) {
            throw new \UnexpectedValueException('The PEAR channel at '.$url.' did not respond.');
        }

        return $content;
    }

    /**
     * Read xml content from remote filesystem
     *
     * @param $origin string server
     * @param $path   string relative path to content
     * @return \SimpleXMLElement
     */
    protected function requestXml($origin, $path)
    {
        // http://components.ez.no/p/packages.xml is malformed. to read it we must ignore parsing errors.
        $xml = simplexml_load_string($this->requestContent($origin, $path), "SimpleXMLElement", LIBXML_NOERROR);

        if (false == $xml) {
            $url = rtrim($origin, '/') . '/' . ltrim($path, '/');
            throw new \UnexpectedValueException('The PEAR channel at '.$origin.' is broken.');
        }

        return $xml;
    }
}
