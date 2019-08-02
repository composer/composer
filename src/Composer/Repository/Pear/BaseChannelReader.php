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

use Composer\Util\HttpDownloader;

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
    const CHANNEL_NS = 'http://pear.php.net/channel-1.0';
    const ALL_CATEGORIES_NS = 'http://pear.php.net/dtd/rest.allcategories';
    const CATEGORY_PACKAGES_INFO_NS = 'http://pear.php.net/dtd/rest.categorypackageinfo';
    const ALL_PACKAGES_NS = 'http://pear.php.net/dtd/rest.allpackages';
    const ALL_RELEASES_NS = 'http://pear.php.net/dtd/rest.allreleases';
    const PACKAGE_INFO_NS = 'http://pear.php.net/dtd/rest.package';

    /** @var HttpDownloader */
    private $httpDownloader;

    protected function __construct(HttpDownloader $httpDownloader)
    {
        $this->httpDownloader = $httpDownloader;
    }

    /**
     * Read content from remote filesystem.
     *
     * @param string $origin server
     * @param string $path   relative path to content
     * @throws \UnexpectedValueException
     * @return string
     */
    protected function requestContent($origin, $path)
    {
        $url = rtrim($origin, '/') . '/' . ltrim($path, '/');
        try {
            $content = $this->httpDownloader->get($url)->getBody();
        } catch (\Exception $e) {
            throw new \UnexpectedValueException('The PEAR channel at ' . $url . ' did not respond.', 0, $e);
        }
        if (!$content) {
            throw new \UnexpectedValueException('The PEAR channel at ' . $url . ' did not respond.');
        }

        return str_replace('http://pear.php.net/rest/', 'https://pear.php.net/rest/', $content);
    }

    /**
     * Read xml content from remote filesystem
     *
     * @param string $origin server
     * @param string $path   relative path to content
     * @throws \UnexpectedValueException
     * @return \SimpleXMLElement
     */
    protected function requestXml($origin, $path)
    {
        // http://components.ez.no/p/packages.xml is malformed. to read it we must ignore parsing errors.
        $xml = simplexml_load_string($this->requestContent($origin, $path), "SimpleXMLElement", LIBXML_NOERROR);

        if (false === $xml) {
            throw new \UnexpectedValueException(sprintf('The PEAR channel at ' . $origin . ' is broken. (Invalid XML at file `%s`)', $path));
        }

        return $xml;
    }
}
