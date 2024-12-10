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
use Composer\EventDispatcher\EventDispatcher;
use Composer\Filter\PlatformRequirementFilter\IgnoreAllPlatformRequirementFilter;
use Composer\Filter\PlatformRequirementFilter\PlatformRequirementFilterFactory;
use Composer\Filter\PlatformRequirementFilter\PlatformRequirementFilterInterface;
use Composer\Installer\InstallationManager;
use Composer\IO\IOInterface;
use Composer\Package\AliasPackage;
use Composer\Package\PackageInterface;
use Composer\Package\RootPackageInterface;
use Composer\Pcre\Preg;
use Composer\Repository\InstalledRepositoryInterface;
use Composer\Semver\Constraint\Bound;
use Composer\Util\Filesystem;
use Composer\Util\Platform;
use Composer\Script\ScriptEvents;
use Composer\Util\PackageSorter;
use Composer\Json\JsonFile;

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
     * @var ?IOInterface
     */
    private $io;

    /**
     * @var ?bool
     */
    private $devMode = null;

    /**
     * @var bool
     */
    private $classMapAuthoritative = false;

    /**
     * @var bool
     */
    private $apcu = false;

    /**
     * @var string|null
     */
    private $apcuPrefix;

    /**
     * @var bool
     */
    private $runScripts = false;

    /**
     * @var PlatformRequirementFilterInterface
     */
    private $platformRequirementFilter;

    public function __construct(EventDispatcher $eventDispatcher, IOInterface $io = null)
    {
        $this->eventDispatcher = $eventDispatcher;
        $this->io = $io;

        $this->platformRequirementFilter = PlatformRequirementFilterFactory::ignoreNothing();
    }

    /**
     * @param bool $devMode
     * @return void
     */
    public function setDevMode($devMode = true)
    {
        $this->devMode = (bool) $devMode;
    }

    /**
     * Whether generated autoloader considers the class map authoritative.
     *
     * @param bool $classMapAuthoritative
     * @return void
     */
    public function setClassMapAuthoritative($classMapAuthoritative)
    {
        $this->classMapAuthoritative = (bool) $classMapAuthoritative;
    }

    /**
     * Whether generated autoloader considers APCu caching.
     *
     * @param bool        $apcu
     * @param string|null $apcuPrefix
     * @return void
     */
    public function setApcu($apcu, $apcuPrefix = null)
    {
        $this->apcu = (bool) $apcu;
        $this->apcuPrefix = $apcuPrefix !== null ? (string) $apcuPrefix : $apcuPrefix;
    }

    /**
     * Whether to run scripts or not
     *
     * @param bool $runScripts
     * @return void
     */
    public function setRunScripts($runScripts = true)
    {
        $this->runScripts = (bool) $runScripts;
    }

    /**
     * Whether platform requirements should be ignored.
     *
     * If this is set to true, the platform check file will not be generated
     * If this is set to false, the platform check file will be generated with all requirements
     * If this is set to string[], those packages will be ignored from the platform check file
     *
     * @param bool|string[] $ignorePlatformReqs
     * @return void
     *
     * @deprecated use setPlatformRequirementFilter instead
     */
    public function setIgnorePlatformRequirements($ignorePlatformReqs)
    {
        trigger_error('AutoloadGenerator::setIgnorePlatformRequirements is deprecated since Composer 2.2, use setPlatformRequirementFilter instead.', E_USER_DEPRECATED);

        $this->setPlatformRequirementFilter(PlatformRequirementFilterFactory::fromBoolOrList($ignorePlatformReqs));
    }

    /**
     * @return void
     */
    public function setPlatformRequirementFilter(PlatformRequirementFilterInterface $platformRequirementFilter)
    {
        $this->platformRequirementFilter = $platformRequirementFilter;
    }

    /**
     * @param string $targetDir
     * @param bool $scanPsrPackages
     * @param string|null $suffix
     * @return int
     * @throws \Seld\JsonLint\ParsingException
     * @throws \RuntimeException
     */
    public function dump(Config $config, InstalledRepositoryInterface $localRepo, RootPackageInterface $rootPackage, InstallationManager $installationManager, $targetDir, $scanPsrPackages = false, $suffix = null)
    {
        if ($this->classMapAuthoritative) {
            // Force scanPsrPackages when classmap is authoritative
            $scanPsrPackages = true;
        }

        // auto-set devMode based on whether dev dependencies are installed or not
        if (null === $this->devMode) {
            // we assume no-dev mode if no vendor dir is present or it is too old to contain dev information
            $this->devMode = false;

            $installedJson = new JsonFile($config->get('vendor-dir').'/composer/installed.json');
            if ($installedJson->exists()) {
                $installedJson = $installedJson->read();
                if (isset($installedJson['dev'])) {
                    $this->devMode = $installedJson['dev'];
                }
            }
        }

        if ($this->runScripts) {
            // set COMPOSER_DEV_MODE in case not set yet so it is available in the dump-autoload event listeners
            if (!isset($_SERVER['COMPOSER_DEV_MODE'])) {
                Platform::putEnv('COMPOSER_DEV_MODE', $this->devMode ? '1' : '0');
            }

            $this->eventDispatcher->dispatchScript(ScriptEvents::PRE_AUTOLOAD_DUMP, $this->devMode, array(), array(
                'optimize' => (bool) $scanPsrPackages,
            ));
        }

        $filesystem = new Filesystem();
        $filesystem->ensureDirectoryExists($config->get('vendor-dir'));
        // Do not remove double realpath() calls.
        // Fixes failing Windows realpath() implementation.
        // See https://bugs.php.net/bug.php?id=72738
        $basePath = $filesystem->normalizePath(realpath(realpath(getcwd())));
        $vendorPath = $filesystem->normalizePath(realpath(realpath($config->get('vendor-dir'))));
        $useGlobalIncludePath = (bool) $config->get('use-include-path');
        $prependAutoloader = $config->get('prepend-autoloader') === false ? 'false' : 'true';
        $targetDir = $vendorPath.'/'.$targetDir;
        $filesystem->ensureDirectoryExists($targetDir);

        $vendorPathCode = $filesystem->findShortestPathCode(realpath($targetDir), $vendorPath, true);
        $vendorPathCode52 = str_replace('__DIR__', 'dirname(__FILE__)', $vendorPathCode);
        $vendorPathToTargetDirCode = $filesystem->findShortestPathCode($vendorPath, realpath($targetDir), true);

        $appBaseDirCode = $filesystem->findShortestPathCode($vendorPath, $basePath, true);
        $appBaseDirCode = str_replace('__DIR__', '$vendorDir', $appBaseDirCode);

        $namespacesFile = <<<EOF
<?php

// autoload_namespaces.php @generated by Composer

\$vendorDir = $vendorPathCode52;
\$baseDir = $appBaseDirCode;

return array(

EOF;

        $psr4File = <<<EOF
<?php

// autoload_psr4.php @generated by Composer

\$vendorDir = $vendorPathCode52;
\$baseDir = $appBaseDirCode;

return array(

EOF;

        // Collect information from all packages.
        $devPackageNames = $localRepo->getDevPackageNames();
        $packageMap = $this->buildPackageMap($installationManager, $rootPackage, $localRepo->getCanonicalPackages());
        if ($this->devMode) {
            // if dev mode is enabled, then we do not filter any dev packages out so disable this entirely
            $filteredDevPackages = false;
        } else {
            // if the list of dev package names is available we use that straight, otherwise pass true which means use legacy algo to figure them out
            $filteredDevPackages = $devPackageNames ?: true;
        }
        $autoloads = $this->parseAutoloads($packageMap, $rootPackage, $filteredDevPackages);

        // Process the 'psr-0' base directories.
        foreach ($autoloads['psr-0'] as $namespace => $paths) {
            $exportedPaths = array();
            foreach ($paths as $path) {
                $exportedPaths[] = $this->getPathCode($filesystem, $basePath, $vendorPath, $path);
            }
            $exportedPrefix = var_export($namespace, true);
            $namespacesFile .= "    $exportedPrefix => ";
            $namespacesFile .= "array(".implode(', ', $exportedPaths)."),\n";
        }
        $namespacesFile .= ");\n";

        // Process the 'psr-4' base directories.
        foreach ($autoloads['psr-4'] as $namespace => $paths) {
            $exportedPaths = array();
            foreach ($paths as $path) {
                $exportedPaths[] = $this->getPathCode($filesystem, $basePath, $vendorPath, $path);
            }
            $exportedPrefix = var_export($namespace, true);
            $psr4File .= "    $exportedPrefix => ";
            $psr4File .= "array(".implode(', ', $exportedPaths)."),\n";
        }
        $psr4File .= ");\n";

        $classmapFile = <<<EOF
<?php

// autoload_classmap.php @generated by Composer

\$vendorDir = $vendorPathCode52;
\$baseDir = $appBaseDirCode;

return array(

EOF;

        // add custom psr-0 autoloading if the root package has a target dir
        $targetDirLoader = null;
        $mainAutoload = $rootPackage->getAutoload();
        if ($rootPackage->getTargetDir() && !empty($mainAutoload['psr-0'])) {
            $levels = substr_count($filesystem->normalizePath($rootPackage->getTargetDir()), '/') + 1;
            $prefixes = implode(', ', array_map(function ($prefix) {
                return var_export($prefix, true);
            }, array_keys($mainAutoload['psr-0'])));
            $baseDirFromTargetDirCode = $filesystem->findShortestPathCode($targetDir, $basePath, true);

            $targetDirLoader = <<<EOF

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

        $excluded = null;
        if (!empty($autoloads['exclude-from-classmap'])) {
            $excluded = $autoloads['exclude-from-classmap'];
        }

        $classMap = array();
        $ambiguousClasses = array();
        $scannedFiles = array();
        foreach ($autoloads['classmap'] as $dir) {
            $classMap = $this->addClassMapCode($filesystem, $basePath, $vendorPath, $dir, $excluded, null, null, $classMap, $ambiguousClasses, $scannedFiles);
        }

        if ($scanPsrPackages) {
            $namespacesToScan = array();

            // Scan the PSR-0/4 directories for class files, and add them to the class map
            foreach (array('psr-4', 'psr-0') as $psrType) {
                foreach ($autoloads[$psrType] as $namespace => $paths) {
                    $namespacesToScan[$namespace][] = array('paths' => $paths, 'type' => $psrType);
                }
            }

            krsort($namespacesToScan);

            foreach ($namespacesToScan as $namespace => $groups) {
                foreach ($groups as $group) {
                    foreach ($group['paths'] as $dir) {
                        $dir = $filesystem->normalizePath($filesystem->isAbsolutePath($dir) ? $dir : $basePath.'/'.$dir);
                        if (!is_dir($dir)) {
                            continue;
                        }

                        $classMap = $this->addClassMapCode($filesystem, $basePath, $vendorPath, $dir, $excluded, $namespace, $group['type'], $classMap, $ambiguousClasses, $scannedFiles);
                    }
                }
            }
        }

        foreach ($ambiguousClasses as $className => $ambiguousPaths) {
            $cleanPath = str_replace(array('$vendorDir . \'', '$baseDir . \'', "',\n"), array($vendorPath, $basePath, ''), $classMap[$className]);

            $this->io->writeError(
                '<warning>Warning: Ambiguous class resolution, "'.$className.'"'.
                ' was found '. (count($ambiguousPaths) + 1) .'x: in "'.$cleanPath.'" and "'. implode('", "', $ambiguousPaths) .'", the first will be used.</warning>'
            );
        }

        $classMap['Composer\\InstalledVersions'] = "\$vendorDir . '/composer/InstalledVersions.php',\n";
        ksort($classMap);
        foreach ($classMap as $class => $code) {
            $classmapFile .= '    '.var_export($class, true).' => '.$code;
        }
        $classmapFile .= ");\n";

        if ('' === $suffix) {
            $suffix = null;
        }
        if (null === $suffix) {
            $suffix = $config->get('autoloader-suffix');

            // carry over existing autoload.php's suffix if possible and none is configured
            if (null === $suffix && Filesystem::isReadable($vendorPath.'/autoload.php')) {
                $content = file_get_contents($vendorPath.'/autoload.php');
                if (Preg::isMatch('{ComposerAutoloaderInit([^:\s]+)::}', $content, $match)) {
                    $suffix = $match[1];
                }
            }

            // generate one if we still haven't got a suffix
            if (null === $suffix) {
                $suffix = md5(uniqid('', true));
            }
        }

        $filesystem->filePutContentsIfModified($targetDir.'/autoload_namespaces.php', $namespacesFile);
        $filesystem->filePutContentsIfModified($targetDir.'/autoload_psr4.php', $psr4File);
        $filesystem->filePutContentsIfModified($targetDir.'/autoload_classmap.php', $classmapFile);
        $includePathFilePath = $targetDir.'/include_paths.php';
        if ($includePathFileContents = $this->getIncludePathsFile($packageMap, $filesystem, $basePath, $vendorPath, $vendorPathCode52, $appBaseDirCode)) {
            $filesystem->filePutContentsIfModified($includePathFilePath, $includePathFileContents);
        } elseif (file_exists($includePathFilePath)) {
            unlink($includePathFilePath);
        }
        $includeFilesFilePath = $targetDir.'/autoload_files.php';
        if ($includeFilesFileContents = $this->getIncludeFilesFile($autoloads['files'], $filesystem, $basePath, $vendorPath, $vendorPathCode52, $appBaseDirCode)) {
            $filesystem->filePutContentsIfModified($includeFilesFilePath, $includeFilesFileContents);
        } elseif (file_exists($includeFilesFilePath)) {
            unlink($includeFilesFilePath);
        }
        $filesystem->filePutContentsIfModified($targetDir.'/autoload_static.php', $this->getStaticFile($suffix, $targetDir, $vendorPath, $basePath, $staticPhpVersion));
        $checkPlatform = $config->get('platform-check') && !($this->platformRequirementFilter instanceof IgnoreAllPlatformRequirementFilter);
        $platformCheckContent = null;
        if ($checkPlatform) {
            $platformCheckContent = $this->getPlatformCheck($packageMap, $config->get('platform-check'), $devPackageNames);
            if (null === $platformCheckContent) {
                $checkPlatform = false;
            }
        }
        if ($checkPlatform) {
            $filesystem->filePutContentsIfModified($targetDir.'/platform_check.php', $platformCheckContent);
        } elseif (file_exists($targetDir.'/platform_check.php')) {
            unlink($targetDir.'/platform_check.php');
        }
        $filesystem->filePutContentsIfModified($vendorPath.'/autoload.php', $this->getAutoloadFile($vendorPathToTargetDirCode, $suffix));
        $filesystem->filePutContentsIfModified($targetDir.'/autoload_real.php', $this->getAutoloadRealFile(true, (bool) $includePathFileContents, $targetDirLoader, (bool) $includeFilesFileContents, $vendorPathCode, $appBaseDirCode, $suffix, $useGlobalIncludePath, $prependAutoloader, $staticPhpVersion, $checkPlatform));

        $filesystem->safeCopy(__DIR__.'/ClassLoader.php', $targetDir.'/ClassLoader.php');
        $filesystem->safeCopy(__DIR__.'/../../../LICENSE', $targetDir.'/LICENSE');

        if ($this->runScripts) {
            $this->eventDispatcher->dispatchScript(ScriptEvents::POST_AUTOLOAD_DUMP, $this->devMode, array(), array(
                'optimize' => (bool) $scanPsrPackages,
            ));
        }

        return count($classMap);
    }

    /**
     * @param string $basePath
     * @param string $vendorPath
     * @param string $dir
     * @param ?array<int, string> $excluded
     * @param ?string $namespaceFilter
     * @param ?string $autoloadType
     * @param array<class-string, string> $classMap
     * @param array<class-string, array<int, string>> $ambiguousClasses
     * @param array<string, true> $scannedFiles
     * @return array<class-string, string>
     */
    private function addClassMapCode(Filesystem $filesystem, $basePath, $vendorPath, $dir, $excluded, $namespaceFilter, $autoloadType, array $classMap, array &$ambiguousClasses, array &$scannedFiles)
    {
        foreach ($this->generateClassMap($dir, $excluded, $namespaceFilter, $autoloadType, true, $scannedFiles) as $class => $path) {
            $pathCode = $this->getPathCode($filesystem, $basePath, $vendorPath, $path).",\n";
            if (!isset($classMap[$class])) {
                $classMap[$class] = $pathCode;
            } elseif ($this->io && $classMap[$class] !== $pathCode && !Preg::isMatch('{/(test|fixture|example|stub)s?/}i', strtr($classMap[$class].' '.$path, '\\', '/'))) {
                $ambiguousClasses[$class][] = $path;
            }
        }

        return $classMap;
    }

    /**
     * @param string $dir
     * @param ?array<int, string> $excluded
     * @param ?string $namespaceFilter
     * @param ?string $autoloadType
     * @param bool $showAmbiguousWarning
     * @param array<string, true> $scannedFiles
     * @return array<class-string, string>
     */
    private function generateClassMap($dir, $excluded, $namespaceFilter, $autoloadType, $showAmbiguousWarning, array &$scannedFiles)
    {
        if ($excluded) {
            // filter excluded patterns here to only use those matching $dir
            // exclude-from-classmap patterns are all realpath'd so we can only filter them if $dir exists so that realpath($dir) will work
            // if $dir does not exist, it should anyway not find anything there so no trouble
            if (file_exists($dir)) {
                // transform $dir in the same way that exclude-from-classmap patterns are transformed so we can match them against each other
                $dirMatch = preg_quote(strtr(realpath($dir), '\\', '/'));
                foreach ($excluded as $index => $pattern) {
                    // extract the constant string prefix of the pattern here, until we reach a non-escaped regex special character
                    $pattern = Preg::replace('{^(([^.+*?\[^\]$(){}=!<>|:\\\\#-]+|\\\\[.+*?\[^\]$(){}=!<>|:#-])*).*}', '$1', $pattern);
                    // if the pattern is not a subset or superset of $dir, it is unrelated and we skip it
                    if (0 !== strpos($pattern, $dirMatch) && 0 !== strpos($dirMatch, $pattern)) {
                        unset($excluded[$index]);
                    }
                }
            }

            $excluded = $excluded ? '{(' . implode('|', $excluded) . ')}' : null;
        }

        return ClassMapGenerator::createMap($dir, $excluded, $showAmbiguousWarning ? $this->io : null, $namespaceFilter, $autoloadType, $scannedFiles);
    }

    /**
     * @param InstallationManager $installationManager
     * @param PackageInterface[] $packages
     * @return array<int, array{0: PackageInterface, 1: string}>
     */
    public function buildPackageMap(InstallationManager $installationManager, PackageInterface $rootPackage, array $packages)
    {
        // build package => install path map
        $packageMap = array(array($rootPackage, ''));

        foreach ($packages as $package) {
            if ($package instanceof AliasPackage) {
                continue;
            }
            $this->validatePackage($package);

            $packageMap[] = array(
                $package,
                $installationManager->getInstallPath($package),
            );
        }

        return $packageMap;
    }

    /**
     * @return void
     * @throws \InvalidArgumentException Throws an exception, if the package has illegal settings.
     */
    protected function validatePackage(PackageInterface $package)
    {
        $autoload = $package->getAutoload();
        if (!empty($autoload['psr-4']) && null !== $package->getTargetDir()) {
            $name = $package->getName();
            $package->getTargetDir();
            throw new \InvalidArgumentException("PSR-4 autoloading is incompatible with the target-dir property, remove the target-dir in package '$name'.");
        }
        if (!empty($autoload['psr-4'])) {
            foreach ($autoload['psr-4'] as $namespace => $dirs) {
                if ($namespace !== '' && '\\' !== substr($namespace, -1)) {
                    throw new \InvalidArgumentException("psr-4 namespaces must end with a namespace separator, '$namespace' does not, use '$namespace\\'.");
                }
            }
        }
    }

    /**
     * Compiles an ordered list of namespace => path mappings
     *
     * @param array<int, array{0: PackageInterface, 1: string}> $packageMap array of array(package, installDir-relative-to-composer.json)
     * @param RootPackageInterface $rootPackage root package instance
     * @param bool|string[] $filteredDevPackages If an array, the list of packages that must be removed. If bool, whether to filter out require-dev packages
     * @return array
     * @phpstan-return array{
     *     'psr-0': array<string, array<string>>,
     *     'psr-4': array<string, array<string>>,
     *     'classmap': array<int, string>,
     *     'files': array<string, string>,
     *     'exclude-from-classmap': array<int, string>,
     * }
     */
    public function parseAutoloads(array $packageMap, PackageInterface $rootPackage, $filteredDevPackages = false)
    {
        $rootPackageMap = array_shift($packageMap);
        if (is_array($filteredDevPackages)) {
            $packageMap = array_filter($packageMap, function ($item) use ($filteredDevPackages) {
                return !in_array($item[0]->getName(), $filteredDevPackages, true);
            });
        } elseif ($filteredDevPackages) {
            $packageMap = $this->filterPackageMap($packageMap, $rootPackage);
        }
        $sortedPackageMap = $this->sortPackageMap($packageMap);
        $sortedPackageMap[] = $rootPackageMap;
        array_unshift($packageMap, $rootPackageMap);

        $psr0 = $this->parseAutoloadsType($packageMap, 'psr-0', $rootPackage);
        $psr4 = $this->parseAutoloadsType($packageMap, 'psr-4', $rootPackage);
        $classmap = $this->parseAutoloadsType(array_reverse($sortedPackageMap), 'classmap', $rootPackage);
        $files = $this->parseAutoloadsType($sortedPackageMap, 'files', $rootPackage);
        $exclude = $this->parseAutoloadsType($sortedPackageMap, 'exclude-from-classmap', $rootPackage);

        krsort($psr0);
        krsort($psr4);

        return array(
            'psr-0' => $psr0,
            'psr-4' => $psr4,
            'classmap' => $classmap,
            'files' => $files,
            'exclude-from-classmap' => $exclude,
        );
    }

    /**
     * Registers an autoloader based on an autoload-map returned by parseAutoloads
     *
     * @param array<string, mixed[]> $autoloads see parseAutoloads return value
     * @param ?string $vendorDir
     * @return ClassLoader
     */
    public function createLoader(array $autoloads, $vendorDir = null)
    {
        $loader = new ClassLoader($vendorDir);

        if (isset($autoloads['psr-0'])) {
            foreach ($autoloads['psr-0'] as $namespace => $path) {
                $loader->add($namespace, $path);
            }
        }

        if (isset($autoloads['psr-4'])) {
            foreach ($autoloads['psr-4'] as $namespace => $path) {
                $loader->addPsr4($namespace, $path);
            }
        }

        if (isset($autoloads['classmap'])) {
            $excluded = null;
            if (!empty($autoloads['exclude-from-classmap'])) {
                $excluded = $autoloads['exclude-from-classmap'];
            }

            $scannedFiles = array();
            foreach ($autoloads['classmap'] as $dir) {
                try {
                    $loader->addClassMap($this->generateClassMap($dir, $excluded, null, null, false, $scannedFiles));
                } catch (\RuntimeException $e) {
                    $this->io->writeError('<warning>'.$e->getMessage().'</warning>');
                }
            }
        }

        return $loader;
    }

    /**
     * @param array<int, array{0: PackageInterface, 1: string}> $packageMap
     * @param string $basePath
     * @param string $vendorPath
     * @param string $vendorPathCode
     * @param string $appBaseDirCode
     * @return ?string
     */
    protected function getIncludePathsFile(array $packageMap, Filesystem $filesystem, $basePath, $vendorPath, $vendorPathCode, $appBaseDirCode)
    {
        $includePaths = array();

        foreach ($packageMap as $item) {
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
            return null;
        }

        $includePathsCode = '';
        foreach ($includePaths as $path) {
            $includePathsCode .= "    " . $this->getPathCode($filesystem, $basePath, $vendorPath, $path) . ",\n";
        }

        return <<<EOF
<?php

// include_paths.php @generated by Composer

\$vendorDir = $vendorPathCode;
\$baseDir = $appBaseDirCode;

return array(
$includePathsCode);

EOF;
    }

    /**
     * @param array<string, string> $files
     * @param string $basePath
     * @param string $vendorPath
     * @param string $vendorPathCode
     * @param string $appBaseDirCode
     * @return ?string
     */
    protected function getIncludeFilesFile(array $files, Filesystem $filesystem, $basePath, $vendorPath, $vendorPathCode, $appBaseDirCode)
    {
        $filesCode = '';
        foreach ($files as $fileIdentifier => $functionFile) {
            $filesCode .= '    ' . var_export($fileIdentifier, true) . ' => '
                . $this->getPathCode($filesystem, $basePath, $vendorPath, $functionFile) . ",\n";
        }

        if (!$filesCode) {
            return null;
        }

        return <<<EOF
<?php

// autoload_files.php @generated by Composer

\$vendorDir = $vendorPathCode;
\$baseDir = $appBaseDirCode;

return array(
$filesCode);

EOF;
    }

    /**
     * @param string $basePath
     * @param string $vendorPath
     * @param string $path
     * @return string
     */
    protected function getPathCode(Filesystem $filesystem, $basePath, $vendorPath, $path)
    {
        if (!$filesystem->isAbsolutePath($path)) {
            $path = $basePath . '/' . $path;
        }
        $path = $filesystem->normalizePath($path);

        $baseDir = '';
        if (strpos($path.'/', $vendorPath.'/') === 0) {
            $path = substr($path, strlen($vendorPath));
            $baseDir = '$vendorDir';

            if ($path !== false) {
                $baseDir .= " . ";
            }
        } else {
            $path = $filesystem->normalizePath($filesystem->findShortestPath($basePath, $path, true));
            if (!$filesystem->isAbsolutePath($path)) {
                $baseDir = '$baseDir . ';
                $path = '/' . $path;
            }
        }

        if (strpos($path, '.phar') !== false) {
            $baseDir = "'phar://' . " . $baseDir;
        }

        return $baseDir . var_export($path, true);
    }

    /**
     * @param array<int, array{0: PackageInterface, 1: string}> $packageMap
     * @param bool $checkPlatform
     * @param string[] $devPackageNames
     * @return ?string
     */
    protected function getPlatformCheck(array $packageMap, $checkPlatform, array $devPackageNames)
    {
        $lowestPhpVersion = Bound::zero();
        $requiredExtensions = array();
        $extensionProviders = array();

        foreach ($packageMap as $item) {
            $package = $item[0];
            foreach (array_merge($package->getReplaces(), $package->getProvides()) as $link) {
                if (Preg::isMatch('{^ext-(.+)$}iD', $link->getTarget(), $match)) {
                    $extensionProviders[$match[1]][] = $link->getConstraint();
                }
            }
        }

        foreach ($packageMap as $item) {
            $package = $item[0];
            // skip dev dependencies platform requirements as platform-check really should only be a production safeguard
            if (in_array($package->getName(), $devPackageNames, true)) {
                continue;
            }

            foreach ($package->getRequires() as $link) {
                if ($this->platformRequirementFilter->isIgnored($link->getTarget())) {
                    continue;
                }

                if ('php' === $link->getTarget()) {
                    $constraint = $link->getConstraint();
                    if ($constraint->getLowerBound()->compareTo($lowestPhpVersion, '>')) {
                        $lowestPhpVersion = $constraint->getLowerBound();
                    }
                }

                if ($checkPlatform === true && Preg::isMatch('{^ext-(.+)$}iD', $link->getTarget(), $match)) {
                    // skip extension checks if they have a valid provider/replacer
                    if (isset($extensionProviders[$match[1]])) {
                        foreach ($extensionProviders[$match[1]] as $provided) {
                            if ($provided->matches($link->getConstraint())) {
                                continue 2;
                            }
                        }
                    }

                    if ($match[1] === 'zend-opcache') {
                        $match[1] = 'zend opcache';
                    }

                    $extension = var_export($match[1], true);
                    if ($match[1] === 'pcntl' || $match[1] === 'readline') {
                        $requiredExtensions[$extension] = "PHP_SAPI !== 'cli' || extension_loaded($extension) || \$missingExtensions[] = $extension;\n";
                    } else {
                        $requiredExtensions[$extension] = "extension_loaded($extension) || \$missingExtensions[] = $extension;\n";
                    }
                }
            }
        }

        ksort($requiredExtensions);

        $formatToPhpVersionId = function (Bound $bound) {
            if ($bound->isZero()) {
                return 0;
            }

            if ($bound->isPositiveInfinity()) {
                return 99999;
            }

            $version = str_replace('-', '.', $bound->getVersion());
            $chunks = array_map('intval', explode('.', $version));

            return $chunks[0] * 10000 + $chunks[1] * 100 + $chunks[2];
        };

        $formatToHumanReadable = function (Bound $bound) {
            if ($bound->isZero()) {
                return 0;
            }

            if ($bound->isPositiveInfinity()) {
                return 99999;
            }

            $version = str_replace('-', '.', $bound->getVersion());
            $chunks = explode('.', $version);
            $chunks = array_slice($chunks, 0, 3);

            return implode('.', $chunks);
        };

        $requiredPhp = '';
        $requiredPhpError = '';
        if (!$lowestPhpVersion->isZero()) {
            $operator = $lowestPhpVersion->isInclusive() ? '>=' : '>';
            $requiredPhp = 'PHP_VERSION_ID '.$operator.' '.$formatToPhpVersionId($lowestPhpVersion);
            $requiredPhpError = '"'.$operator.' '.$formatToHumanReadable($lowestPhpVersion).'"';
        }

        if ($requiredPhp) {
            $requiredPhp = <<<PHP_CHECK

if (!($requiredPhp)) {
    \$issues[] = 'Your Composer dependencies require a PHP version $requiredPhpError. You are running ' . PHP_VERSION . '.';
}

PHP_CHECK;
        }

        $requiredExtensions = implode('', $requiredExtensions);
        if ('' !== $requiredExtensions) {
            $requiredExtensions = <<<EXT_CHECKS

\$missingExtensions = array();
$requiredExtensions
if (\$missingExtensions) {
    \$issues[] = 'Your Composer dependencies require the following PHP extensions to be installed: ' . implode(', ', \$missingExtensions) . '.';
}

EXT_CHECKS;
        }

        if (!$requiredPhp && !$requiredExtensions) {
            return null;
        }

        return <<<PLATFORM_CHECK
<?php

// platform_check.php @generated by Composer

\$issues = array();
{$requiredPhp}{$requiredExtensions}
if (\$issues) {
    if (!headers_sent()) {
        header('HTTP/1.1 500 Internal Server Error');
    }
    if (!ini_get('display_errors')) {
        if (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') {
            fwrite(STDERR, 'Composer detected issues in your platform:' . PHP_EOL.PHP_EOL . implode(PHP_EOL, \$issues) . PHP_EOL.PHP_EOL);
        } elseif (!headers_sent()) {
            echo 'Composer detected issues in your platform:' . PHP_EOL.PHP_EOL . str_replace('You are running '.PHP_VERSION.'.', '', implode(PHP_EOL, \$issues)) . PHP_EOL.PHP_EOL;
        }
    }
    trigger_error(
        'Composer detected issues in your platform: ' . implode(' ', \$issues),
        E_USER_ERROR
    );
}

PLATFORM_CHECK;
    }

    /**
     * @param  string $vendorPathToTargetDirCode
     * @param  string $suffix
     * @return string
     */
    protected function getAutoloadFile($vendorPathToTargetDirCode, $suffix)
    {
        $lastChar = $vendorPathToTargetDirCode[strlen($vendorPathToTargetDirCode) - 1];
        if ("'" === $lastChar || '"' === $lastChar) {
            $vendorPathToTargetDirCode = substr($vendorPathToTargetDirCode, 0, -1).'/autoload_real.php'.$lastChar;
        } else {
            $vendorPathToTargetDirCode .= " . '/autoload_real.php'";
        }

        return <<<AUTOLOAD
<?php

// autoload.php @generated by Composer

require_once $vendorPathToTargetDirCode;

return ComposerAutoloaderInit$suffix::getLoader();

AUTOLOAD;
    }

    /**
     * @param bool $useClassMap
     * @param bool $useIncludePath
     * @param ?string $targetDirLoader
     * @param bool $useIncludeFiles
     * @param string $vendorPathCode unused in this method
     * @param string $appBaseDirCode unused in this method
     * @param string $suffix
     * @param bool $useGlobalIncludePath
     * @param string $prependAutoloader 'true'|'false'
     * @param string $staticPhpVersion
     * @param bool $checkPlatform
     * @return string
     */
    protected function getAutoloadRealFile($useClassMap, $useIncludePath, $targetDirLoader, $useIncludeFiles, $vendorPathCode, $appBaseDirCode, $suffix, $useGlobalIncludePath, $prependAutoloader, $staticPhpVersion, $checkPlatform)
    {
        $file = <<<HEADER
<?php

// autoload_real.php @generated by Composer

class ComposerAutoloaderInit$suffix
{
    private static \$loader;

    public static function loadClassLoader(\$class)
    {
        if ('Composer\\Autoload\\ClassLoader' === \$class) {
            require __DIR__ . '/ClassLoader.php';
        }
    }

    /**
     * @return \Composer\Autoload\ClassLoader
     */
    public static function getLoader()
    {
        if (null !== self::\$loader) {
            return self::\$loader;
        }


HEADER;

        if ($checkPlatform) {
            $file .= <<<'PLATFORM_CHECK'
        require __DIR__ . '/platform_check.php';


PLATFORM_CHECK;
        }

        $file .= <<<CLASSLOADER_INIT
        spl_autoload_register(array('ComposerAutoloaderInit$suffix', 'loadClassLoader'), true, $prependAutoloader);
        self::\$loader = \$loader = new \\Composer\\Autoload\\ClassLoader(\\dirname(\\dirname(__FILE__)));
        spl_autoload_unregister(array('ComposerAutoloaderInit$suffix', 'loadClassLoader'));


CLASSLOADER_INIT;

        if ($useIncludePath) {
            $file .= <<<'INCLUDE_PATH'
        $includePaths = require __DIR__ . '/include_paths.php';
        $includePaths[] = get_include_path();
        set_include_path(implode(PATH_SEPARATOR, $includePaths));


INCLUDE_PATH;
        }

        $file .= <<<STATIC_INIT
        \$useStaticLoader = PHP_VERSION_ID >= $staticPhpVersion && !defined('HHVM_VERSION') && (!function_exists('zend_loader_file_encoded') || !zend_loader_file_encoded());
        if (\$useStaticLoader) {
            require __DIR__ . '/autoload_static.php';

            call_user_func(\Composer\Autoload\ComposerStaticInit$suffix::getInitializer(\$loader));
        } else {

STATIC_INIT;

        if (!$this->classMapAuthoritative) {
            $file .= <<<'PSR04'
            $map = require __DIR__ . '/autoload_namespaces.php';
            foreach ($map as $namespace => $path) {
                $loader->set($namespace, $path);
            }

            $map = require __DIR__ . '/autoload_psr4.php';
            foreach ($map as $namespace => $path) {
                $loader->setPsr4($namespace, $path);
            }


PSR04;
        }

        if ($useClassMap) {
            $file .= <<<'CLASSMAP'
            $classMap = require __DIR__ . '/autoload_classmap.php';
            if ($classMap) {
                $loader->addClassMap($classMap);
            }

CLASSMAP;
        }

        $file .= "        }\n\n";

        if ($this->classMapAuthoritative) {
            $file .= <<<'CLASSMAPAUTHORITATIVE'
        $loader->setClassMapAuthoritative(true);

CLASSMAPAUTHORITATIVE;
        }

        if ($this->apcu) {
            $apcuPrefix = var_export(($this->apcuPrefix !== null ? $this->apcuPrefix : substr(base64_encode(md5(uniqid('', true), true)), 0, -3)), true);
            $file .= <<<APCU
        \$loader->setApcuPrefix($apcuPrefix);

APCU;
        }

        if ($useGlobalIncludePath) {
            $file .= <<<'INCLUDEPATH'
        $loader->setUseIncludePath(true);

INCLUDEPATH;
        }

        if ($targetDirLoader) {
            $file .= <<<REGISTER_TARGET_DIR_AUTOLOAD
        spl_autoload_register(array('ComposerAutoloaderInit$suffix', 'autoload'), true, true);


REGISTER_TARGET_DIR_AUTOLOAD;
        }

        $file .= <<<REGISTER_LOADER
        \$loader->register($prependAutoloader);


REGISTER_LOADER;

        if ($useIncludeFiles) {
            $file .= <<<INCLUDE_FILES
        if (\$useStaticLoader) {
            \$includeFiles = Composer\Autoload\ComposerStaticInit$suffix::\$files;
        } else {
            \$includeFiles = require __DIR__ . '/autoload_files.php';
        }
        foreach (\$includeFiles as \$fileIdentifier => \$file) {
            composerRequire$suffix(\$fileIdentifier, \$file);
        }


INCLUDE_FILES;
        }

        $file .= <<<METHOD_FOOTER
        return \$loader;
    }

METHOD_FOOTER;

        $file .= $targetDirLoader;

        if ($useIncludeFiles) {
            return $file . <<<FOOTER
}

/**
 * @param string \$fileIdentifier
 * @param string \$file
 * @return void
 */
function composerRequire$suffix(\$fileIdentifier, \$file)
{
    if (empty(\$GLOBALS['__composer_autoload_files'][\$fileIdentifier])) {
        \$GLOBALS['__composer_autoload_files'][\$fileIdentifier] = true;

        require \$file;
    }
}

FOOTER;
        }

        return $file . <<<FOOTER
}

FOOTER;
    }

    /**
     * @param string $suffix
     * @param string $targetDir
     * @param string $vendorPath input for findShortestPathCode
     * @param string $basePath input for findShortestPathCode
     * @param string $staticPhpVersion
     * @return string
     */
    protected function getStaticFile($suffix, $targetDir, $vendorPath, $basePath, &$staticPhpVersion)
    {
        $staticPhpVersion = 50600;

        $file = <<<HEADER
<?php

// autoload_static.php @generated by Composer

namespace Composer\Autoload;

class ComposerStaticInit$suffix
{

HEADER;

        $loader = new ClassLoader();

        $map = require $targetDir . '/autoload_namespaces.php';
        foreach ($map as $namespace => $path) {
            $loader->set($namespace, $path);
        }

        $map = require $targetDir . '/autoload_psr4.php';
        foreach ($map as $namespace => $path) {
            $loader->setPsr4($namespace, $path);
        }

        $classMap = require $targetDir . '/autoload_classmap.php';
        if ($classMap) {
            $loader->addClassMap($classMap);
        }

        $filesystem = new Filesystem();

        $vendorPathCode = ' => ' . $filesystem->findShortestPathCode(realpath($targetDir), $vendorPath, true, true) . " . '/";
        $vendorPharPathCode = ' => \'phar://\' . ' . $filesystem->findShortestPathCode(realpath($targetDir), $vendorPath, true, true) . " . '/";
        $appBaseDirCode = ' => ' . $filesystem->findShortestPathCode(realpath($targetDir), $basePath, true, true) . " . '/";
        $appBaseDirPharCode = ' => \'phar://\' . ' . $filesystem->findShortestPathCode(realpath($targetDir), $basePath, true, true) . " . '/";

        $absoluteVendorPathCode = ' => ' . substr(var_export(rtrim($vendorDir, '\\/') . '/', true), 0, -1);
        $absoluteVendorPharPathCode = ' => ' . substr(var_export(rtrim('phar://' . $vendorDir, '\\/') . '/', true), 0, -1);
        $absoluteAppBaseDirCode = ' => ' . substr(var_export(rtrim($baseDir, '\\/') . '/', true), 0, -1);
        $absoluteAppBaseDirPharCode = ' => ' . substr(var_export(rtrim('phar://' . $baseDir, '\\/') . '/', true), 0, -1);

        $initializer = '';
        $prefix = "\0Composer\Autoload\ClassLoader\0";
        $prefixLen = strlen($prefix);
        if (file_exists($targetDir . '/autoload_files.php')) {
            $maps = array('files' => require $targetDir . '/autoload_files.php');
        } else {
            $maps = array();
        }

        foreach ((array) $loader as $prop => $value) {
            if (is_array($value) && \count($value) !== 0 && 0 === strpos($prop, $prefix)) {
                $maps[substr($prop, $prefixLen)] = $value;
            }
        }

        foreach ($maps as $prop => $value) {
            if (count($value) > 32767) {
                // Static arrays are limited to 32767 values on PHP 5.6
                // See https://bugs.php.net/68057
                $staticPhpVersion = 70000;
            }
            $value = strtr(
                var_export($value, true),
                array(
                    $absoluteVendorPathCode => $vendorPathCode,
                    $absoluteVendorPharPathCode => $vendorPharPathCode,
                    $absoluteAppBaseDirCode => $appBaseDirCode,
                    $absoluteAppBaseDirPharCode => $appBaseDirPharCode,
                )
            );
            $value = ltrim(Preg::replace('/^ */m', '    $0$0', $value));

            $file .= sprintf("    public static $%s = %s;\n\n", $prop, $value);
            if ('files' !== $prop) {
                $initializer .= "            \$loader->$prop = ComposerStaticInit$suffix::\$$prop;\n";
            }
        }

        return $file . <<<INITIALIZER
    public static function getInitializer(ClassLoader \$loader)
    {
        return \Closure::bind(function () use (\$loader) {
$initializer
        }, null, ClassLoader::class);
    }
}

INITIALIZER;
    }

    /**
     * @param array<int, array{0: PackageInterface, 1: string}> $packageMap
     * @param string $type one of: 'psr-0'|'psr-4'|'classmap'|'files'
     * @return array<int, string>|array<string, array<string>>|array<string, string>
     */
    protected function parseAutoloadsType(array $packageMap, $type, RootPackageInterface $rootPackage)
    {
        $autoloads = array();

        foreach ($packageMap as $item) {
            list($package, $installPath) = $item;

            $autoload = $package->getAutoload();
            if ($this->devMode && $package === $rootPackage) {
                $autoload = array_merge_recursive($autoload, $package->getDevAutoload());
            }

            // skip misconfigured packages
            if (!isset($autoload[$type]) || !is_array($autoload[$type])) {
                continue;
            }
            if (null !== $package->getTargetDir() && $package !== $rootPackage) {
                $installPath = substr($installPath, 0, -strlen('/'.$package->getTargetDir()));
            }

            foreach ($autoload[$type] as $namespace => $paths) {
                foreach ((array) $paths as $path) {
                    if (($type === 'files' || $type === 'classmap' || $type === 'exclude-from-classmap') && $package->getTargetDir() && !Filesystem::isReadable($installPath.'/'.$path)) {
                        // remove target-dir from file paths of the root package
                        if ($package === $rootPackage) {
                            $targetDir = str_replace('\\<dirsep\\>', '[\\\\/]', preg_quote(str_replace(array('/', '\\'), '<dirsep>', $package->getTargetDir())));
                            $path = ltrim(Preg::replace('{^'.$targetDir.'}', '', ltrim($path, '\\/')), '\\/');
                        } else {
                            // add target-dir from file paths that don't have it
                            $path = $package->getTargetDir() . '/' . $path;
                        }
                    }

                    if ($type === 'exclude-from-classmap') {
                        // first escape user input
                        $path = Preg::replace('{/+}', '/', preg_quote(trim(strtr($path, '\\', '/'), '/')));

                        // add support for wildcards * and **
                        $path = strtr($path, array('\\*\\*' => '.+?', '\\*' => '[^/]+?'));

                        // add support for up-level relative paths
                        $updir = null;
                        $path = Preg::replaceCallback(
                            '{^((?:(?:\\\\\\.){1,2}+/)+)}',
                            function ($matches) use (&$updir) {
                                if (isset($matches[1])) {
                                    // undo preg_quote for the matched string
                                    $updir = str_replace('\\.', '.', $matches[1]);
                                }

                                return '';
                            },
                            $path
                        );
                        if (empty($installPath)) {
                            $installPath = strtr(getcwd(), '\\', '/');
                        }

                        $resolvedPath = realpath($installPath . '/' . $updir);
                        if (false === $resolvedPath) {
                            continue;
                        }
                        $autoloads[] = preg_quote(strtr($resolvedPath, '\\', '/')) . '/' . $path . '($|/)';
                        continue;
                    }

                    $relativePath = empty($installPath) ? (empty($path) ? '.' : $path) : $installPath.'/'.$path;

                    if ($type === 'files') {
                        $autoloads[$this->getFileIdentifier($package, $path)] = $relativePath;
                        continue;
                    }
                    if ($type === 'classmap') {
                        $autoloads[] = $relativePath;
                        continue;
                    }

                    $autoloads[$namespace][] = $relativePath;
                }
            }
        }

        return $autoloads;
    }

    /**
     * @param string $path
     * @return string
     */
    protected function getFileIdentifier(PackageInterface $package, $path)
    {
        return md5($package->getName() . ':' . $path);
    }

    /**
     * Filters out dev-dependencies
     *
     * @param array<int, array{0: PackageInterface, 1: string}> $packageMap
     * @param  RootPackageInterface $rootPackage
     * @return array<int, array{0: PackageInterface, 1: string}>
     *
     * @phpstan-param array<int, array{0: PackageInterface, 1: string}> $packageMap
     */
    protected function filterPackageMap(array $packageMap, RootPackageInterface $rootPackage)
    {
        $packages = array();
        $include = array();
        $replacedBy = array();

        foreach ($packageMap as $item) {
            $package = $item[0];
            $name = $package->getName();
            $packages[$name] = $package;
            foreach ($package->getReplaces() as $replace) {
                $replacedBy[$replace->getTarget()] = $name;
            }
        }

        $add = function (PackageInterface $package) use (&$add, $packages, &$include, $replacedBy) {
            foreach ($package->getRequires() as $link) {
                $target = $link->getTarget();
                if (isset($replacedBy[$target])) {
                    $target = $replacedBy[$target];
                }
                if (!isset($include[$target])) {
                    $include[$target] = true;
                    if (isset($packages[$target])) {
                        $add($packages[$target]);
                    }
                }
            }
        };
        $add($rootPackage);

        return array_filter(
            $packageMap,
            function ($item) use ($include) {
                $package = $item[0];
                foreach ($package->getNames() as $name) {
                    if (isset($include[$name])) {
                        return true;
                    }
                }

                return false;
            }
        );
    }

    /**
     * Sorts packages by dependency weight
     *
     * Packages of equal weight are sorted alphabetically
     *
     * @param array<int, array{0: PackageInterface, 1: string}> $packageMap
     * @return array<int, array{0: PackageInterface, 1: string}>
     */
    protected function sortPackageMap(array $packageMap)
    {
        $packages = array();
        $paths = array();

        foreach ($packageMap as $item) {
            list($package, $path) = $item;
            $name = $package->getName();
            $packages[$name] = $package;
            $paths[$name] = $path;
        }

        $sortedPackages = PackageSorter::sortPackages($packages);

        $sortedPackageMap = array();

        foreach ($sortedPackages as $package) {
            $name = $package->getName();
            $sortedPackageMap[] = array($packages[$name], $paths[$name]);
        }

        return $sortedPackageMap;
    }
}

/**
 * @param string $fileIdentifier
 * @param string $file
 * @return void
 */
function composerRequire($fileIdentifier, $file)
{
    if (empty($GLOBALS['__composer_autoload_files'][$fileIdentifier])) {
        $GLOBALS['__composer_autoload_files'][$fileIdentifier] = true;

        require $file;
    }
}
