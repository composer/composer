<?php

namespace Composer\Autoload\Generator;

use Composer\Autoload\Generator\AutoloadGeneratorHelper;
use Composer\Autoload\Generator;
use Composer\Autoload\Generator\PackageMapProcessor;
use Composer\Config;
use Composer\Installer\InstallationManager;
use Composer\Package\AliasPackage;
use Composer\Package\PackageInterface;
use Composer\Repository\InstalledRepositoryInterface;
use Composer\Util\Filesystem;

/**
 * @property Config config
 * @property InstalledRepositoryInterface localRepo
 * @property PackageInterface mainPackage
 * @property InstallationManager installationManager
 * @property string targetDir
 *   The string to append to
 * @property bool scanPsr0Packages
 * @property string suffix
 *
 * @property array packageMap
 * @property PackageMapProcessor packageMapProcessor
 * @property array autoloads
 * @property bool useGlobalIncludePath
 * @property AutoloadGeneratorHelper helper
 * @property Filesystem filesystem
 * @property string basePath
 * @property string vendorPath
 * @property string targetPath
 * @property string vendorPathCode
 * @property string vendorPathCode52
 * @property string vendorPathToTargetDirCode
 * @property string appBaseDirCode
 * @property array generatorAspects
 * @property \Composer\Autoload\Generator\Aspect\ClassMapAspect classMapAspect
 * @property \Composer\Autoload\Generator\Aspect\TargetDirAspect targetDirAspect
 */
class AutoloadGenerationTask {

    /**
     * @var array
     *   Lazy-generated data.
     */
    protected $data = array();

    /**
     * @param array $data
     *   Starting values for some data keys.
     *   @todo verify required keys
     */
    public function __construct(array $data)
    {
        $this->data = $data;
    }

    /**
     * Gets a lazy value.
     *
     * @param $key
     * @return mixed
     */
    public function __get($key)
    {
        if (array_key_exists($key, $this->data)) {
            return $this->data[$key];
        }
        return $this->data[$key] = $this->{'get_' . $key}();
    }

    /**
     * @return bool
     */
    protected function get_useGlobalIncludePath()
    {
        return (bool) $this->config->get('use-include-path');
    }

    /**
     * @return array
     */
    protected function get_packageMap()
    {
        // build package => install path map
        $packageMap = array(array($this->mainPackage, ''));

        foreach ($this->localRepo->getCanonicalPackages() as $package) {
            if ($package instanceof AliasPackage) {
                continue;
            }

            $packageMap[] = array(
                $package,
                $this->installationManager->getInstallPath($package)
            );
        }

        return $packageMap;
    }

    /**
     * @return PackageMapProcessor
     */
    protected function get_packageMapProcessor()
    {
        return new PackageMapProcessor();
    }

    /**
     * @return array
     */
    protected function get_autoloads()
    {
        return $this->packageMapProcessor->parseAutoloads($this->packageMap, $this->mainPackage);
    }

    /**
     * @return AutoloadGeneratorHelper
     */
    protected function get_helper()
    {
        return new AutoloadGeneratorHelper($this->config->get('vendor-dir'), $this->targetDir);
    }

    /**
     * @return Filesystem
     */
    protected function get_filesystem()
    {
        return new Filesystem();
    }

    /**
     * @return string
     */
    protected function get_basePath() {
        return $this->filesystem->normalizePath(getcwd());
    }

    /**
     * @return string
     */
    protected function get_vendorPath()
    {
        $vendorDir = $this->config->get('vendor-dir');
        $this->filesystem->ensureDirectoryExists($vendorDir);
        return $this->filesystem->normalizePath(realpath($vendorDir));
    }

    /**
     * @return string
     */
    protected function get_targetPath()
    {
        $targetPath = $this->vendorPath . '/' . $this->targetDir;
        $this->filesystem->ensureDirectoryExists($targetPath);
        return $targetPath;
    }

    /**
     * @return string
     */
    protected function get_vendorPathCode() {
        return $this->filesystem->findShortestPathCode(realpath($this->targetPath), $this->vendorPath, true);
    }

    /**
     * @return string
     */
    protected function get_vendorPathCode52() {
        return str_replace('__DIR__', 'dirname(__FILE__)', $this->vendorPathCode);
    }

    /**
     * @return string
     */
    protected function get_vendorPathToTargetDirCode() {
        return $this->filesystem->findShortestPathCode($this->vendorPath, realpath($this->targetPath), true);
    }

    /**
     * @return string
     */
    protected function get_appBaseDirCode() {
        $appBaseDirCode = $this->filesystem->findShortestPathCode($this->vendorPath, $this->basePath, true);
        return str_replace('__DIR__', '$vendorDir', $appBaseDirCode);
    }

    /**
     * @return array
     */
    protected function get_generatorAspects()
    {
        $aspects = array();
        $aspects[] = new Generator\Aspect\Psr0Aspect($this->autoloads['psr-0']);
        $aspects[] = $this->classMapAspect;
        $aspects[] = new Generator\Aspect\IncludePathAspect($this->packageMap);
        $aspects[] = new Generator\Aspect\UseGlobalIncludePathAspect();
        if ($this->targetDirAspect) {
            $aspects[] = $this->targetDirAspect;
        }
        $aspects[] = new Generator\Aspect\RegisterLoaderAspect();
        $aspects[] = new Generator\Aspect\IncludeFilesAspect($this->autoloads['files']);

        return $aspects;
    }

    /**
     * @return \Composer\Autoload\Generator\Aspect\ClassMapAspect
     */
    protected function get_classMapAspect()
    {
        $aspect = new Generator\Aspect\ClassMapAspect;
        if ($this->scanPsr0Packages) {
            foreach ($this->autoloads['psr-0'] as $namespace => $paths) {
                foreach ($paths as $dir) {
                    $dir = $this->filesystem->isAbsolutePath($dir) ? $dir : $this->basePath.'/'.$dir;
                    $dir = $this->filesystem->normalizePath($dir);
                    $aspect->scanPsr0Dir($namespace, $dir);
                }
            }
        }

        foreach (new \RecursiveIteratorIterator(new \RecursiveArrayIterator($this->autoloads['classmap'])) as $dir) {
            $aspect->scanDir($dir);
        }

        return $aspect;
    }

    /**
     * @return Aspect\TargetDirAspect|null
     */
    protected function get_targetDirAspect()
    {
        // Add custom psr-0 autoloading, if the root package has a target dir.
        $mainAutoload = $this->mainPackage->getAutoload();
        if (1
            && ($targetDir = $this->mainPackage->getTargetDir())
            && !empty($mainAutoload['psr-0'])
        ) {
            return new Generator\Aspect\TargetDirAspect($targetDir, $mainAutoload['psr-0']);
        }

        // No target dir handling required.
        return null;
    }
}
