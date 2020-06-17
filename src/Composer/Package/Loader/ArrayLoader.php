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
use Composer\Package\AliasPackage;
use Composer\Package\Link;
use Composer\Package\RootAliasPackage;
use Composer\Package\RootPackageInterface;
use Composer\Package\Version\VersionParser;
use Composer\Semver\VersionParser as SemverVersionParser;

/**
 * @author Konstantin Kudryashiv <ever.zet@gmail.com>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class ArrayLoader implements LoaderInterface
{
    protected $versionParser;
    protected $loadOptions;

    public function __construct(SemverVersionParser $parser = null, $loadOptions = false)
    {
        if (!$parser) {
            $parser = new VersionParser;
        }
        $this->versionParser = $parser;
        $this->loadOptions = $loadOptions;
    }

    public function load(array $config, $class = 'Composer\Package\CompletePackage')
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
        $package = new $class($config['name'], $version, $config['version']);
        $package->setType(isset($config['type']) ? strtolower($config['type']) : 'library');

        if (isset($config['target-dir'])) {
            $package->setTargetDir($config['target-dir']);
        }

        if (isset($config['extra']) && is_array($config['extra'])) {
            $package->setExtra($config['extra']);
        }

        if (isset($config['bin'])) {
            foreach ((array) $config['bin'] as $key => $bin) {
                $config['bin'][$key] = ltrim($bin, '/');
            }
            $package->setBinaries((array) $config['bin']);
        }

        if (isset($config['installation-source'])) {
            $package->setInstallationSource($config['installation-source']);
        }

        if (isset($config['source'])) {
            if (!isset($config['source']['type']) || !isset($config['source']['url']) || !isset($config['source']['reference'])) {
                throw new \UnexpectedValueException(sprintf(
                    "Package %s's source key should be specified as {\"type\": ..., \"url\": ..., \"reference\": ...},\n%s given.",
                    $config['name'],
                    json_encode($config['source'])
                ));
            }
            $package->setSourceType($config['source']['type']);
            $package->setSourceUrl($config['source']['url']);
            $package->setSourceReference(isset($config['source']['reference']) ? $config['source']['reference'] : null);
            if (isset($config['source']['mirrors'])) {
                $package->setSourceMirrors($config['source']['mirrors']);
            }
        }

        if (isset($config['dist'])) {
            if (!isset($config['dist']['type'])
             || !isset($config['dist']['url'])) {
                throw new \UnexpectedValueException(sprintf(
                    "Package %s's dist key should be specified as ".
                    "{\"type\": ..., \"url\": ..., \"reference\": ..., \"shasum\": ...},\n%s given.",
                    $config['name'],
                    json_encode($config['dist'])
                ));
            }
            $package->setDistType($config['dist']['type']);
            $package->setDistUrl($config['dist']['url']);
            $package->setDistReference(isset($config['dist']['reference']) ? $config['dist']['reference'] : null);
            $package->setDistSha1Checksum(isset($config['dist']['shasum']) ? $config['dist']['shasum'] : null);
            if (isset($config['dist']['mirrors'])) {
                $package->setDistMirrors($config['dist']['mirrors']);
            }
        }

        foreach (Package\BasePackage::$supportedLinkTypes as $type => $opts) {
            if (isset($config[$type])) {
                $method = 'set'.ucfirst($opts['method']);
                $package->{$method}(
                    $this->parseLinks(
                        $package->getName(),
                        $package->getPrettyVersion(),
                        $opts['description'],
                        $config[$type]
                    )
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

        if (isset($config['autoload-dev'])) {
            $package->setDevAutoload($config['autoload-dev']);
        }

        if (isset($config['include-path'])) {
            $package->setIncludePaths($config['include-path']);
        }

        if (!empty($config['time'])) {
            $time = preg_match('/^\d++$/D', $config['time']) ? '@'.$config['time'] : $config['time'];

            try {
                $date = new \DateTime($time, new \DateTimeZone('UTC'));
                $package->setReleaseDate($date);
            } catch (\Exception $e) {
            }
        }

        if (!empty($config['notification-url'])) {
            $package->setNotificationUrl($config['notification-url']);
        }

        if (!empty($config['archive']['exclude'])) {
            $package->setArchiveExcludes($config['archive']['exclude']);
        }

        if ($package instanceof Package\CompletePackageInterface) {
            if (isset($config['scripts']) && is_array($config['scripts'])) {
                foreach ($config['scripts'] as $event => $listeners) {
                    $config['scripts'][$event] = (array) $listeners;
                }
                if (isset($config['scripts']['composer'])) {
                    trigger_error('The `composer` script name is reserved for internal use, please avoid defining it', E_USER_DEPRECATED);
                }
                $package->setScripts($config['scripts']);
            }

            if (!empty($config['description']) && is_string($config['description'])) {
                $package->setDescription($config['description']);
            }

            if (!empty($config['homepage']) && is_string($config['homepage'])) {
                $package->setHomepage($config['homepage']);
            }

            if (!empty($config['keywords']) && is_array($config['keywords'])) {
                $package->setKeywords($config['keywords']);
            }

            if (!empty($config['license'])) {
                $package->setLicense(is_array($config['license']) ? $config['license'] : array($config['license']));
            }

            if (!empty($config['authors']) && is_array($config['authors'])) {
                $package->setAuthors($config['authors']);
            }

            if (isset($config['support'])) {
                $package->setSupport($config['support']);
            }

            if (!empty($config['funding']) && is_array($config['funding'])) {
                $package->setFunding($config['funding']);
            }

            if (isset($config['abandoned'])) {
                $package->setAbandoned($config['abandoned']);
            }
        }

        if ($aliasNormalized = $this->getBranchAlias($config)) {
            if ($package instanceof RootPackageInterface) {
                $package = new RootAliasPackage($package, $aliasNormalized, preg_replace('{(\.9{7})+}', '.x', $aliasNormalized));
            } else {
                $package = new AliasPackage($package, $aliasNormalized, preg_replace('{(\.9{7})+}', '.x', $aliasNormalized));
            }
        }

        if ($this->loadOptions && isset($config['transport-options'])) {
            $package->setTransportOptions($config['transport-options']);
        }

        return $package;
    }

    /**
     * @param  string $source        source package name
     * @param  string $sourceVersion source package version (pretty version ideally)
     * @param  string $description   link description (e.g. requires, replaces, ..)
     * @param  array  $links         array of package name => constraint mappings
     * @return Link[]
     */
    public function parseLinks($source, $sourceVersion, $description, $links)
    {
        $res = array();
        foreach ($links as $target => $constraint) {
            if (!is_string($constraint)) {
                throw new \UnexpectedValueException('Link constraint in '.$source.' '.$description.' > '.$target.' should be a string, got '.gettype($constraint) . ' (' . var_export($constraint, true) . ')');
            }
            if ('self.version' === $constraint) {
                $parsedConstraint = $this->versionParser->parseConstraints($sourceVersion);
            } else {
                $parsedConstraint = $this->versionParser->parseConstraints($constraint);
            }

            $res[strtolower($target)] = new Link($source, $target, $parsedConstraint, $description, $constraint);
        }

        return $res;
    }

    /**
     * Retrieves a branch alias (dev-master => 1.0.x-dev for example) if it exists
     *
     * @param  array       $config the entire package config
     * @return string|null normalized version of the branch alias or null if there is none
     */
    public function getBranchAlias(array $config)
    {
        if (('dev-' !== substr($config['version'], 0, 4) && '-dev' !== substr($config['version'], -4))
            || !isset($config['extra']['branch-alias'])
            || !is_array($config['extra']['branch-alias'])
        ) {
            return;
        }

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
            if (strtolower($config['version']) !== strtolower($sourceBranch)) {
                continue;
            }

            // If using numeric aliases ensure the alias is a valid subversion
            if (($sourcePrefix = $this->versionParser->parseNumericAliasPrefix($sourceBranch))
                && ($targetPrefix = $this->versionParser->parseNumericAliasPrefix($targetBranch))
                && (stripos($targetPrefix, $sourcePrefix) !== 0)
            ) {
                continue;
            }

            return $validatedTargetBranch;
        }
    }
}
