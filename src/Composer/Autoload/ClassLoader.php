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

/**
 * ClassLoader implements an PSR-0 class loader
 *
 * See https://github.com/php-fig/fig-standards/blob/master/accepted/PSR-0.md
 *
 *     $loader = new ComposerClassLoader();
 *
 *     // register classes with namespaces
 *     $loader->add('Symfony\Component' => __DIR__.'/component');
 *     $loader->add('Symfony'           => __DIR__.'/framework');
 *
 *     // activate the autoloader
 *     $loader->register();
 *
 * In this example, if you try to use a class in the Symfony\Component
 * namespace or one of its children (Symfony\Component\Console for instance),
 * the autoloader will first look for the class under the component/
 * directory, and it will then fallback to the framework/ directory if not
 * found before giving up.
 *
 * This class is loosely based on the Symfony UniversalClassLoader.
 *
 * @author Fabien Potencier <fabien@symfony.com>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class ClassLoader
{
    private $prefixes = array();
    private $fallbackDir;

    public function getPrefixes()
    {
        return $this->prefixes;
    }

    /**
     * Registers a set of classes
     *
     * @param string       $prefix  The classes prefix
     * @param array|string $paths   The location(s) of the classes
     */
    public function add($prefix, $paths)
    {
        if (!$prefix) {
            $this->fallbackDir = is_array($paths) ? $paths[0] : $paths;
            return;
        }
        if (isset($this->prefixes[$prefix])) {
            $this->prefixes[$prefix] = array_merge(
                $this->prefixes[$prefix],
                (array) $paths
            );
        } else {
            $this->prefixes[$prefix] = (array) $paths;
        }
    }

    /**
     * Registers this instance as an autoloader.
     *
     * @param Boolean $prepend Whether to prepend the autoloader or not
     */
    public function register($prepend = false)
    {
        spl_autoload_register(array($this, 'loadClass'), true, $prepend);
    }

    /**
     * Loads the given class or interface.
     *
     * @param string $class The name of the class
     */
    public function loadClass($class)
    {
        if ($file = $this->findFile($class)) {
            require $file;
        }
    }

    /**
     * Finds the path to the file where the class is defined.
     *
     * @param string $class The name of the class
     *
     * @return string|null The path, if found
     */
    public function findFile($class)
    {
        if ('\\' == $class[0]) {
            $class = substr($class, 1);
        }

        if (false !== $pos = strrpos($class, '\\')) {
            // namespaced class name
            $classPath = DIRECTORY_SEPARATOR . str_replace('\\', DIRECTORY_SEPARATOR, substr($class, 0, $pos));
            $className = substr($class, $pos + 1);
        } else {
            // PEAR-like class name
            $classPath = null;
            $className = $class;
        }

        $classPath .= DIRECTORY_SEPARATOR . str_replace('_', DIRECTORY_SEPARATOR, $className) . '.php';

        foreach ($this->prefixes as $prefix => $dirs) {
            foreach ($dirs as $dir) {
                if (0 === strpos($class, $prefix)) {
                    $file = $dir . $classPath;
                    if (file_exists($file)) {
                        return $file;
                    }
                }
            }
        }

        if ($this->fallbackDir) {
            $file = $this->fallbackDir . $classPath;
            if (file_exists($file)) {
                return $file;
            }
        }
    }
}