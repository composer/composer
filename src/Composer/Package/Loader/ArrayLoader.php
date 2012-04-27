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

        if (isset($config['bin'])) {
            if (!is_array($config['bin'])) {
                throw new \UnexpectedValueException('Package '.$config['name'].'\'s bin key should be an array, '.gettype($config['bin']).' given.');
            }
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

        // check for a branch alias (dev-master => 1.0.x-dev for example) if this is a named branch
        if ('dev-' === substr($package->getPrettyVersion(), 0, 4) && isset($config['extra']['branch-alias']) && is_array($config['extra']['branch-alias'])) {
            foreach ($config['extra']['branch-alias'] as $sourceBranch => $targetBranch) {
                // ensure it is an alias to a -dev package
                if ('-dev' !== substr($targetBranch, -4)) {
                    continue;
                }
                // normalize without -dev and ensure it's a numeric branch that is parseable
                $validatedTargetBranch = $this->versionParser->normalizeBranch(substr($targetBranch, 0, -4));
                if ('-dev' !== substr($validatedTargetBranch, -4)) {
                    continue;
                }

                // ensure that it is the current branch aliasing itself
                if (strtolower($package->getPrettyVersion()) !== strtolower($sourceBranch)) {
                    continue;
                }

                $package->setAlias($validatedTargetBranch);
                $package->setPrettyAlias(preg_replace('{(\.9{7})+}', '.x', $validatedTargetBranch));
                break;
            }
        }

        foreach (Package\BasePackage::$supportedLinkTypes as $type => $opts) {
            if (isset($config[$type])) {
                $method = 'set'.ucfirst($opts['method']);
                $package->{$method}(
                    $this->loadLinksFromConfig($package, $opts['description'], $config[$type])
                );
            }
        }

        if (isset($config['suggest']) && is_array($config['suggest'])) {
            foreach ($config['suggest'] as $target => $reason) {
                if ('self.version' === trim($reason)) {
                    $config['suggest'][$target] = $package->getPrettyVersion();
                }
            }
            $package->setSuggests($config['suggest']);
        }

        if (isset($config['autoload'])) {
            $package->setAutoload($config['autoload']);
        }

        if (isset($config['include-path'])) {
            $package->setIncludePaths($config['include-path']);
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
