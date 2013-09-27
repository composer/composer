<?php

namespace Composer\Autoload;

use Composer\Config;
use Composer\Package\PackageInterface;
use Composer\Util\Filesystem;

class AutoloadGeneratorHelper
{
    /**
     * @var \Composer\Util\Filesystem
     */
    private $filesystem;

    /**
     * @var string
     *   The project root path.
     */
    private $basePath;

    /**
     * @var string
     *   The vendor path, typically (project root)/vendor.
     */
    private $vendorPath;

    /**
     * @var string
     *   The target path, typically (project root)/vendor/composer.
     */
    private $targetPath;

    /**
     * @var string
     *   PHP code to obtain the vendor dir from within a generated file in the target dir.
     *   Note: This may break in PHP 5.2 because it may contain __DIR__.
     */
    private $vendorPathCode;

    /**
     * @var string
     *   PHP code to obtain the vendor dir from within a generated file in the target dir,
     *   with __DIR__ being replaced with dirname(__FILE__) for PHP 5.2 compatibility.
     */
    private $vendorPathCode52;

    /**
     * @var string
     *   PHP code to obtain the application base directory from within a generated file in the target dir.
     */
    private $appBaseDirCode;

    /**
     * @var string
     *   PHP code to obtain the target dir from within a generated file in the vendor dir.
     */
    private $vendorPathToTargetDirCode;

    /**
     * @param string $vendorDir
     *   The vendor dir, typically (project root)/vendor
     * @param string $targetDir
     *   The target dir, relative to the vendor dir.
     *   E.g. if $targetDir is 'composer', and $vendorDir is (project root)/vendor,
     *   then the absolute target dir is (project root)/vendor/composer.
     */
    public function __construct($vendorDir, $targetDir)
    {
        $this->filesystem = new Filesystem();
        $this->filesystem->ensureDirectoryExists($vendorDir);
        $this->basePath = $this->filesystem->normalizePath(getcwd());
        $this->vendorPath = $this->filesystem->normalizePath(realpath($vendorDir));
        $this->targetPath = $this->vendorPath.'/'.$targetDir;
        $this->filesystem->ensureDirectoryExists($this->targetPath);

        $this->vendorPathCode = $this->filesystem->findShortestPathCode(realpath($this->targetPath), $this->vendorPath, true);
        $this->vendorPathCode52 = str_replace('__DIR__', 'dirname(__FILE__)', $this->vendorPathCode);
        $this->vendorPathToTargetDirCode = $this->filesystem->findShortestPathCode($this->vendorPath, realpath($this->targetPath), true);

        $appBaseDirCode = $this->filesystem->findShortestPathCode($this->vendorPath, $this->basePath, true);
        $this->appBaseDirCode = str_replace('__DIR__', '$vendorDir', $appBaseDirCode);
    }

    /**
     * @return string
     *   Path of the vendor directory.
     */
    public function getVendorPath()
    {
        return $this->vendorPath;
    }

    /**
     * Generates the autoload_namespaces.php, typically in (project root)/vendor/composer/autoload_namespaces.php.
     * Returns the PHP code snippet for AutoloaderInit::getLoader() that will register these PSR-0 directories to the
     * class loader.
     *
     * @param array $psr0
     *   Array of PSR-0 namespaces and directories, as collected from various composer.json files.
     * @return string
     *   PHP snippet for AutoloaderInit::getLoader().
     */
    public function dumpNamespacesFile($psr0)
    {
        $namespacesCode = '';
        foreach ($psr0 as $namespace => $paths) {
            $exportedPaths = array();
            foreach ($paths as $path) {
                $exportedPaths[] = $this->getPathCode($path);
            }
            $exportedPrefix = var_export($namespace, true);
            $namespacesCode .= "    $exportedPrefix => ";
            $namespacesCode .= "array(".implode(', ', $exportedPaths)."),\n";
        }

        $this->dumpArrayFile('autoload_namespaces.php', $namespacesCode);

        return <<<'PSR0'

        $map = require __DIR__ . '/autoload_namespaces.php';
        foreach ($map as $namespace => $path) {
            $loader->set($namespace, $path);
        }

PSR0;
    }

