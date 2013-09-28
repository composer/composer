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

use Composer\Autoload\Generator\Aspect\ExtraMethodsAspectInterface;
use Composer\Autoload\Generator\AutoloadGenerationTask;
use Composer\Config;
use Composer\Installer\InstallationManager;
use Composer\Package\PackageInterface;
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

    /**
     * @param EventDispatcher $eventDispatcher
     */
    public function __construct(EventDispatcher $eventDispatcher)
    {
        $this->eventDispatcher = $eventDispatcher;
    }

    /**
     * @param Config $config
     * @param InstalledRepositoryInterface $localRepo
     * @param PackageInterface $mainPackage
     * @param InstallationManager $installationManager
     * @param $targetDir
     *   The string to append to
     * @param bool $scanPsr0Packages
     * @param string $suffix
     */
    public function dump(Config $config, InstalledRepositoryInterface $localRepo, PackageInterface $mainPackage, InstallationManager $installationManager, $targetDir, $scanPsr0Packages = false, $suffix = '')
    {
        $this->eventDispatcher->dispatch(ScriptEvents::PRE_AUTOLOAD_DUMP);

        if (!$suffix) {
            $suffix = md5(uniqid('', true));
        }

        $task = new AutoloadGenerationTask(compact('config', 'localRepo', 'mainPackage', 'installationManager', 'targetDir', 'scanPsr0Packages', 'suffix'));

        // Assemble code snippets for ComposerAutoloaderInit.
        $loaderSetupCode = '';
        $extraMethodsCode = '';

        /**
         * @var \Composer\Autoload\Generator\Aspect\AutoloadGeneratorAspectInterface $aspect
         */
        foreach ($task->generatorAspects as $aspect) {
            $loaderSetupCode .= $aspect->dumpAndGetSnippet($task);
            if ($aspect instanceof ExtraMethodsAspectInterface) {
                /**
                 * @var ExtraMethodsAspectInterface $aspect
                 */
                $extraMethodsCode .= $aspect->getExtraMethods($task);
            }
        }

        $task->helper->dumpAutoloadFile($task);
        $task->helper->dumpAutoloadRealFile($task, $loaderSetupCode, $extraMethodsCode);
        $task->helper->dumpClassLoader($task->targetPath);

        $this->eventDispatcher->dispatch(ScriptEvents::POST_AUTOLOAD_DUMP);
    }

    /**
     * @param InstallationManager $installationManager
     * @param PackageInterface $mainPackage
     * @param array $packages
     * @return array
     */
    public function buildPackageMap(InstallationManager $installationManager, PackageInterface $mainPackage, array $packages)
    {
        $task = new AutoloadGenerationTask(compact('installationManager', 'mainPackage', 'packages'));
        return $task->packageMap;
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
        $task = new AutoloadGenerationTask(compact('packageMap', 'mainPackage'));
        return $task->autoloads;
    }

    /**
     * Registers an autoloader based on an autoload map returned by parseAutoloads()
     *
     * @param  array       $autoloads see parseAutoloads() return value
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
}
