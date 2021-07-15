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
use Composer\Util\Platform;
use Composer\Util\Filesystem;
use Composer\Util\Url;

/**
 * This repository allows installing packages already installed in another app.
 *
 * The app's packages will be symlinked when possible.
 *
 * @code
 * "require": {
 *     "app/app": "*",
 *     "<vendor>/<package>": "*"
 * },
 * "repositories": [
 *     {
 *         "type": "app",
 *         "url": "../../relative/path/to/app/"
 *     },
 *     {
 *         "type": "app",
 *         "url": "/absolute/path/to/app/"
 *     },
 *     {
 *         "type": "app",
 *         "url": "../../relative/path/to/app/",
 *         "canonical": false
 *     },
 * ]
 * @endcode
 *
 * @author Nicolas Grekas <p@tchwork.com>
 */
class AppRepository extends ArrayRepository implements ConfigurableRepositoryInterface
{
    /**
     * @var ArrayLoader
     */
    private $loader;

    /**
     * @var string
     */
    private $url;

    /**
     * @var array
     */
    private $repoConfig;

    /**
     * @var array
     */
    private $options;

    /**
     * Initializes app repository.
     */
    public function __construct(array $repoConfig)
    {
        if (!isset($repoConfig['url'])) {
            throw new \RuntimeException('You must specify the `url` configuration for the app repository');
        }

        $this->loader = new ArrayLoader(null, true);
        $this->url = Platform::expandPath($repoConfig['url']);
        $this->repoConfig = $repoConfig;
        $this->options = isset($repoConfig['options']) ? $repoConfig['options'] : array();
        if (!isset($this->options['relative'])) {
            $filesystem = new Filesystem();
            $this->options['relative'] = !$filesystem->isAbsolutePath($this->url);
        }

        parent::__construct();
    }

    public function getRepoName()
    {
        return 'app repo ('.Url::sanitize($this->repoConfig['url']).')';
    }

    public function getRepoConfig()
    {
        return $this->repoConfig;
    }

    /**
     * Initializes app repository.
     *
     * This method will basically read the folder and add the found package.
     */
    protected function initialize()
    {
        parent::initialize();

        $composerFilePath = $this->url.'/composer.json';
        if (!file_exists($composerFilePath)) {
            throw new \RuntimeException('Cannot find composer.json in the `url` supplied for the app (' . $this->url . ') repository');
        }

        $json = file_get_contents($composerFilePath);
        $package = JsonFile::parseJson($json, $composerFilePath);
        $package['name'] = 'app/app';
        $package['version'] = '99999';
        $package['dist'] = array(
            'type' => 'path',
            'url' => $this->url,
            'reference' => sha1(serialize($this->options)),
        );
        $package['transport-options'] = $this->options;

        $this->addPackage($this->loader->load($package));

        $vendorDir = $this->url . '/' . (isset($package['config']['vendor-dir']) ? $package['config']['vendor-dir'] : 'vendor') . '/composer/';
        $installedFilePath = $vendorDir . 'installed.json';
        if (!file_exists($installedFilePath)) {
            throw new \RuntimeException('Cannot find installed.json in the vendor directory of the app (' . $this->url . ') repository, did you forget to run `composer install` in the app?');
        }

        $json = file_get_contents($installedFilePath);
        $packages = JsonFile::parseJson($json, $installedFilePath);

        foreach ($packages['packages'] as $package) {
            $package['dist'] = array(
                'type' => 'path',
                'url' => $vendorDir . $package['install-path'],
                'reference' => sha1(serialize(array($vendorDir, $package, $this->options))),
            );
            $package['transport-options'] = $this->options;
            unset($package['install-path']);

            $this->addPackage($this->loader->load($package));
        }
    }
}