    /**
     * @param array $psr0
     *   Array of PSR-0 namespaces and directories, as collected from various composer.json files.
     * @return array
     *   The generated class map.
     */
    public function buildClassMapFromPsr0Scan(array $psr0)
    {
        // flatten array
        $classMap = array();
        foreach ($psr0 as $namespace => $paths) {
            foreach ($paths as $dir) {
                $dir = $this->filesystem->normalizePath($this->filesystem->isAbsolutePath($dir) ? $dir : $this->basePath.'/'.$dir);
                if (!is_dir($dir)) {
                    continue;
                }
                $whitelist = sprintf(
                    '{%s/%s.+(?<!(?<!/)Test\.php)$}',
                    preg_quote($dir),
                    strpos($namespace, '_') === false ? preg_quote(strtr($namespace, '\\', '/')) : ''
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

    /**
     * Generates the autoload_classmap.php, typically in (project root)/vendor/composer/autoload_classmap.php
     * Returns the PHP code snippet for AutoloaderInit::getLoader() that will register the class map to the class loader.
     *
     * @param array $classMap
     *   Class map generated from directory scans and information in composer.json.
     * @return string
     *   PHP snippet for AutoloaderInit::getLoader().
     */
    public function dumpClassmapFile(array $classMap)
    {
        $classmapCode = '';
        foreach ($classMap as $class => $path) {
            $pathCode = $this->getPathCode($path);
            $classmapCode .= '    '.var_export($class, true).' => '.$pathCode.",\n";
        }

        $this->dumpArrayFile('autoload_classmap.php', $classmapCode);

        return <<<'CLASSMAP'

        $classMap = require __DIR__ . '/autoload_classmap.php';
        if ($classMap) {
            $loader->addClassMap($classMap);
        }

CLASSMAP;
    }

    /**
     * Generates the autoload_classmap.php, typically in (project root)/vendor/composer/autoload_classmap.php
     * Returns the PHP code snippet for AutoloaderInit::getLoader() that will register the class map to the class loader.
     *
     * @param array $packageMap
     * @return string
     *   PHP snippet for AutoloaderInit::getLoader().
     */
    public function dumpIncludePathsFile(array $packageMap)
    {
        $includePaths = array();

        foreach ($packageMap as $item) {
            /**
             * @var PackageInterface $package
             */
            list($package, $installPath) = $item;

            if (null !== $package->getTargetDir() && strlen($package->getTargetDir()) > 0) {
                $installPath = substr($installPath, 0, -strlen('/'.$package->getTargetDir()));
            }

            foreach ($package->getIncludePaths() as $includePath) {
                $includePath = trim($includePath, '/');
                $includePaths[] = empty($installPath) ? $includePath : $installPath.'/'.$includePath;
            }
        }

        if (!$includePaths) {
            return '';
        }

        $includePathsCode = '';
        foreach ($includePaths as $path) {
            $includePathsCode .= "    " . $this->getPathCode($path) . ",\n";
        }

        $this->dumpArrayFile('include_paths.php', $includePathsCode);

        return <<<'INCLUDE_PATH'

        $includePaths = require __DIR__ . '/include_paths.php';
        array_push($includePaths, get_include_path());
        set_include_path(join(PATH_SEPARATOR, $includePaths));

INCLUDE_PATH;
    }

    /**
     * Generates the autoload_files.php, typically in (project root)/vendor/composer/autoload_files.php
     * Returns the PHP code snippet for AutoloaderInit::getLoader() that will include these files..
     *
     * @param array $files
     * @return string
     *   PHP snippet for AutoloaderInit::getLoader().
     */
    public function dumpIncludeFilesFile(array $files)
    {
        $filesCode = '';
        $files = new \RecursiveIteratorIterator(new \RecursiveArrayIterator($files));
        foreach ($files as $functionFile) {
            $filesCode .= '    '.$this->getPathCode($functionFile).",\n";
        }

        if (!$filesCode) {
            return '';
        }

        $this->dumpArrayFile('autoload_files.php', $filesCode, '');

        return <<<INCLUDE_FILES

        foreach (require __DIR__ . '/autoload_files.php' as \$file) {
            require \$file;
        }

INCLUDE_FILES;
    }

    /**
     * @param $suffix
     * @return bool
     */
    public function dumpAutoloadFile($suffix)
    {
        $php = <<<AUTOLOAD
<?php

// autoload.php generated by Composer

require_once $this->vendorPathToTargetDirCode . '/autoload_real.php';

return ComposerAutoloaderInit$suffix::getLoader();

AUTOLOAD;
        file_put_contents($this->vendorPath.'/autoload.php', $php);

        return true;
    }

    /**
     * @param $loaderSetupCode
     * @param $suffix
     * @param $targetDirLoader
     * @return bool
     */
    public function dumpAutoloadRealFile($loaderSetupCode, $suffix, $targetDirLoader)
    {
        // TODO the class ComposerAutoloaderInit should be revert to a closure
        // when APC has been fixed:
        // - https://github.com/composer/composer/issues/959
        // - https://bugs.php.net/bug.php?id=52144
        // - https://bugs.php.net/bug.php?id=61576
        // - https://bugs.php.net/bug.php?id=59298

        $file = <<<EOF
<?php

// autoload_real.php generated by Composer

class ComposerAutoloaderInit$suffix
{
    private static \$loader;

    public static function loadClassLoader(\$class)
    {
        if ('Composer\\Autoload\\ClassLoader' === \$class) {
            require __DIR__ . '/ClassLoader.php';
        }
    }

    public static function getLoader()
    {
        if (null !== self::\$loader) {
            return self::\$loader;
        }

        spl_autoload_register(array('ComposerAutoloaderInit$suffix', 'loadClassLoader'), true, true);
        self::\$loader = \$loader = new \\Composer\\Autoload\\ClassLoader();
        spl_autoload_unregister(array('ComposerAutoloaderInit$suffix', 'loadClassLoader'));

        \$vendorDir = $this->vendorPathCode;
        \$baseDir = $this->appBaseDirCode;
$loaderSetupCode
        return \$loader;
    }
$targetDirLoader}

EOF;
        file_put_contents($this->targetPath.'/autoload_real.php', $file);
        return true;
    }

    /**
     * @param $mainPackageTargetDir
     * @param $psr0
     * @return string
     */
    public function getTargetDirLoaderMethod($mainPackageTargetDir, $psr0)
    {
        $levels = count(explode('/', $this->filesystem->normalizePath($mainPackageTargetDir)));
        $prefixes = implode(', ', array_map(function ($prefix) {
            return var_export($prefix, true);
        }, array_keys($psr0)));

        $baseDirFromTargetDirCode = $this->filesystem->findShortestPathCode($this->targetPath, $this->basePath, true);

        return <<<EOF

    public static function autoload(\$class)
    {
        \$dir = $baseDirFromTargetDirCode . '/';
        \$prefixes = array($prefixes);
        foreach (\$prefixes as \$prefix) {
            if (0 !== strpos(\$class, \$prefix)) {
                continue;
            }
            \$path = \$dir . implode('/', array_slice(explode('\\\\', \$class), $levels)).'.php';
            if (!\$path = stream_resolve_include_path(\$path)) {
                return false;
            }
            require \$path;

            return true;
        }
    }

EOF;
    }

    /**
     * @param $path
     * @return string
     */
    protected function getPathCode($path)
    {
        if (!$this->filesystem->isAbsolutePath($path)) {
            $path = $this->basePath . '/' . $path;
        }
        $path = $this->filesystem->normalizePath($path);

        $baseDir = '';
        if (strpos($path, $this->vendorPath) === 0) {
            $path = substr($path, strlen($this->vendorPath));
            $baseDir = '$vendorDir . ';
        } else {
            $path = $this->filesystem->normalizePath($this->filesystem->findShortestPath($this->basePath, $path, true));
            if (!$this->filesystem->isAbsolutePath($path)) {
                $baseDir = '$baseDir . ';
                $path = '/' . $path;
            }
        }

        if (preg_match('/\.phar$/', $path)) {
            $baseDir = "'phar://' . " . $baseDir;
        }

        return $baseDir.var_export($path, true);
    }

    /**
     * Generates a PHP file returning an array.
     *
     * @param string $filename
     *   File name without the directory.
     * @param string $itemsCode
     *   PHP code that expresses the array values.
     * @param string $prepend
     *   String to append at the end of the file.
     *   This is a temporary solution to avoid tests from breaking, because some of the generated files are expected to
     *   have an additional linebreak in the end.
     */
    protected function dumpArrayFile($filename, $itemsCode, $prepend = "\n")
    {
        $php = <<<EOF
<?php

// $filename generated by Composer

\$vendorDir = $this->vendorPathCode52;
\$baseDir = $this->appBaseDirCode;

return array(
$itemsCode);
EOF;
        file_put_contents($this->targetPath . '/' . $filename, $php . $prepend);
    }
}