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

use Composer\Json\JsonFile;
use Composer\Package\Loader\ArrayLoader;
use Composer\Package\Dumper\ArrayDumper;
use Composer\Package\Version\VersionParser;
use Composer\Factory;
use Composer\IO\NullIO;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Config;

/**
 * Package repository.
 *
 * @author Filip Procházka <filip.prochazka@kdyby.org>
 */
class LocalLinksRepository implements WritableRepositoryInterface
{
    private $config;
    private $io;

    private $linksJson;
    private $packages;

    public function __construct(Config $config = null, IOInterface $io = null)
    {
        $this->config = $config ?: Factory::createConfig();
        $this->io = $io ?: new NullIO();

        $this->linksJson = new JsonFile($this->config->get('home') . '/links.json');
    }

    public function addPackage(PackageInterface $package)
    {
        if (null === $this->packages) {
            $this->reload();
        }

        $packageName = $package->getName();
        if (empty($packageName) || $packageName === '__root__') {
            throw new \InvalidArgumentException("Package has no name specified.");
        }

        if (isset($this->packages[$packageName])) {
            return;
        }

        $sourcePath = realpath(str_replace('file://', '', $package->getSourceUrl()));
        if ($sourcePath === false) {
            throw new \InvalidArgumentException("Package " . $package->getPrettyName() . " must have a source url specified, that is in local filesystem.");
        }

        $this->packages[$packageName] = $sourcePath;
        $this->write();
    }

    public function removePackage(PackageInterface $package)
    {
        if (null === $this->packages) {
            $this->reload();
        }

        unset($this->packages[$package->getName()]);
        $this->write();
    }

    /**
     * @internal
     */
    public function reload()
    {
        if (!$this->linksJson->exists()) {
            return;
        }

        $this->packages = array();
        foreach ($this->linksJson->read() as $package) {
            $this->packages[$package['package']] = $package['path'];
        }
    }

    /**
     * @internal
     */
    public function write()
    {
        if (null === $this->packages) {
            return;
        }

        $data = array();
        foreach ($this->packages as $packageName => $sourcePath) {
            $data[] = array('package' => $packageName, 'path' => $sourcePath);
        }

        $this->linksJson->write($data);
    }

    public function hasPackage(PackageInterface $package)
    {
        if (null === $this->packages) {
            $this->reload();
        }

        return isset($this->packages[$package->getName()]);
    }

    public function findPackage($name, $version)
    {
        if (null === $this->packages) {
            $this->reload();
        }

        // normalize name
        $name = strtolower($name);

        if (!isset($this->packages[$name])) {
            return;
        }

        // normalize version & name
        $versionParser = new VersionParser();
        $version = $versionParser->normalize($version);

        foreach ($this->packageVersions($name) as $package) {
            if ($name === $package->getName() && $version === $package->getVersion()) {
                $package->setInstallationSource('link');
//                $package->setSourceType('link');
                return $package;
            }
        }
    }

    public function findPackages($name, $version = null)
    {
        if (null === $this->packages) {
            $this->reload();
        }

        // normalize name
        $name = strtolower($name);

        $packages = array();

        if (!isset($this->packages[$name])) {
            return $packages;
        }

        // normalize version
        if (null !== $version) {
            $versionParser = new VersionParser();
            $version = $versionParser->normalize($version);
        }

        foreach ($this->packageVersions($name) as $package) {
            if ($package->getName() === $name && (null === $version || $version === $package->getVersion())) {
                $package->setInstallationSource('link');
//                $package->setSourceType('link');
                $packages[] = $package;
            }
        }

        return $packages;
    }

    public function getPackages()
    {
        if (null === $this->packages) {
            $this->reload();
        }

        $packages = array();

        foreach ($this->packages as $packageName => $sourcePath) {
            $packages[] = $this->findPackages($packageName);
        }

        return call_user_func_array('array_merge', $packages);
    }

    public function count()
    {
        if (null === $this->packages) {
            $this->reload();
        }

        return count($this->packages);
    }

    private function packageVersions($name)
    {
        $repoConf = array('url' => $this->packages[$name], 'type' => 'vcs');
        $vcsRepo = new VcsRepository($repoConf, $this->io, $this->config);

        $packages = array();
        foreach ($vcsRepo->getPackages() as $package) {
            /** @var \Composer\Package\MemoryPackage $package */
            $package = clone $package;
            $package->setRepository($this);
            $packages[] = $package;
        }

        return $packages;
    }

}
