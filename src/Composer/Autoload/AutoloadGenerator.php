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

namespace Composer\Autoload;

use Composer\Config;
use Composer\Installer\InstallationManager;
use Composer\Package\AliasPackage;
use Composer\Package\PackageInterface;
use Composer\Package\Link as PackageLink;
use Composer\Repository\InstalledRepositoryInterface;
use Composer\Script\EventDispatcher;
use Composer\Script\ScriptEvents;

/**
 * @author Igor Wiedler <igor@wiedler.ch>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class AutoloadGenerator
{
    /**
     * @var EventDispatcher
     */
    private $eventDispatcher;

    public function __construct(EventDispatcher $eventDispatcher)
    {
        $this->eventDispatcher = $eventDispatcher;
    }

    public function dump(Config $config, InstalledRepositoryInterface $localRepo, PackageInterface $mainPackage, InstallationManager $installationManager, $targetDir, $scanPsr0Packages = false, $suffix = '')
    {
        $packageMap = $this->buildPackageMap($installationManager, $mainPackage, $localRepo->getCanonicalPackages());
        $autoloads = $this->parseAutoloads($packageMap, $mainPackage);

        $this->eventDispatcher->dispatch(ScriptEvents::PRE_AUTOLOAD_DUMP);
        $helper = new AutoloadGeneratorHelper($config->get('vendor-dir'), $targetDir);

        $vendorPath = $helper->getVendorPath();
        $targetPath = $vendorPath.'/'.$targetDir;

        $loaderSetupCode = '';

        $loaderSetupCode .= $helper->dumpNamespacesFile($autoloads['psr-0']);

        $classMap = $this->buildClassMap($helper, $autoloads, $scanPsr0Packages);
        $loaderSetupCode .= $helper->dumpClassMapFile($classMap);

        $loaderSetupCode .= $helper->dumpIncludePathsFile($packageMap);

        // add custom psr-0 autoloading if the root package has a target dir
        $targetDirLoader = null;
        $mainAutoload = $mainPackage->getAutoload();
        if ($mainPackage->getTargetDir() && !empty($mainAutoload['psr-0'])) {
            $targetDirLoader = $helper->getTargetDirLoaderMethod($mainPackage->getTargetDir(), $mainAutoload['psr-0']);
        }

        $useGlobalIncludePath = (bool) $config->get('use-include-path');

        if (!$suffix) {
            $suffix = md5(uniqid('', true));
        }

        $loaderSetupCode .= $this->buildLoaderSetupCode((bool) $targetDirLoader, $useGlobalIncludePath, $suffix);

        $loaderSetupCode .= $helper->dumpIncludeFilesFile($autoloads['files']);

        $helper->dumpAutoloadFile($suffix);
        $helper->dumpAutoloadRealFile($loaderSetupCode, $suffix, $targetDirLoader);

        // use stream_copy_to_stream instead of copy
        // to work around https://bugs.php.net/bug.php?id=64634
        $sourceLoader = fopen(__DIR__.'/ClassLoader.php', 'r');
        $targetLoader = fopen($targetPath.'/ClassLoader.php', 'w+');
        stream_copy_to_stream($sourceLoader, $targetLoader);
        fclose($sourceLoader);
        fclose($targetLoader);
        unset($sourceLoader, $targetLoader);

        $this->eventDispatcher->dispatch(ScriptEvents::POST_AUTOLOAD_DUMP);
    }

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
     * Registers an autoloader based on an autoload map returned by parseAutoloads
     *
     * @param  array       $autoloads see parseAutoloads return value
     * @return ClassLoader
     */
    public function createLoader(array $autoloads)
    {
        $loader = new ClassLoader();

        if (isset($autoloads['psr-0'])) {
            foreach ($autoloads['psr-0'] as $namespace => $path) {
                $loader->add($namespace, $path);
            }
        }

        return $loader;
    }

    protected function buildLoaderSetupCode($useTargetDirLoader, $useGlobalIncludePath, $suffix)
    {
        $loaderSetupCode = '';

        if ($useGlobalIncludePath) {
            $loaderSetupCode .= <<<'INCLUDEPATH'

        $loader->setUseIncludePath(true);
INCLUDEPATH;
        }

        if ($useTargetDirLoader) {
            $loaderSetupCode .= <<<REGISTER_AUTOLOAD

        spl_autoload_register(array('ComposerAutoloaderInit$suffix', 'autoload'), true, true);

REGISTER_AUTOLOAD;

        }

        $loaderSetupCode .= <<<REGISTER_LOADER

        \$loader->register(true);

REGISTER_LOADER;

        return $loaderSetupCode;
    }

    protected function buildClassMap(AutoloadGeneratorHelper $helper, array $autoloads, $scanPsr0Packages)
    {
        if ($scanPsr0Packages) {
            $classMap = $helper->buildClassMapFromPsr0Scan($autoloads['psr-0']);
        }
        else {
            $classMap = array();
        }

        foreach (new \RecursiveIteratorIterator(new \RecursiveArrayIterator($autoloads['classmap'])) as $dir) {
            foreach (ClassMapGenerator::createMap($dir) as $class => $path) {
                $classMap[$class] = $path;
            }
        }

        ksort($classMap);
        return $classMap;
    }

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
