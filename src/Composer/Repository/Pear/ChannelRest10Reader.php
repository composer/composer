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

use Composer\Downloader\TransportException;

/**
 * Read PEAR packages using REST 1.0 interface
 *
 * At version 1.0 package descriptions read from:
 *  {baseUrl}/p/packages.xml
 *  {baseUrl}/p/{package}/info.xml
 *  {baseUrl}/p/{package}/allreleases.xml
 *  {baseUrl}/p/{package}/deps.{version}.txt
 *
 * @author Alexey Prilipko <palex@farpost.com>
 */
class ChannelRest10Reader extends BaseChannelReader
{
    private $dependencyReader;

    public function __construct($rfs)
    {
        parent::__construct($rfs);

        $this->dependencyReader = new PackageDependencyParser();
    }

    /**
     * Reads package descriptions using PEAR Rest 1.0 interface
     *
     * @param string $baseUrl base Url interface
     *
     * @return PackageInfo[]
     */
    public function read($baseUrl)
    {
        return $this->readPackages($baseUrl);
    }

    /**
     * Read list of packages from
     *  {baseUrl}/p/packages.xml
     *
     * @param string $baseUrl
     * @return PackageInfo[]
     */
    private function readPackages($baseUrl)
    {
        $result = array();

        $xmlPath = '/p/packages.xml';
        $xml = $this->requestXml($baseUrl, $xmlPath);
        $xml->registerXPathNamespace('ns', self::ALL_PACKAGES_NS);
        foreach ($xml->xpath('ns:p') as $node) {
            $packageName = (string) $node;
            $packageInfo = $this->readPackage($baseUrl, $packageName);
            $result[] = $packageInfo;
        }

        return $result;
    }

    /**
     * Read package info from
     *  {baseUrl}/p/{package}/info.xml
     *
     * @param string $baseUrl
     * @param string $packageName
     * @return PackageInfo
     */
    private function readPackage($baseUrl, $packageName)
    {
        $xmlPath = '/p/' . strtolower($packageName) . '/info.xml';
        $xml = $this->requestXml($baseUrl, $xmlPath);
        $xml->registerXPathNamespace('ns', self::PACKAGE_INFO_NS);

        $channelName = (string) $xml->c;
        $packageName = (string) $xml->n;
        $license = (string) $xml->l;
        $shortDescription = (string) $xml->s;
        $description = (string) $xml->d;

        return new PackageInfo(
            $channelName,
            $packageName,
            $license,
            $shortDescription,
            $description,
            $this->readPackageReleases($baseUrl, $packageName)
        );
    }

    /**
     * Read package releases from
     *  {baseUrl}/p/{package}/allreleases.xml
     *
     * @param string $baseUrl
     * @param string $packageName
     * @throws \Composer\Downloader\TransportException|\Exception
     * @return ReleaseInfo[]                                      hash array with keys as version numbers
     */
    private function readPackageReleases($baseUrl, $packageName)
    {
        $result = array();

        try {
            $xmlPath = '/r/' . strtolower($packageName) . '/allreleases.xml';
            $xml = $this->requestXml($baseUrl, $xmlPath);
            $xml->registerXPathNamespace('ns', self::ALL_RELEASES_NS);
            foreach ($xml->xpath('ns:r') as $node) {
                $releaseVersion = (string) $node->v;
                $releaseStability = (string) $node->s;

                try {
                    $result[$releaseVersion] = new ReleaseInfo(
                        $releaseStability,
                        $this->readPackageReleaseDependencies($baseUrl, $packageName, $releaseVersion)
                    );
                } catch (TransportException $exception) {
                    if ($exception->getCode() != 404) {
                        throw $exception;
                    }
                }
            }
        } catch (TransportException $exception) {
            if ($exception->getCode() != 404) {
                throw $exception;
            }
        }

        return $result;
    }

    /**
     * Read package dependencies from
     *  {baseUrl}/p/{package}/deps.{version}.txt
     *
     * @param string $baseUrl
     * @param string $packageName
     * @param string $version
     * @return DependencyInfo[]
     */
    private function readPackageReleaseDependencies($baseUrl, $packageName, $version)
    {
        $dependencyReader = new PackageDependencyParser();

        $depthPath = '/r/' . strtolower($packageName) . '/deps.' . $version . '.txt';
        $content = $this->requestContent($baseUrl, $depthPath);
        $dependencyArray = unserialize($content);

        return $dependencyReader->buildDependencyInfo($dependencyArray);
    }
}
