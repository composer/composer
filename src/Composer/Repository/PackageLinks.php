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

use Composer\IO\IOInterface;
use Composer\Json\JsonFile;
use Composer\Package\Link;
use Composer\Package\LinkPackage;
use Composer\Package\Loader\LoaderInterface;
use Composer\Package\Loader\ValidatingArrayLoader;
use Composer\Package\PackageInterface;
use Composer\Semver\Constraint\Constraint;
use Composer\Util\Filesystem;

/**
 * Manages local "links" to packages.
 *
 * @author Giuseppe Mazzapica <giuseppe.mazzapica@gmail.com>
 */
class PackageLinks
{
    protected $io;
    protected $filesystem;
    protected $loader;

    /** @var array<string, array{path:string, repo:string}> */
    protected $packagesData = array();

    /** @var LinkPackage[] */
    protected $packages = array();

    /** @var ArrayRepository<LinkPackage> */
    protected $repository;

    /** @var LinkPackage[] */
    protected $required = array();

    /** @var array<string, bool> */
    private $linksRepoLoaded = array();

    /** @var bool */
    private $packagesDataLoaded = false;

    /** @var bool */
    private $fromLocked;

    /**
     * @param IOInterface $io
     * @param LoaderInterface $loader
     * @param Filesystem $filesystem
     * @param bool $fromLocked
     */
    public function __construct(IOInterface $io, LoaderInterface $loader, Filesystem $filesystem, $fromLocked = false)
    {
        $this->io = $io;
        $this->loader = $loader;
        $this->filesystem = $filesystem;
        $this->repository = new ArrayRepository();
        $this->fromLocked = (bool)$fromLocked;
    }

    /**
     * Loads `composer-links.json` from given path and stores info for all packages in it.
     *
     * @param string $path
     */
    public function loadLinksFromPath($path)
    {
        $repoPath = $this->filesystem->normalizePath($path);
        if (!$repoPath || isset($this->linksRepoLoaded[$repoPath])) {
            return;
        }

        // We're not going to load same file more than once.
        $this->linksRepoLoaded[$repoPath] = true;

        $jsonFile = new JsonFile($repoPath.'/composer-links.json');
        $repo = $jsonFile->getPath();

        if (!$jsonFile->exists()) {
            $this->io->writeError(
                'Links repository '.$repo.' does not exists.',
                true,
                IOInterface::VERBOSE
            );

            return;
        }

        $linksData = $jsonFile->read();
        $invalidMsg = 'Links repository '.$repo.' does not contain valid package links data.';

        if (!is_array($linksData) || empty($linksData['packages']) || !is_array($linksData['packages'])) {
            throw new \UnexpectedValueException($invalidMsg);
        }

        $this->io->writeError(
            '<info>Loading link packages data from '.$repo.'</info>',
            true,
            IOInterface::VERBOSE
        );

        $loaded = 0;
        foreach ($linksData['packages'] as $linkPackage) {
            if (!is_array($linkPackage) || empty($linkPackage['name']) || empty($linkPackage['path']) || ValidatingArrayLoader::hasPackageNamingError($linkPackage['name'])) {
                throw new \UnexpectedValueException($invalidMsg);
            }

            $name = $linkPackage['name'];
            $path = $this->filesystem->normalizePath($linkPackage['path']);

            if (!empty($this->packagesData[$name])) {
                // If a package was already linked from another links repository using a different
                // path we need to bail because we can't load a package from two different paths.
                if ($path !== $this->packagesData[$name]['path']) {
                    throw new \RuntimeException(
                        sprintf(
                            "Package \"%s\" is already linked\nfrom \"%s\" via \"%s\",\ncan't be linked from \"%s\" as set in \"%s\".",
                            $name,
                            $this->packagesData[$name]['path'],
                            $this->packagesData[$name]['repo'],
                            $path,
                            $repo
                        )
                    );
                }
                continue;
            }

            $loaded++;
            $this->packagesData[$name] = compact('path', 'repo');
        }

        if ($loaded > 0) {
            // Set this to false to inform `getAllPackages` that there are new packages to load.
            $this->packagesDataLoaded = false;
        }
    }

    /**
     * @return string[]
     */
    public function getLoadedLinkRepoPaths()
    {
        return array_keys($this->linksRepoLoaded);
    }

    /**
     * @return bool
     */
    public function hasPackages()
    {
        return (bool)$this->packagesData;
    }

    /**
     * @param string $package
     * @return bool
     */
    public function hasPackage($package)
    {
        return !empty($this->packagesData[$package]);
    }

    /**
     * @return string[]
     */
    public function getAllPackageNames()
    {
        return array_keys($this->packagesData);
    }

    /**
     * @return LinkPackage[]
     */
    public function getAllPackages()
    {
        if (!$this->packagesDataLoaded) {
            array_map(array($this, 'getPackage'), $this->getAllPackageNames());
            $this->packagesDataLoaded = true;
        }

        return $this->packages;
    }

    /**
     * @param string $name
     * @return LinkPackage
     */
    public function getPackage($name)
    {
        if (isset($this->packages[$name])) {
            return $this->packages[$name];
        }

        if (!$this->hasPackage($name)) {
            throw new \UnexpectedValueException('"'.$name.'" is not a linked package.');
        }

        $json = new JsonFile($this->packagesData[$name]['path'].'/composer.json');
        $json->validateSchema();

        $data = $json->read();
        unset($data['source'], $data['dist'], $data['installation-source']);
        $data['version'] = 'dev-local';

        /** @var LinkPackage $package */
        $package = $this->loader->load($data, 'Composer\Package\LinkPackage');

        $package->setDistType('path');
        $package->setDistUrl($this->packagesData[$name]['path']);
        $package->setDistReference('local');
        $package->setDistSha1Checksum(sha1(serialize($data)));
        $package->setLinkRepoPath($this->packagesData[$name]['repo']);
        $this->packages[$name] = $package;
        $this->repository->addPackage($package);

        return $package;
    }

    /**
     * @return ArrayRepository
     */
    public function getRepository()
    {
        // Ensures all packages data is loaded.
        $this->getAllPackages();

        return $this->repository;
    }

    /**
     * @param PackageInterface|Link|array $reasonData
     * @return void
     */
    public function setRequired($reasonData)
    {
        $isArray = is_array($reasonData);
        if ($isArray && isset($reasonData['package'])) {
            $reasonData = $reasonData['package'];
        } elseif ($isArray && isset($reasonData['packageName'])) {
            $reasonData = $this->getPackage($reasonData['packageName']);
        }

        $version = '';
        if ($reasonData instanceof PackageInterface) {
            $version = $reasonData->getVersion();
            if (!$reasonData instanceof LinkPackage) {
                $reasonData = $this->getPackage($reasonData->getName());
            }
        }

        $isLink = $reasonData instanceof Link;
        $package = $isLink ? $this->getPackage($reasonData->getTarget()) : $reasonData;
        if (!$package instanceof LinkPackage) {
            return;
        }

        if (!$this->fromLocked || $version !== 'dev-local') {
            $constraint = $isLink ? $reasonData->getConstraint() : new Constraint('==', $version);
            $package->checkConstraint($constraint);
        }

        // Use name as key to ensure same package is added once.
        $this->required[$package->getName()] = $package;
    }

    /**
     * @return LinkPackage[]
     */
    public function getAllRequired()
    {
        return array_values($this->required);
    }
}
