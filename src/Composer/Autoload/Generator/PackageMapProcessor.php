<?php

namespace Composer\Autoload\Generator;

use Composer\Installer\InstallationManager;
use Composer\Package\AliasPackage;
use Composer\Package\PackageInterface;
use Composer\Package\Link as PackageLink;


class PackageMapProcessor
{
    /**
     * @param InstallationManager $installationManager
     * @param PackageInterface $mainPackage
     * @param array $packages
     * @return array
     */
    public function buildPackageMap(InstallationManager $installationManager, PackageInterface $mainPackage, array $packages)
    {
        // build package => install path map
        $packageMap = array(array($mainPackage, ''));

        foreach ($packages as $package) {
            if ($package instanceof AliasPackage) {
                continue;
            }

            $packageMap[] = array(
                $package,
                $installationManager->getInstallPath($package)
            );
        }

        return $packageMap;
    }

    /**
     * Compiles an ordered list of namespace => path mappings
     *
     * @param  array            $packageMap  array of array(package, installDir-relative-to-composer.json)
     * @param  PackageInterface $mainPackage root package instance
     * @return array            array('psr-0' => array('Ns\\Foo' => array('installDir')))
     */
    public function parseAutoloads(array $packageMap, PackageInterface $mainPackage)
    {
        $mainPackageMap = array_shift($packageMap);
        $sortedPackageMap = $this->sortPackageMap($packageMap);
        $sortedPackageMap[] = $mainPackageMap;
        array_unshift($packageMap, $mainPackageMap);

        $psr0 = $this->parseAutoloadsType($packageMap, 'psr-0', $mainPackage);
        $classmap = $this->parseAutoloadsType($sortedPackageMap, 'classmap', $mainPackage);
        $files = $this->parseAutoloadsType($sortedPackageMap, 'files', $mainPackage);

        krsort($psr0);

        return array('psr-0' => $psr0, 'classmap' => $classmap, 'files' => $files);
    }

    /**
     * @param array $packageMap
     * @param $type
     * @param PackageInterface $mainPackage
     * @return array
     */
    protected function parseAutoloadsType(array $packageMap, $type, PackageInterface $mainPackage)
    {
        $autoloads = array();

        foreach ($packageMap as $item) {
            /**
             * @var PackageInterface $package
             */
            list($package, $installPath) = $item;

            $autoload = $package->getAutoload();

            // skip misconfigured packages
            if (!isset($autoload[$type]) || !is_array($autoload[$type])) {
                continue;
            }
            if (null !== $package->getTargetDir() && $package !== $mainPackage) {
                $installPath = substr($installPath, 0, -strlen('/'.$package->getTargetDir()));
            }

            foreach ($autoload[$type] as $namespace => $paths) {
                foreach ((array) $paths as $path) {
                    // remove target-dir from file paths of the root package
                    if ($type === 'files' && $package === $mainPackage && $package->getTargetDir() && !is_readable($installPath.'/'.$path)) {
                        $targetDir = str_replace('\\<dirsep\\>', '[\\\\/]', preg_quote(str_replace(array('/', '\\'), '<dirsep>', $package->getTargetDir())));
                        $path = ltrim(preg_replace('{^'.$targetDir.'}', '', ltrim($path, '\\/')), '\\/');
                    }

                    // add target-dir from file paths that don't have it
                    if ($type === 'files' && $package !== $mainPackage && $package->getTargetDir() && !is_readable($installPath.'/'.$path)) {
                        $path = $package->getTargetDir() . '/' . $path;
                    }

                    // remove target-dir from classmap entries of the root package
                    if ($type === 'classmap' && $package === $mainPackage && $package->getTargetDir() && !is_readable($installPath.'/'.$path)) {
                        $targetDir = str_replace('\\<dirsep\\>', '[\\\\/]', preg_quote(str_replace(array('/', '\\'), '<dirsep>', $package->getTargetDir())));
                        $path = ltrim(preg_replace('{^'.$targetDir.'}', '', ltrim($path, '\\/')), '\\/');
                    }

                    // add target-dir to classmap entries that don't have it
                    if ($type === 'classmap' && $package !== $mainPackage && $package->getTargetDir() && !is_readable($installPath.'/'.$path)) {
                        $path = $package->getTargetDir() . '/' . $path;
                    }

                    if (empty($installPath)) {
                        $autoloads[$namespace][] = empty($path) ? '.' : $path;
                    } else {
                        $autoloads[$namespace][] = $installPath.'/'.$path;
                    }
                }
            }
        }

        return $autoloads;
    }

    /**
     * @param array $packageMap
     * @return array
     */
    protected function sortPackageMap(array $packageMap)
    {
        $positions = array();
        $names = array();
        $indexes = array();

        foreach ($packageMap as $position => $item) {
            /**
             * @var PackageInterface $package
             */
            $package = $item[0];
            $mainName = $package->getName();
            $names = array_merge(array_fill_keys($package->getNames(), $mainName), $names);
            $names[$mainName] = $mainName;
            $indexes[$mainName] = $positions[$mainName] = $position;
        }

        foreach ($packageMap as $item) {
            /**
             * @var PackageInterface $package
             */
            $package = $item[0];
            $position = $positions[$package->getName()];
            /**
             * @var PackageLink $link
             */
            foreach (array_merge($package->getRequires(), $package->getDevRequires()) as $link) {
                $target = $link->getTarget();
                if (!isset($names[$target])) {
                    continue;
                }

                $target = $names[$target];
                if ($positions[$target] <= $position) {
                    continue;
                }

                foreach ($positions as $key => $value) {
                    if ($value >= $position) {
                        break;
                    }
                    $positions[$key]--;
                }

                $positions[$target] = $position - 1;
            }
            asort($positions);
        }

        $sortedPackageMap = array();
        foreach (array_keys($positions) as $packageName) {
            $sortedPackageMap[] = $packageMap[$indexes[$packageName]];
        }

        return $sortedPackageMap;
    }
}