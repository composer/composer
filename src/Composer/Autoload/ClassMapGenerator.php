<?php

/*
 * This file is copied from the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license MIT
 */

namespace Composer\Autoload;

/**
 * ClassMapGenerator
 *
 * @author Gyula Sallai <salla016@gmail.com>
 */
class ClassMapGenerator
{
    /**
     * Generate a class map file
     *
     * @param Traversable $dirs Directories or a single path to search in
     * @param string      $file The name of the class map file
     */
    public static function dump($dirs, $file)
    {
        $maps = array();

        foreach ($dirs as $dir) {
            $maps = array_merge($maps, static::createMap($dir));
        }

        file_put_contents($file, sprintf('<?php return %s;', var_export($maps, true)));
    }

    /**
     * Iterate over all files in the given directory searching for classes
     *
     * @param Iterator|string $dir       The directory to search in or an iterator
     * @param string          $whitelist Regex that matches against the file path
     *
     * @return array A class map array
     */
    public static function createMap($dir, $whitelist = null)
    {
        if (is_string($dir)) {
            if (is_file($dir)) {
                $dir = array(new \SplFileInfo($dir));
            } else {
                $dir = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
            }
        }

        $map = array();

        foreach ($dir as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $path = $file->getRealPath();

            if (pathinfo($path, PATHINFO_EXTENSION) !== 'php') {
                continue;
            }

            if ($whitelist && !preg_match($whitelist, strtr($path, '\\', '/'))) {
                continue;
            }

            $classes = self::findClasses($path);

            foreach ($classes as $class) {
                $map[$class] = $path;
            }

        }

        return $map;
    }

    /**
     * Extract the classes in the given file
     *
     * @param string $path The file to check
     *
     * @return array The found classes
     */
    private static function findClasses($path)
    {
        $contents = php_strip_whitespace($path);

        try {
            if (!preg_match('{\b(?:class|interface|trait)\b}i', $contents)) {
                return array();
            }

            // strip heredocs/nowdocs
            $contents = preg_replace('{<<<\'?(\w+)\'?(?:\r\n|\n|\r)(?:.*?)(?:\r\n|\n|\r)\\1(?=\r\n|\n|\r|;)}s', 'null', $contents);
            // strip strings
            $contents = preg_replace('{"[^"\\\\]*(\\\\.[^"\\\\]*)*"|\'[^\'\\\\]*(\\\\.[^\'\\\\]*)*\'}', 'null', $contents);

            preg_match_all('{(?:\b(?<![\$:>])(?<type>class|interface|trait)\s+(?<name>\S+)|\b(?<![\$:>])(?<ns>namespace)\s+(?<nsname>[^\s;{}\\\\]+(?:\s*\\\\\s*[^\s;{}\\\\]+)*))}i', $contents, $matches);
            $classes = array();

            $namespace = '';

            for ($i = 0, $len = count($matches['type']); $i < $len; $i++) {
                $name = $matches['name'][$i];

                if (!empty($matches['ns'][$i])) {
                    $namespace = str_replace(array(' ', "\t", "\r", "\n"), '', $matches['nsname'][$i]) . '\\';
                } else {
                    $classes[] = ltrim($namespace . $matches['name'][$i], '\\');
                }
            }

            return $classes;
        } catch (\Exception $e) {
            throw new \RuntimeException('Could not scan for classes inside '.$path.": \n".$e->getMessage(), 0, $e);
        }
    }
}
