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
use Composer\Autoload\ClassMapGenerator;
use Composer\Package\SortedPackageConsumerInterface;

/**
 * Scans the ['autoload']['classmap'] in composer.json, and exposes a classmap
 * via the ->buildClassMap() method.
 *
 * @author Andreas hennings <andreas@dqxtech.net>
 */
class ClassmapPackageConsumer extends AbstractAutoloadType implements ClassmapProviderInterface, SortedPackageConsumerInterface
{
    /**
     * Overrides property from AbstractPackageConsumer.
     *
     * @var string
     */
    protected $type = 'classmap';

    /**
     * Overrides property from AbstractPackageConsumer.
     *
     * @var bool
     */
    protected $mustResolveTargetDir = true;

    /**
     * @param string $path
     * @param string $targetDir
     * @param bool $isMainPackage
     *
     * @return string
     */
    protected function pathResolveTargetDir($path, $targetDir, $isMainPackage)
    {
        if ($isMainPackage) {
            // remove target-dir from classmap entries of the root package
            $targetDir = str_replace('\\<dirsep\\>', '[\\\\/]', preg_quote(str_replace(array('/', '\\'), '<dirsep>', $targetDir)));
            return ltrim(preg_replace('{^'.$targetDir.'}', '', ltrim($path, '\\/')), '\\/');
        }
        else {
            // add target-dir to classmap entries that don't have it
            return $targetDir . '/' . $path;
        }
    }

    /**
     * Implements ClassmapProviderInterface::buildClassMap()
     *
     * @param BuildDataInterface $buildData
     * @return string[]
     *   Class map.
     */
    public function buildClassMap(BuildDataInterface $buildData = null)
    {
        ksort($this->map);
        $iterator = new \RecursiveIteratorIterator(new \RecursiveArrayIterator($this->map));
        $classMap = array();
        foreach ($iterator as $dir) {
            foreach (ClassMapGenerator::createMap($dir) as $class => $path) {
                $classMap[$class] = $path;
            }
        }
        return $classMap;
    }
}
