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

namespace Composer\Repository;

use Composer\Package\MemoryPackage;
use Composer\Package\BasePackage;
use Composer\Package\Link;
use Composer\Package\LinkConstraint\VersionConstraint;

/**
 * @author Benjamin Eberlei <kontakt@beberlei.de>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class PearRepository extends ArrayRepository
{
    private $name;
    private $url;

    public function __construct($url, $name = '')
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new \UnexpectedValueException('Invalid url given for PEAR repository "'.$name.'": '.$url);
        }

        $this->url = $url;
    }

    protected function initialize()
    {
        parent::initialize();

        set_error_handler(function($severity, $message, $file, $line) {
            throw new \ErrorException($message, $severity, $severity, $file, $line);
        });
        $this->fetchFromServer();
        restore_error_handler();
    }

    protected function fetchFromServer()
    {
        $categoryXML = $this->requestXml($this->url . "/rest/c/categories.xml");
        $categories = $categoryXML->getElementsByTagName("c");

        foreach ($categories as $category) {
            $categoryLink = $category->getAttribute("xlink:href");
            $categoryLink = str_replace("info.xml", "packages.xml", $categoryLink);
            $packagesXML = $this->requestXml($this->url . $categoryLink);

            $packages = $packagesXML->getElementsByTagName('p');
            foreach ($packages as $package) {
                $packageName = $package->nodeValue;

                $packageLink = $package->getAttribute('xlink:href');
                $releaseLink = $this->url . str_replace("/rest/p/", "/rest/r/", $packageLink);
                $allReleasesLink = $releaseLink . "/allreleases2.xml";
                $releasesXML = $this->requestXml($allReleasesLink);

                $releases = $releasesXML->getElementsByTagName('r');

                foreach ($releases as $release) {
                    /* @var $release DOMElement */
                    $pearVersion = $release->getElementsByTagName('v')->item(0)->nodeValue;

                    $version = BasePackage::parseVersion($pearVersion);

                    $package = new MemoryPackage($packageName, $version['version'], $version['type']);
                    $package->setDistType('pear');
                    $package->setDistUrl($this->url.'/get/'.$packageName.'-'.$pearVersion.".tgz");

                    $depsLink = $releaseLink . "/deps.".$pearVersion.".txt";
                    $deps = file_get_contents($depsLink);
                    if (preg_match('((O:([0-9])+:"([^"]+)"))', $deps, $matches)) {
                        if (strlen($matches[3]) == $matches[2]) {
                            throw new \InvalidArgumentException("Invalid dependency data, it contains serialized objects.");
                        }
                    }
                    $deps = unserialize($deps);
                    if (isset($deps['required']['package'])) {
                        $requires = array();
                        foreach ($deps['required']['package'] as $dependency) {
                            if (isset($dependency['min'])) {
                                $constraint = new VersionConstraint('>=', $dependency['min']);
                            } else {
                                $constraint = new VersionConstraint('>=', '0.0.0');
                            }
                            $requires[] = new Link($packageName, $dependency['name'], $constraint, 'requires');
                        }
                        $package->setRequires($requires);
                    }

                    $this->addPackage($package);
                }
            }
        }
    }

    /**
     * @param  string $url
     * @return DOMDocument
     */
    private function requestXml($url)
    {
        $content = file_get_contents($url);
        if (!$content) {
            throw new \UnexpectedValueException('The PEAR channel at '.$url.' did not respond.');
        }
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->loadXML($content);

        return $dom;
    }
}
