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

/**
 * @author Serge Smertin <serg.smertin@gmail.com>
 */
use Composer\IO\IOInterface;
use Composer\Config;
use Composer\Json\JsonFile;
use Composer\Package\Loader\LoaderInterface;
use Composer\Package\Version\VersionParser;
use Composer\Package\Loader\ArrayLoader;

class ArtifactRepository extends ArrayRepository
{
    protected $path;

    /** @var LoaderInterface */
    protected $loader;

    public function __construct(array $repoConfig, IOInterface $io, Config $config, array $drivers = null)
    {
        $this->path = $repoConfig['url'];
    }

    protected function initialize()
    {
        parent::initialize();
        $this->versionParser = new VersionParser;
        if (!$this->loader) {
            $this->loader = new ArrayLoader($this->versionParser);
        }

        $this->getDirectoryPackages($this->path);
    }

    private function getDirectoryPackages($path)
    {
        foreach(new \RecursiveDirectoryIterator($path) as $file) {
            /* @var $file \SplFileInfo */
            if(!$file->isFile()) {
                continue;
            }

            $package = $this->getComposerInformation($file);
            if(!$package) {
                // @todo add log
                continue;
            }

            $package = $this->loader->load($package);

            $this->addPackage($package);
        }
    }

    private function getComposerInformation(\SplFileInfo $file)
    {
        $config = "zip://{$file->getPathname()}#composer.json";
        $json = @file_get_contents($config);
        if(!$json) {
            return false;
        }

        return JsonFile::parseJson($json, $config);
    }
}
