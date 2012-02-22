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
use Composer\Package\Version\VersionParser;
use Composer\Repository\RepositoryManager;

/**
 * @author Konstantin Kudryashiv <ever.zet@gmail.com>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class ArrayLoader
{
    protected $versionParser;

    public function __construct(VersionParser $parser = null)
    {
        if (!$parser) {
            $parser = new VersionParser;
        }
        $this->versionParser = $parser;
    }

    public function load($config)
    {
        if (!isset($config['name'])) {
            throw new \UnexpectedValueException('Unknown package has no name defined ('.json_encode($config).').');
        }
        if (!isset($config['version'])) {
            throw new \UnexpectedValueException('Package '.$config['name'].' has no version defined.');
        }

        // handle already normalized versions
        if (isset($config['version_normalized'])) {
            $version = $config['version_normalized'];
        } else {
            $version = $this->versionParser->normalize($config['version']);
        }
        $package = new Package\MemoryPackage($config['name'], $version, $config['version']);
        $package->setType(isset($config['type']) ? strtolower($config['type']) : 'library');

        if (isset($config['target-dir'])) {
            $package->setTargetDir($config['target-dir']);
        }

        if (isset($config['extra']) && is_array($config['extra'])) {
            $package->setExtra($config['extra']);
        }

        if (isset($config['bin']) && is_array($config['bin'])) {
            foreach ($config['bin'] as $key => $bin) {
                $config['bin'][$key]= ltrim($bin, '/');
            }
            $package->setBinaries($config['bin']);
        }

        if (isset($config['scripts']) && is_array($config['scripts'])) {
            foreach ($config['scripts'] as $event => $listeners) {
                $config['scripts'][$event]= (array) $listeners;
            }
            $package->setScripts($config['scripts']);
        }

        if (!empty($config['description']) && is_string($config['description'])) {
            $package->setDescription($config['description']);
        }

        if (!empty($config['homepage']) && is_string($config['homepage'])) {
            $package->setHomepage($config['homepage']);
        }

        if (!empty($config['keywords'])) {
            $package->setKeywords(is_array($config['keywords']) ? $config['keywords'] : array($config['keywords']));
        }

        if (!empty($config['license'])) {
            $package->setLicense(is_array($config['license']) ? $config['license'] : array($config['license']));
        }

        if (!empty($config['time'])) {
            try {
                $date = new \DateTime($config['time']);
                $date->setTimezone(new \DateTimeZone('UTC'));
                $package->setReleaseDate($date);
            } catch (\Exception $e) {
            }
        }

        if (!empty($config['authors']) && is_array($config['authors'])) {
            $package->setAuthors($config['authors']);
        }

        if (isset($config['installation-source'])) {
            $package->setInstallationSource($config['installation-source']);
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
        } elseif ($package->isDev()) {
            throw new \UnexpectedValueException('Dev package '.$package.' must have a source specified');
        }

        if (isset($config['dist'])) {
            if (!isset($config['dist']['type'])
             || !isset($config['dist']['url'])) {
                throw new \UnexpectedValueException(sprintf(
                    "package dist should be specified as ".
                    "{\"type\": ..., \"url\": ..., \"reference\": ..., \"shasum\": ...},\n%s given",
                    json_encode($config['dist'])
                ));
            }
            $package->setDistType($config['dist']['type']);
            $package->setDistUrl($config['dist']['url']);
            $package->setDistReference(isset($config['dist']['reference']) ? $config['dist']['reference'] : null);
            $package->setDistSha1Checksum(isset($config['dist']['shasum']) ? $config['dist']['shasum'] : null);
        }

        foreach (Package\BasePackage::$supportedLinkTypes as $type => $description) {
            if (isset($config[$type])) {
                $method = 'set'.ucfirst($description);
                $package->{$method}(
                    $this->loadLinksFromConfig($package, $description, $config[$type])
                );
            }
        }

        if (isset($config['autoload'])) {
            $package->setAutoload($config['autoload']);
        }

        return $package;
    }

    private function loadLinksFromConfig($package, $description, array $linksSpecs)
    {
        $links = array();
        foreach ($linksSpecs as $packageName => $constraint) {
            if ('self.version' === $constraint) {
                $parsedConstraint = $this->versionParser->parseConstraints($package->getPrettyVersion());
            } else {
                $parsedConstraint = $this->versionParser->parseConstraints($constraint);
            }
            $links[] = new Package\Link($package->getName(), $packageName, $parsedConstraint, $description, $constraint);
        }

        return $links;
    }
}
