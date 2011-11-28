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
use Composer\Repository\RepositoryManager;

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
    private $manager;

    public function __construct(RepositoryManager $manager, $parser = null)
    {
        $this->manager = $manager;
        $this->versionParser = $parser;
        if (!$parser) {
            $this->versionParser = new Package\Version\VersionParser;
        }
    }

    public function load($config)
    {
        $prettyVersion = isset($config['version']) ? $config['version'] : '0.0.0';
        // handle already normalized versions
        if (isset($config['version_normalized'])) {
            $version = $config['version_normalized'];
        } else {
            $version = $this->versionParser->normalize($prettyVersion);
        }
        $package = new Package\MemoryPackage(isset($config['name']) ? $config['name'] : '__app__', $version, $prettyVersion);

        $package->setType(isset($config['type']) ? $config['type'] : 'library');

        if (isset($config['target-dir'])) {
            $package->setTargetDir($config['target-dir']);
        }

        if (isset($config['repositories'])) {
            $repositories = array();
            foreach ($config['repositories'] as $repoName => $repo) {
                if (false === $repo && 'packagist' === $repoName) {
                    continue;
                }
                if (!is_array($repo)) {
                    throw new \UnexpectedValueException('Repository '.$repoName.' in '.$package->getPrettyName().' '.$package->getVersion().' should be an array, '.gettype($repo).' given');
                }
                $repository = $this->manager->createRepository(key($repo), current($repo));
                $this->manager->addRepository($repository);
            }
            $package->setRepositories($config['repositories']);
        }

        if (isset($config['extra']) && is_array($config['extra'])) {
            $package->setExtra($config['extra']);
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
                $package->setReleaseDate(new \DateTime($config['time']));
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

        foreach ($this->supportedLinkTypes as $type => $description) {
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
                $constraint = $package->getVersion();
            }
            $parsedConstraint = $this->versionParser->parseConstraints($constraint);
            $links[]    = new Package\Link($package->getName(), $packageName, $parsedConstraint, $description, $constraint);
        }

        return $links;
    }
}
