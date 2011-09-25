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

    public function load($config)
    {
        $this->validateConfig($config);

        $versionParser = new Package\Version\VersionParser();
        $version = $versionParser->parse($config['version']);
        $package = new Package\MemoryPackage($config['name'], $version['version'], $version['type']);

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
            $package->setDistSha1Checksum($config['dist']['shasum']);
        }

        foreach ($this->supportedLinkTypes as $type => $description) {
            if (isset($config[$type])) {
                $method = 'set'.ucfirst($description);
                $package->{$method}(
                    $this->loadLinksFromConfig($package->getName(), $description, $config['require'])
                );
            }
        }

        return $package;
    }

    private function loadLinksFromConfig($srcPackageName, $description, array $linksSpecs)
    {
        $links = array();
        foreach ($linksSpecs as $packageName => $version) {
            $name = strtolower($packageName);

            preg_match('#^([>=<~]*)([\d.]+.*)$#', $version, $match);
            if (!$match[1]) {
                $match[1] = '=';
            }

            $constraint = new Package\LinkConstraint\VersionConstraint($match[1], $match[2]);
            $links[]    = new Package\Link($srcPackageName, $packageName, $constraint, $description);
        }

        return $links;
    }

    private function validateConfig(array $config)
    {
        if (!isset($config['name'])) {
            throw new \UnexpectedValueException('name is required for package');
        }
        if (!isset($config['version'])) {
            throw new \UnexpectedValueException('version is required for package');
        }
        if (!isset($config['type'])) {
            throw new \UnexpectedValueException('type is required for package');
        }
    }
}
