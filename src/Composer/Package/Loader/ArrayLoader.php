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

namespace Composer\Package\Loader;

use Composer\Package;

/**
 * @author Konstantin Kudryashiv <ever.zet@gmail.com>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class ArrayLoader
{
    protected $supportedLinkTypes = array(
        'require'   => 'requires',
        'conflict'  => 'conflicts',
        'provide'   => 'provides',
        'replace'   => 'replaces',
        'recommend' => 'recommends',
        'suggest'   => 'suggests',
    );

    protected $versionParser;

    public function __construct($parser = null)
    {
        $this->versionParser = $parser;
        if (!$parser) {
            $this->versionParser = new Package\Version\VersionParser;
        }
    }

    public function load($config)
    {
        $version = $this->versionParser->normalize(isset($config['version']) ? $config['version'] : '0.0.0');
        $package = new Package\MemoryPackage(isset($config['name']) ? $config['name'] : '__app__', $version);

        $package->setType(isset($config['type']) ? $config['type'] : 'library');

        if (isset($config['extra'])) {
            $package->setExtra($config['extra']);
        }

        if (isset($config['license'])) {
            $package->setLicense($config['license']);
        }

        if (isset($config['source'])) {
            if (!isset($config['source']['type']) || !isset($config['source']['url'])) {
                throw new \UnexpectedValueException(sprintf(
                    "package source should be specified as {\"type\": ..., \"url\": ...},\n%s given",
                    json_encode($config['source'])
                ));
            }
            $package->setSourceType($config['source']['type']);
            $package->setSourceUrl($config['source']['url']);
            $package->setSourceReference($config['source']['reference']);
        }

        if (isset($config['dist'])) {
            if (!isset($config['dist']['type'])
             || !isset($config['dist']['url'])
             || !isset($config['dist']['shasum'])) {
                throw new \UnexpectedValueException(sprintf(
                    "package dist should be specified as ".
                    "{\"type\": ..., \"url\": ..., \"shasum\": ...},\n%s given",
                    json_encode($config['source'])
                ));
            }
            $package->setDistType($config['dist']['type']);
            $package->setDistUrl($config['dist']['url']);
            $package->setDistReference($config['dist']['reference']);
            $package->setDistSha1Checksum($config['dist']['shasum']);
        }

        foreach ($this->supportedLinkTypes as $type => $description) {
            if (isset($config[$type])) {
                $method = 'set'.ucfirst($description);
                $package->{$method}(
                    $this->loadLinksFromConfig($package->getName(), $description, $config[$type])
                );
            }
        }

        return $package;
    }

    private function loadLinksFromConfig($srcPackageName, $description, array $linksSpecs)
    {
        $links = array();
        foreach ($linksSpecs as $packageName => $constraint) {
            $name = strtolower($packageName);

            $constraint = $this->versionParser->parseConstraints($constraint);
            $links[]    = new Package\Link($srcPackageName, $packageName, $constraint, $description);
        }

        return $links;
    }
}
