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

use Composer\Package\Loader\ArrayLoader;

/**
 * @author Benjamin Eberlei <kontakt@beberlei.de>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class PearRepository extends ArrayRepository
{
    protected $url;

    public function __construct(array $config)
    {
        if (!preg_match('{^https?://}', $config['url'])) {
            $config['url'] = 'http://'.$config['url'];
        }
        if (!filter_var($config['url'], FILTER_VALIDATE_URL)) {
            throw new \UnexpectedValueException('Invalid url given for PEAR repository: '.$config['url']);
        }

        $this->url = rtrim($config['url'], '/');
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
            $link = $category->getAttribute("xlink:href");
            if (0 !== strpos($link, '/')) {
                $link = '/' . $link;
            }
            try {
                $packagesLink = str_replace("info.xml", "packagesinfo.xml", $link);
                $this->fetchPear2Packages($this->url . $packagesLink);
            } catch (\ErrorException $e) {
                if (false === strpos($e->getMessage(), '404')) {
                    throw $e;
                }
                $categoryLink = str_replace("info.xml", "packages.xml", $link);
                $this->fetchPearPackages($this->url . $categoryLink);
            }

        }
    }

    /**
     * @param   string $categoryLink
     * @throws  ErrorException
     * @throws  InvalidArgumentException
     */
    private function fetchPearPackages($categoryLink)
    {
        $packagesXML = $this->requestXml($categoryLink);
        $packages = $packagesXML->getElementsByTagName('p');
        $loader = new ArrayLoader();
        foreach ($packages as $package) {
            $packageName = $package->nodeValue;

            $packageLink = $package->getAttribute('xlink:href');
            $releaseLink = $this->url . str_replace("/rest/p/", "/rest/r/", $packageLink);
            $allReleasesLink = $releaseLink . "/allreleases2.xml";

            try {
                $releasesXML = $this->requestXml($allReleasesLink);
            } catch (\ErrorException $e) {
                if (strpos($e->getMessage(), '404')) {
                    continue;
                }
                throw $e;
            }

            $releases = $releasesXML->getElementsByTagName('r');

            foreach ($releases as $release) {
                /* @var $release \DOMElement */
                $pearVersion = $release->getElementsByTagName('v')->item(0)->nodeValue;

                $packageData = array(
                    'name' => $packageName,
                    'type' => 'library',
                    'dist' => array('type' => 'pear', 'url' => $this->url.'/get/'.$packageName.'-'.$pearVersion.".tgz"),
                    'version' => $pearVersion,
                );

                try {
                    $deps = file_get_contents($releaseLink . "/deps.".$pearVersion.".txt");
                } catch (\ErrorException $e) {
                    if (strpos($e->getMessage(), '404')) {
                        continue;
                    }
                    throw $e;
                }

                $packageData += $this->parseDependencies($deps);

                try {
                    $this->addPackage($loader->load($packageData));
                } catch (\UnexpectedValueException $e) {
                    continue;
                }
            }
        }
    }

    /**
     * @param   array $data
     * @return  string
     */
    private function parseVersion(array $data)
    {
        if (!isset($data['min']) && !isset($data['max'])) {
            return '*';
        }
        $versions = array();
        if (isset($data['min'])) {
            $versions[] = '>=' . $data['min'];
        }
        if (isset($data['max'])) {
            $versions[] = '<=' . $data['max'];
        }
        return implode(',', $versions);
    }

    /**
     * @todo    Improve dependencies resolution of pear packages.
     * @param   array $options
     * @return  array
     */
    private function parseDependenciesOptions(array $depsOptions)
    {
        $data = array();
        foreach ($depsOptions as $name => $options) {
            // make sure single deps are wrapped in an array
            if (isset($options['name'])) {
                $options = array($options);
            }
            if ('php' == $name) {
                $data[$name] = $this->parseVersion($options);
            } elseif ('package' == $name) {
                foreach ($options as $key => $value) {
                    if (is_array($value)) {
                        $dataKey = $value['name'];
                        $data[$dataKey] = $this->parseVersion($value);
                    }
                }
            } elseif ('extension' == $name) {
                foreach ($options as $key => $value) {
                    $dataKey = 'ext-' . $value['name'];
                    $data[$dataKey] = $this->parseVersion($value);
                }
            }
        }
        return $data;
    }

    /**
     * @param   string $deps
     * @return  array
     * @throws  InvalidArgumentException
     */
    private function parseDependencies($deps)
    {
        if (preg_match('((O:([0-9])+:"([^"]+)"))', $deps, $matches)) {
            if (strlen($matches[3]) == $matches[2]) {
                throw new \InvalidArgumentException("Invalid dependency data, it contains serialized objects.");
            }
        }
        $deps = (array) @unserialize($deps);
        unset($deps['required']['pearinstaller']);

        $depsData = array();
        if (!empty($deps['required'])) {
            $depsData['require'] = $this->parseDependenciesOptions($deps['required']);
        }

        if (!empty($deps['optional'])) {
            $depsData['suggest'] = $this->parseDependenciesOptions($deps['optional']);
        }

        return $depsData;
    }

    /**
     * @param   string $packagesLink
     * @return  void
     * @throws  InvalidArgumentException
     */
    private function fetchPear2Packages($packagesLink)
    {
        $loader = new ArrayLoader();
        $packagesXml = $this->requestXml($packagesLink);
        $informations = $packagesXml->getElementsByTagName('pi');
        foreach ($informations as $information) {
            $package = $information->getElementsByTagName('p')->item(0);

            $packageName = $package->getElementsByTagName('n')->item(0)->nodeValue;
            $packageData = array(
                'name' => $packageName,
                'type' => 'library'
            );
            $packageKeys = array('l' => 'license', 'd' => 'description');
            foreach ($packageKeys as $pear => $composer) {
                if ($package->getElementsByTagName($pear)->length > 0
                        && ($pear = $package->getElementsByTagName($pear)->item(0)->nodeValue)) {
                    $packageData[$composer] = $pear;
                }
            }

            $depsData = array();
            foreach ($information->getElementsByTagName('deps') as $depElement) {
                $depsVersion = $depElement->getElementsByTagName('v')->item(0)->nodeValue;
                $depsData[$depsVersion] = $this->parseDependencies(
                    $depElement->getElementsByTagName('d')->item(0)->nodeValue
                );
            }

            $releases = $information->getElementsByTagName('a')->item(0);
            if (!$releases) {
                continue;
            }

            $releases = $releases->getElementsByTagName('r');
            $packageUrl = $this->url . '/get/' . $packageName;
            foreach ($releases as $release) {
                $version = $release->getElementsByTagName('v')->item(0)->nodeValue;
                $releaseData = array(
                    'dist' => array(
                        'type' => 'pear',
                        'url' => $packageUrl . '-' . $version . '.tgz'
                    ),
                    'version' => $version
                );
                if (isset($depsData[$version])) {
                    $releaseData += $depsData[$version];
                }

                try {
                    $this->addPackage(
                        $loader->load($packageData + $releaseData)
                    );
                } catch (\UnexpectedValueException $e) {
                    continue;
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