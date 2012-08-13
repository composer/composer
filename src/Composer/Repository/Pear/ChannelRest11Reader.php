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
 * Read PEAR packages using REST 1.1 interface
 *
 * At version 1.1 package descriptions read from:
 *  {baseUrl}/c/categories.xml
 *  {baseUrl}/c/{category}/packagesinfo.xml
 *
 * @author Alexey Prilipko <palex@farpost.com>
 */
class ChannelRest11Reader extends BaseChannelReader
{
    private $dependencyReader;

    public function __construct($rfs)
    {
        parent::__construct($rfs);

        $this->dependencyReader = new PackageDependencyParser();
    }

    /**
     * Reads package descriptions using PEAR Rest 1.1 interface
     *
     * @param $baseUrl  string base Url interface
     *
     * @return PackageInfo[]
     */
    public function read($baseUrl)
    {
        return $this->readChannelPackages($baseUrl);
    }

    /**
     * Read list of channel categories from
     *  {baseUrl}/c/categories.xml
     *
     * @param $baseUrl string
     * @return PackageInfo[]
     */
    private function readChannelPackages($baseUrl)
    {
        $result = array();

        $xml = $this->requestXml($baseUrl, "/c/categories.xml");
        $xml->registerXPathNamespace('ns', self::ALL_CATEGORIES_NS);
        foreach ($xml->xpath('ns:c') as $node) {
            $categoryName = (string) $node;
            $categoryPackages = $this->readCategoryPackages($baseUrl, $categoryName);
            $result = array_merge($result, $categoryPackages);
        }

        return $result;
    }

    /**
     * Read packages from
     *  {baseUrl}/c/{category}/packagesinfo.xml
     *
     * @param $baseUrl      string
     * @param $categoryName string
     * @return PackageInfo[]
     */
    private function readCategoryPackages($baseUrl, $categoryName)
    {
        $result = array();

        $categoryPath = '/c/'.urlencode($categoryName).'/packagesinfo.xml';
        $xml = $this->requestXml($baseUrl, $categoryPath);
        $xml->registerXPathNamespace('ns', self::CATEGORY_PACKAGES_INFO_NS);
        foreach ($xml->xpath('ns:pi') as $node) {
            $packageInfo = $this->parsePackage($node);
            $result[] = $packageInfo;
        }

        return $result;
    }

    /**
     * Parses package node.
     *
     * @param $packageInfo  \SimpleXMLElement   xml element describing package
     * @return PackageInfo
     */
    private function parsePackage($packageInfo)
    {
        $packageInfo->registerXPathNamespace('ns', self::CATEGORY_PACKAGES_INFO_NS);
        $channelName = (string) $packageInfo->p->c;
        $packageName = (string) $packageInfo->p->n;
        $license = (string) $packageInfo->p->l;
        $shortDescription = (string) $packageInfo->p->s;
        $description = (string) $packageInfo->p->d;

        $dependencies = array();
        foreach ($packageInfo->xpath('ns:deps') as $node) {
            $dependencyVersion = (string) $node->v;
            $dependencyArray = unserialize((string) $node->d);

            $dependencyInfo = $this->dependencyReader->buildDependencyInfo($dependencyArray);

            $dependencies[$dependencyVersion] = $dependencyInfo;
        }

        $releases = array();
        $releasesInfo = $packageInfo->xpath('ns:a/ns:r');
        if ($releasesInfo) {
            foreach ($releasesInfo as $node) {
                $releaseVersion = (string) $node->v;
                $releaseStability = (string) $node->s;
                $releases[$releaseVersion] = new ReleaseInfo(
                    $releaseStability,
                    isset($dependencies[$releaseVersion]) ? $dependencies[$releaseVersion] : new DependencyInfo(array(), array())
                );
            }
        }

        return new PackageInfo(
            $channelName,
            $packageName,
            $license,
            $shortDescription,
            $description,
            $releases
        );
    }
}
