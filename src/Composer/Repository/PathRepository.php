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

use Composer\Config;
use Composer\IO\IOInterface;
use Composer\Json\JsonFile;
use Composer\Package\Loader\ArrayLoader;
use Composer\Package\Locker;
use Composer\Package\Version\VersionGuesser;
use Composer\Semver\VersionParser;
use Composer\Util\ProcessExecutor;

/**
 * This repository allows installing local packages that are not necessarily under their own VCS.
 *
 * The local packages will be symlinked when possible, else they will be copied.
 *
 * @code
 * "require": {
 *     "<vendor>/<local-package>": "*"
 * },
 * "repositories": [
 *     {
 *         "type": "path",
 *         "url": "../../relative/path/to/package/"
 *     },
 *     {
 *         "type": "path",
 *         "url": "/absolute/path/to/package/"
 *     },
 *     {
 *         "type": "path",
 *         "url": "/absolute/path/to/several/packages/*"
 *     }
 * ]
 * @endcode
 *
 * @author Samuel Roze <samuel.roze@gmail.com>
 * @author Johann Reinke <johann.reinke@gmail.com>
 */
class PathRepository extends ArrayRepository implements ConfigurableRepositoryInterface
{
    /**
     * @var ArrayLoader
     */
    private $loader;

    /**
     * @var VersionGuesser
     */
    private $versionGuesser;

    /**
     * @var string
     */
    private $url;

    /**
     * @var array
     */
    private $repoConfig;

    /**
     * @var ProcessExecutor
     */
    private $process;

    /**
     * Initializes path repository.
     *
     * @param array       $repoConfig
     * @param IOInterface $io
     * @param Config      $config
     */
    public function __construct(array $repoConfig, IOInterface $io, Config $config)
    {
        if (!isset($repoConfig['url'])) {
            throw new \RuntimeException('You must specify the `url` configuration for the path repository');
        }

        $this->loader = new ArrayLoader();
        $this->url = $repoConfig['url'];
        $this->process = new ProcessExecutor($io);
        $this->versionGuesser = new VersionGuesser($config, $this->process, new VersionParser());
        $this->repoConfig = $repoConfig;

        parent::__construct();
    }

    public function getRepoConfig()
    {
        return $this->repoConfig;
    }

    /**
     * Initializes path repository.
     *
     * This method will basically read the folder and add the found package.
     */
    protected function initialize()
    {
        parent::initialize();

        foreach ($this->getUrlMatches() as $url) {
            $path = realpath($url) . DIRECTORY_SEPARATOR;
            $composerFilePath = $path.'composer.json';

            if (!file_exists($composerFilePath)) {
                continue;
            }

            $json = file_get_contents($composerFilePath);
            $package = JsonFile::parseJson($json, $composerFilePath);
            $package['dist'] = array(
                'type' => 'path',
                'url' => $url,
                'reference' => sha1($json),
            );

            if (!isset($package['version'])) {
                $package['version'] = $this->versionGuesser->guessVersion($package, $path) ?: 'dev-master';
            }

            $output = '';
            if (is_dir($path . DIRECTORY_SEPARATOR . '.git') && 0 === $this->process->execute('git log -n1 --pretty=%H', $output, $path)) {
                $package['dist']['reference'] = trim($output);
            }

            $package = $this->loader->load($package);
            $this->addPackage($package);
        }

        if (count($this->getPackages()) == 0) {
            throw new \RuntimeException(sprintf('No `composer.json` file found in any path repository in "%s"', $this->url));
        }
    }

    /**
     * Get a list of all (possibly relative) path names matching given url (supports globbing).
     *
     * @return string[]
     */
    private function getUrlMatches()
    {
        // Ensure environment-specific path separators are normalized to URL separators
        return array_map(function ($val) {
            return str_replace(DIRECTORY_SEPARATOR, '/', $val);
        }, glob($this->url, GLOB_MARK | GLOB_ONLYDIR));
    }
}
