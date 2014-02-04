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

namespace Composer\Autoload\Plugin;


use Composer\Autoload\BuildDataInterface;
use Composer\Autoload\BuildInterface;
use Composer\Autoload\PathCodeBuilderInterface;
use Composer\Autoload\ClassLoader;

/**
 * Registers an accumulated classmap to the class loader, based on sources added
 * with addClassmapSource().
 *
 * This plugin does NOT look into ['autoload']['classmap'] in composer.json,
 * this is the job of the sources. See ClassmapPackageConsumer.
 *
 * @author Andreas hennings <andreas@dqxtech.net>
 */
class Classmap implements PluginInterface
{
    /**
     * @var ClassmapProviderInterface[]
     */
    private $providers = array();

    /**
     * @param ClassmapProviderInterface $provider
     */
    public function addClassmapProvider(ClassmapProviderInterface $provider)
    {
        $this->providers[] = $provider;
    }

    /**
     * @param ClassLoader $classLoader
     * @param bool $prependAutoloader
     */
    public function initClassLoader(ClassLoader $classLoader, $prependAutoloader)
    {
        // Attention, this is expensive!
        $classMap = $this->buildCombinedClassMap();
        $classLoader->addClassMap($classMap);
    }

    /**
     * @param BuildInterface $build
     * @param BuildDataInterface $buildData
     * @param PathCodeBuilderInterface $buildUtil
     */
    public function generate(BuildInterface $build, BuildDataInterface $buildData, PathCodeBuilderInterface $buildUtil)
    {
        $phpRows = '';
        foreach ($this->buildCombinedClassMap($buildData) as $class => $file) {
            $code = $buildUtil->getPathCode($file, $buildData);
            $phpRows .= '    ' . var_export($class, true) . ' => ' . $code . ",\n";
        }
        $build->addArraySourceFile('autoload_classmap.php', $phpRows);

        $build->addPhpSnippet(<<<'EOT'
        $classMap = require __DIR__ . '/autoload_classmap.php';
        if ($classMap) {
            $loader->addClassMap($classMap);
        }


EOT
        );
    }

    /**
     * @param BuildDataInterface $buildData
     * @return string[]
     */
    protected function buildCombinedClassMap(BuildDataInterface $buildData = null)
    {
        $classMap = array();
        foreach ($this->providers as $provider) {
            $classMap += $provider->buildClassMap($buildData);
        }
        ksort($classMap);
        return $classMap;
    }
}
