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
use Composer\Autoload\ClassMapGenerator;
use Composer\Package\Loader\InvalidPackageException;
use Composer\Package\PackageInterface;

/**
 * Base class for PSR-0 and PSR-4 plugins.
 */
class Psr0Psr4 extends AbstractAutoloadType implements PluginInterface, ClassmapProviderInterface
{
    /**
     * @var bool
     */
    protected $isPsr4;

    /**
     * @var string $filename
     *   Either 'autoload_namespaces.php' or 'autoload_psr4.php'.
     */
    protected $filename;

    /**
     * @param string $type
     *   Either 'psr-0' or 'psr-4'.
     *
     * @throws \Exception
     *   Argument was something other than 'psr-0' or 'psr-4'.
     */
    function __construct($type)
    {
        switch ($type) {
            case 'psr-0':
                $this->type = 'psr-0';
                $this->isPsr4 = false;
                $this->filename = 'autoload_namespaces.php';
                break;
            case 'psr-4':
                $this->type = 'psr-4';
                $this->isPsr4 = true;
                $this->filename = 'autoload_psr4.php';
                break;
            default:
                throw new \Exception("Invalid argument '$type'.");
        }
    }

    /**
     * @param PackageInterface $package
     * @param string $installPath
     * @param bool $isMainPackage
     *
     * @throws \Exception
     */
    public function addPackage(PackageInterface $package, $installPath, $isMainPackage)
    {
        if ($this->isPsr4) {
            // PSR-4 needs some extra validation.
            $autoload = $package->getAutoload();
            if (!isset($autoload['psr-4'])) {
                // Skip this package, as it has no PSR-4 directories.
                return;
            }
            if (!is_array($autoload['psr-4'])) {
                // So far we silently ignore this case, and simply skip the package.
                return;
            }
            if (null !== $package->getTargetDir()) {
                $name = $package->getName();
                $package->getTargetDir();
                throw new \InvalidArgumentException("PSR-4 autoloading is incompatible with the target-dir property, remove the target-dir in package '$name'.");
            }
            foreach ($autoload['psr-4'] as $namespace => $dirs) {
                if ($namespace !== '' && '\\' !== substr($namespace, -1)) {
                    $name = $package->getName();
                    throw new \InvalidArgumentException("The PSR-4 namespace '$namespace' in package '$name' does not end with a namespace separator. Use '$namespace\\' instead.");
                }
            }
        }

        parent::addPackage($package, $installPath, $isMainPackage);
    }

    /**
     * @param ClassLoader $classLoader
     * @param bool $prependAutoloader
     */
    public function initClassLoader(ClassLoader $classLoader, $prependAutoloader)
    {
        krsort($this->map);

        foreach ($this->map as $namespace => $paths) {
            if ($this->isPsr4) {
                $classLoader->addPsr4($namespace, $paths);
            }
            else {
                $classLoader->add($namespace, $paths);
            }
        }
    }

    /**
     * @param BuildInterface $build
     * @param \Composer\Autoload\BuildDataInterface $buildData
     * @param PathCodeBuilderInterface $buildUtil
     */
    public function generate(BuildInterface $build, BuildDataInterface $buildData, PathCodeBuilderInterface $buildUtil)
    {
        krsort($this->map);

        // Generate the autoload_*.php file.
        $phpRows = '';
        foreach ($this->map as $namespace => $paths) {
            $exportedPaths = array();
            foreach ($paths as $path) {
                $exportedPaths[] = $buildUtil->getPathCode($path, $buildData);
            }
            $exportedPrefix = var_export($namespace, true);
            $phpRows .= "    $exportedPrefix => ";
            $phpRows .= "array(".implode(', ', $exportedPaths)."),\n";
        }
        $build->addArraySourceFile($this->filename, $phpRows);

        $method = $this->isPsr4 ? 'setPsr4' : 'set';
        $build->addPhpSnippet(<<<EOT
        \$map = require __DIR__ . '/$this->filename';
        foreach (\$map as \$namespace => \$path) {
            \$loader->$method(\$namespace, \$path);
        }


EOT
        );
    }

    /**
     * Implements ClassmapProviderInterface::buildClassMap()
     *
     * @param BuildDataInterface $buildData
     * @return string[]
     *   Class map.
     */
    public function buildClassMap(BuildDataInterface $buildData = NULL)
    {
        $filesystem = $buildData->getFilesystem();
        $classMap = array();
        foreach ($this->map as $namespace => $paths) {
            foreach ($paths as $dir) {
                if (!$filesystem->isAbsolutePath($dir)) {
                    $dir = $buildData->getBasePath() . '/' . $dir;
                }
                $dir = $buildData->getFilesystem()->normalizePath($dir);
                if (!is_dir($dir)) {
                    continue;
                }
                $whitelist = sprintf(
                  '{%s/%s.+(?<!(?<!/)Test\.php)$}',
                  preg_quote($dir),
                  ($this->isPsr4 || strpos($namespace, '_') === false)
                    ? preg_quote(strtr($namespace, '\\', '/'))
                    : ''
                );
                foreach (ClassMapGenerator::createMap($dir, $whitelist) as $class => $path) {
                    if ('' === $namespace || 0 === strpos($class, $namespace)) {
                        if (!isset($classMap[$class])) {
                            $classMap[$class] = $path;
                        }
                    }
                }
            }
        }
        return $classMap;
    }
}
