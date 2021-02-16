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

/*
 * This file is copied from the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 */

namespace Composer\Autoload;

use Symfony\Component\Finder\Finder;
use Composer\IO\IOInterface;
use Composer\Util\Filesystem;

/**
 * ClassMapGenerator
 *
 * @author Gyula Sallai <salla016@gmail.com>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class ClassMapGenerator
{
    /**
     * Generate a class map file
     *
     * @param \Traversable $dirs Directories or a single path to search in
     * @param string       $file The name of the class map file
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
     * @param \Iterator|string $path         The path to search in or an iterator
     * @param string           $excluded     Regex that matches file paths to be excluded from the classmap
     * @param IOInterface      $io           IO object
     * @param string           $namespace    Optional namespace prefix to filter by
     * @param string           $autoloadType psr-0|psr-4 Optional autoload standard to use mapping rules
     *
     * @throws \RuntimeException When the path is neither an existing file nor directory
     * @return array             A class map array
     */
    public static function createMap($path, $excluded = null, IOInterface $io = null, $namespace = null, $autoloadType = null, &$scannedFiles = array())
    {
        $basePath = $path;
        if (is_string($path)) {
            if (is_file($path)) {
                $path = array(new \SplFileInfo($path));
            } elseif (is_dir($path) || strpos($path, '*') !== false) {
                $path = Finder::create()->files()->followLinks()->name('/\.(php|inc|hh)$/')->in($path);
            } else {
                throw new \RuntimeException(
                    'Could not scan for classes inside "'.$path.
                    '" which does not appear to be a file nor a folder'
                );
            }
        } elseif (null !== $autoloadType) {
            throw new \RuntimeException('Path must be a string when specifying an autoload type');
        }

        $map = array();
        $filesystem = new Filesystem();
        $cwd = realpath(getcwd());

        foreach ($path as $file) {
            $filePath = $file->getPathname();
            if (!in_array(pathinfo($filePath, PATHINFO_EXTENSION), array('php', 'inc', 'hh'))) {
                continue;
            }

            if (!$filesystem->isAbsolutePath($filePath)) {
                $filePath = $cwd . '/' . $filePath;
                $filePath = $filesystem->normalizePath($filePath);
            } else {
                $filePath = preg_replace('{[\\\\/]{2,}}', '/', $filePath);
            }

            $realPath = realpath($filePath);

            // if a list of scanned files is given, avoid scanning twice the same file to save cycles and avoid generating warnings
            // in case a PSR-0/4 declaration follows another more specific one, or a classmap declaration, which covered this file already
            if (isset($scannedFiles[$realPath])) {
                continue;
            }

            // check the realpath of the file against the excluded paths as the path might be a symlink and the excluded path is realpath'd so symlink are resolved
            if ($excluded && preg_match($excluded, strtr($realPath, '\\', '/'))) {
                continue;
            }
            // check non-realpath of file for directories symlink in project dir
            if ($excluded && preg_match($excluded, strtr($filePath, '\\', '/'))) {
                continue;
            }

            $classes = self::findClasses($filePath);
            if (null !== $autoloadType) {
                $classes = self::filterByNamespace($classes, $filePath, $namespace, $autoloadType, $basePath, $io);

                // if no valid class was found in the file then we do not mark it as scanned as it might still be matched by another rule later
                if ($classes) {
                    $scannedFiles[$realPath] = true;
                }
            } else {
                // classmap autoload rules always collect all classes so for these we definitely do not want to scan again
                $scannedFiles[$realPath] = true;
            }

            foreach ($classes as $class) {
                // skip classes not within the given namespace prefix
                if (null === $autoloadType && null !== $namespace && '' !== $namespace && 0 !== strpos($class, $namespace)) {
                    continue;
                }

                if (!isset($map[$class])) {
                    $map[$class] = $filePath;
                } elseif ($io && $map[$class] !== $filePath && !preg_match('{/(test|fixture|example|stub)s?/}i', strtr($map[$class].' '.$filePath, '\\', '/'))) {
                    $io->writeError(
                        '<warning>Warning: Ambiguous class resolution, "'.$class.'"'.
                        ' was found in both "'.$map[$class].'" and "'.$filePath.'", the first will be used.</warning>'
                    );
                }
            }
        }

        return $map;
    }

    /**
     * Remove classes which could not have been loaded by namespace autoloaders
     *
     * @param  array       $classes       found classes in given file
     * @param  string      $filePath      current file
     * @param  string      $baseNamespace prefix of given autoload mapping
     * @param  string      $namespaceType psr-0|psr-4
     * @param  string      $basePath      root directory of given autoload mapping
     * @param  IOInterface $io            IO object
     * @return array       valid classes
     */
    private static function filterByNamespace($classes, $filePath, $baseNamespace, $namespaceType, $basePath, $io)
    {
        $validClasses = array();
        $rejectedClasses = array();

        $realSubPath = substr($filePath, strlen($basePath) + 1);
        $realSubPath = substr($realSubPath, 0, strrpos($realSubPath, '.'));

        foreach ($classes as $class) {
            // silently skip if ns doesn't have common root
            if ('' !== $baseNamespace && 0 !== strpos($class, $baseNamespace)) {
                continue;
            }
            // transform class name to file path and validate
            if ('psr-0' === $namespaceType) {
                $namespaceLength = strrpos($class, '\\');
                if (false !== $namespaceLength) {
                    $namespace = substr($class, 0, $namespaceLength + 1);
                    $className = substr($class, $namespaceLength + 1);
                    $subPath = str_replace('\\', DIRECTORY_SEPARATOR, $namespace)
                        . str_replace('_', DIRECTORY_SEPARATOR, $className);
                } else {
                    $subPath = str_replace('_', DIRECTORY_SEPARATOR, $class);
                }
            } elseif ('psr-4' === $namespaceType) {
                $subNamespace = ('' !== $baseNamespace) ? substr($class, strlen($baseNamespace)) : $class;
                $subPath = str_replace('\\', DIRECTORY_SEPARATOR, $subNamespace);
            } else {
                throw new \RuntimeException("namespaceType must be psr-0 or psr-4, $namespaceType given");
            }
            if ($subPath === $realSubPath) {
                $validClasses[] = $class;
            } else {
                $rejectedClasses[] = $class;
            }
        }
        // warn only if no valid classes, else silently skip invalid
        if (empty($validClasses)) {
            foreach ($rejectedClasses as $class) {
                if ($io) {
                    $io->writeError("<warning>Class $class located in ".preg_replace('{^'.preg_quote(getcwd()).'}', '.', $filePath, 1)." does not comply with $namespaceType autoloading standard. Skipping.</warning>");
                }
            }

            return array();
        }

        return $validClasses;
    }

    /**
     * Extract the classes in the given file
     *
     * @param  string            $path The file to check
     * @throws \RuntimeException
     * @return array             The found classes
     */
    private static function findClasses($path)
    {
        $extraTypes = PHP_VERSION_ID < 50400 ? '' : '|trait';
        if (defined('HHVM_VERSION') && version_compare(HHVM_VERSION, '3.3', '>=')) {
            $extraTypes .= '|enum';
        }

        // Use @ here instead of Silencer to actively suppress 'unhelpful' output
        // @link https://github.com/composer/composer/pull/4886
        $contents = @php_strip_whitespace($path);
        if (!$contents) {
            if (!file_exists($path)) {
                $message = 'File at "%s" does not exist, check your classmap definitions';
            } elseif (!is_readable($path)) {
                $message = 'File at "%s" is not readable, check its permissions';
            } elseif ('' === trim(file_get_contents($path))) {
                // The input file was really empty and thus contains no classes
                return array();
            } else {
                $message = 'File at "%s" could not be parsed as PHP, it may be binary or corrupted';
            }
            $error = error_get_last();
            if (isset($error['message'])) {
                $message .= PHP_EOL . 'The following message may be helpful:' . PHP_EOL . $error['message'];
            }
            throw new \RuntimeException(sprintf($message, $path));
        }

        // return early if there is no chance of matching anything in this file
        if (!preg_match('{\b(?:class|interface'.$extraTypes.')\s}i', $contents)) {
            return array();
        }

        // strip heredocs/nowdocs
        $contents = preg_replace('{<<<[ \t]*([\'"]?)(\w+)\\1(?:\r\n|\n|\r)(?:.*?)(?:\r\n|\n|\r)(?:\s*)\\2(?=\s+|[;,.)])}s', 'null', $contents);
        // strip strings
        $contents = preg_replace('{"[^"\\\\]*+(\\\\.[^"\\\\]*+)*+"|\'[^\'\\\\]*+(\\\\.[^\'\\\\]*+)*+\'}s', 'null', $contents);
        // strip leading non-php code if needed
        if (strpos($contents, '<?') !== 0) {
            $contents = preg_replace('{^.+?<\?}s', '<?', $contents, 1, $replacements);
            if ($replacements === 0) {
                return array();
            }
        }
        // strip non-php blocks in the file
        $contents = preg_replace('{\?>(?:[^<]++|<(?!\?))*+<\?}s', '?><?', $contents);
        // strip trailing non-php code if needed
        $pos = strrpos($contents, '?>');
        if (false !== $pos && false === strpos(substr($contents, $pos), '<?')) {
            $contents = substr($contents, 0, $pos);
        }
        // strip comments if short open tags are in the file
        if (preg_match('{(<\?)(?!(php|hh))}i', $contents)) {
            $contents = preg_replace('{//.* | /\*(?:[^*]++|\*(?!/))*\*/}x', '', $contents);
        }

        preg_match_all('{
            (?:
                 \b(?<![\$:>])(?P<type>class|interface'.$extraTypes.') \s++ (?P<name>[a-zA-Z_\x7f-\xff:][a-zA-Z0-9_\x7f-\xff:\-]*+)
               | \b(?<![\$:>])(?P<ns>namespace) (?P<nsname>\s++[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*+(?:\s*+\\\\\s*+[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*+)*+)? \s*+ [\{;]
            )
        }ix', $contents, $matches);

        $classes = array();
        $namespace = '';

        for ($i = 0, $len = count($matches['type']); $i < $len; $i++) {
            if (!empty($matches['ns'][$i])) {
                $namespace = str_replace(array(' ', "\t", "\r", "\n"), '', $matches['nsname'][$i]) . '\\';
            } else {
                $name = $matches['name'][$i];
                // skip anon classes extending/implementing
                if ($name === 'extends' || $name === 'implements') {
                    continue;
                }
                if ($name[0] === ':') {
                    // This is an XHP class, https://github.com/facebook/xhp
                    $name = 'xhp'.substr(str_replace(array('-', ':'), array('_', '__'), $name), 1);
                } elseif ($matches['type'][$i] === 'enum') {
                    // In Hack, something like:
                    //   enum Foo: int { HERP = '123'; }
                    // The regex above captures the colon, which isn't part of
                    // the class name.
                    $name = rtrim($name, ':');
                }
                $classes[] = ltrim($namespace . $name, '\\');
            }
        }

        return $classes;
    }
}
