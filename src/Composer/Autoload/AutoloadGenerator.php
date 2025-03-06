<?php declare(strict_types=1);

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

use Composer\ClassMapGenerator\ClassMap;
use Composer\ClassMapGenerator\ClassMapGenerator;
use Composer\Config;
use Composer\EventDispatcher\EventDispatcher;
use Composer\Filter\PlatformRequirementFilter\IgnoreAllPlatformRequirementFilter;
use Composer\Filter\PlatformRequirementFilter\PlatformRequirementFilterFactory;
use Composer\Filter\PlatformRequirementFilter\PlatformRequirementFilterInterface;
use Composer\Installer\InstallationManager;
use Composer\IO\IOInterface;
use Composer\IO\NullIO;
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
use Composer\Package\Locker;
use Symfony\Component\Console\Formatter\OutputFormatter;

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
     * @var IOInterface
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
    private $dryRun = false;

    /**
     * @var bool
     */
    private $runScripts = false;

    /**
     * @var PlatformRequirementFilterInterface
     */
    private $platformRequirementFilter;

    public function __construct(EventDispatcher $eventDispatcher, ?IOInterface $io = null)
    {
        $this->eventDispatcher = $eventDispatcher;
        $this->io = $io ?? new NullIO();

        $this->platformRequirementFilter = PlatformRequirementFilterFactory::ignoreNothing();
    }

    /**
     * @return void
     */
    public function setDevMode(bool $devMode = true)
    {
        $this->devMode = $devMode;
    }

    /**
     * Whether generated autoloader considers the class map authoritative.
     *
     * @return void
     */
    public function setClassMapAuthoritative(bool $classMapAuthoritative)
    {
        $this->classMapAuthoritative = $classMapAuthoritative;
    }

    /**
     * Whether generated autoloader considers APCu caching.
     *
     * @return void
     */
    public function setApcu(bool $apcu, ?string $apcuPrefix = null)
    {
        $this->apcu = $apcu;
        $this->apcuPrefix = $apcuPrefix;
    }

    /**
     * Whether to run scripts or not
     *
     * @return void
     */
    public function setRunScripts(bool $runScripts = true)
    {
        $this->runScripts = $runScripts;
    }

    /**
     * Whether to run in drymode or not
     */
    public function setDryRun(bool $dryRun = true): void
    {
        $this->dryRun = $dryRun;
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
     * @return ClassMap
     * @throws \Seld\JsonLint\ParsingException
     * @throws \RuntimeException
     */
    public function dump(Config $config, InstalledRepositoryInterface $localRepo, RootPackageInterface $rootPackage, InstallationManager $installationManager, string $targetDir, bool $scanPsrPackages = false, ?string $suffix = null, ?Locker $locker = null, bool $strictAmbiguous = false)
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

            $this->eventDispatcher->dispatchScript(ScriptEvents::PRE_AUTOLOAD_DUMP, $this->devMode, [], [
                'optimize' => $scanPsrPackages,
            ]);
        }

        $classMapGenerator = new ClassMapGenerator(['php', 'inc', 'hh']);
        $classMapGenerator->avoidDuplicateScans();

        $filesystem = new Filesystem();
        $filesystem->ensureDirectoryExists($config->get('vendor-dir'));
        // Do not remove double realpath() calls.
        // Fixes failing Windows realpath() implementation.
        // See https://bugs.php.net/bug.php?id=72738
        $basePath = $filesystem->normalizePath(realpath(realpath(Platform::getCwd())));
        $vendorPath = $filesystem->normalizePath(realpath(realpath($config->get('vendor-dir'))));
        $useGlobalIncludePath = $config->get('use-include-path');
        $prependAutoloader = $config->get('prepend-autoloader') === false ? 'false' : 'true';
        $targetDir = $vendorPath.'/'.$targetDir;
        $filesystem->ensureDirectoryExists($targetDir);

        $vendorPathCode = $filesystem->findShortestPathCode(realpath($targetDir), $vendorPath, true);
        $vendorPathToTargetDirCode = $filesystem->findShortestPathCode($vendorPath, realpath($targetDir), true);

        $appBaseDirCode = $filesystem->findShortestPathCode($vendorPath, $basePath, true);
        $appBaseDirCode = str_replace('__DIR__', '$vendorDir', $appBaseDirCode);

        $namespacesFile = <<<EOF
<?php

// autoload_namespaces.php @generated by Composer

\$vendorDir = $vendorPathCode;
\$baseDir = $appBaseDirCode;

return array(

EOF;

        $psr4File = <<<EOF
<?php

// autoload_psr4.php @generated by Composer

\$vendorDir = $vendorPathCode;
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
            $exportedPaths = [];
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
            $exportedPaths = [];
            foreach ($paths as $path) {
                $exportedPaths[] = $this->getPathCode($filesystem, $basePath, $vendorPath, $path);
            }
            $exportedPrefix = var_export($namespace, true);
            $psr4File .= "    $exportedPrefix => ";
            $psr4File .= "array(".implode(', ', $exportedPaths)."),\n";
        }
        $psr4File .= ");\n";

        // add custom psr-0 autoloading if the root package has a target dir
        $targetDirLoader = null;
        $mainAutoload = $rootPackage->getAutoload();
        if ($rootPackage->getTargetDir() && !empty($mainAutoload['psr-0'])) {
            $levels = substr_count($filesystem->normalizePath($rootPackage->getTargetDir()), '/') + 1;
            $prefixes = implode(', ', array_map(static function ($prefix): string {
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

        $excluded = [];
        if (!empty($autoloads['exclude-from-classmap'])) {
            $excluded = $autoloads['exclude-from-classmap'];
        }

        foreach ($autoloads['classmap'] as $dir) {
            $classMapGenerator->scanPaths($dir, $this->buildExclusionRegex($dir, $excluded));
        }

        if ($scanPsrPackages) {
            $namespacesToScan = [];

            // Scan the PSR-0/4 directories for class files, and add them to the class map
            foreach (['psr-4', 'psr-0'] as $psrType) {
                foreach ($autoloads[$psrType] as $namespace => $paths) {
                    $namespacesToScan[$namespace][] = ['paths' => $paths, 'type' => $psrType];
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

                        // if the vendor dir is contained within a psr-0/psr-4 dir being scanned we exclude it
                        if (str_contains($vendorPath, $dir.'/')) {
                            $exclusionRegex = $this->buildExclusionRegex($dir, array_merge($excluded, [$vendorPath.'/']));
                        } else {
                            $exclusionRegex = $this->buildExclusionRegex($dir, $excluded);
                        }

                        $classMapGenerator->scanPaths($dir, $exclusionRegex, $group['type'], $namespace);
                    }
                }
            }
        }

        $classMap = $classMapGenerator->getClassMap();
        if ($strictAmbiguous) {
            $ambiguousClasses = $classMap->getAmbiguousClasses(false);
        } else {
            $ambiguousClasses = $classMap->getAmbiguousClasses();
        }
        foreach ($ambiguousClasses as $className => $ambiguousPaths) {
            if (count($ambiguousPaths) > 1) {
                $this->io->writeError(
                    '<warning>Warning: Ambiguous class resolution, "'.$className.'"'.
                    ' was found '. (count($ambiguousPaths) + 1) .'x: in "'.$classMap->getClassPath($className).'" and "'. implode('", "', $ambiguousPaths) .'", the first will be used.</warning>'
                );
            } else {
                $this->io->writeError(
                    '<warning>Warning: Ambiguous class resolution, "'.$className.'"'.
                    ' was found in both "'.$classMap->getClassPath($className).'" and "'. implode('", "', $ambiguousPaths) .'", the first will be used.</warning>'
                );
            }
        }
        if (\count($ambiguousClasses) > 0) {
            $this->io->writeError('<info>To resolve ambiguity in classes not under your control you can ignore them by path using <href='.OutputFormatter::escape('https://getcomposer.org/doc/04-schema.md#exclude-files-from-classmaps').'>exclude-files-from-classmap</>');
        }

        // output PSR violations which are not coming from the vendor dir
        $classMap->clearPsrViolationsByPath($vendorPath);
        foreach ($classMap->getPsrViolations() as $msg) {
            $this->io->writeError("<warning>$msg</warning>");
        }

        $classMap->addClass('Composer\InstalledVersions', $vendorPath . '/composer/InstalledVersions.php');
        $classMap->sort();

        $classmapFile = <<<EOF
<?php

// autoload_classmap.php @generated by Composer

\$vendorDir = $vendorPathCode;
\$baseDir = $appBaseDirCode;

return array(

EOF;
        foreach ($classMap->getMap() as $className => $path) {
            $pathCode = $this->getPathCode($filesystem, $basePath, $vendorPath, $path).",\n";
            $classmapFile .= '    '.var_export($className, true).' => '.$pathCode;
        }
        $classmapFile .= ");\n";

        if ('' === $suffix) {
            $suffix = null;
        }
        if (null === $suffix) {
            $suffix = $config->get('autoloader-suffix');

            // carry over existing autoload.php's suffix if possible and none is configured
            if (null === $suffix && Filesystem::isReadable($vendorPath.'/autoload.php')) {
                $content = (string) file_get_contents($vendorPath.'/autoload.php');
                if (Preg::isMatch('{ComposerAutoloaderInit([^:\s]+)::}', $content, $match)) {
                    $suffix = $match[1];
                }
            }

            if (null === $suffix) {
                $suffix = $locker !== null && $locker->isLocked() ? $locker->getLockData()['content-hash'] : bin2hex(random_bytes(16));
            }
        }

        if ($this->dryRun) {
            return $classMap;
        }

        $filesystem->filePutContentsIfModified($targetDir.'/autoload_namespaces.php', $namespacesFile);
        $filesystem->filePutContentsIfModified($targetDir.'/autoload_psr4.php', $psr4File);
        $filesystem->filePutContentsIfModified($targetDir.'/autoload_classmap.php', $classmapFile);
        $includePathFilePath = $targetDir.'/include_paths.php';
        if ($includePathFileContents = $this->getIncludePathsFile($packageMap, $filesystem, $basePath, $vendorPath, $vendorPathCode, $appBaseDirCode)) {
            $filesystem->filePutContentsIfModified($includePathFilePath, $includePathFileContents);
        } elseif (file_exists($includePathFilePath)) {
            unlink($includePathFilePath);
        }
        $includeFilesFilePath = $targetDir.'/autoload_files.php';
        if ($includeFilesFileContents = $this->getIncludeFilesFile($autoloads['files'], $filesystem, $basePath, $vendorPath, $vendorPathCode, $appBaseDirCode)) {
            $filesystem->filePutContentsIfModified($includeFilesFilePath, $includeFilesFileContents);
        } elseif (file_exists($includeFilesFilePath)) {
            unlink($includeFilesFilePath);
        }
        $filesystem->filePutContentsIfModified($targetDir.'/autoload_static.php', $this->getStaticFile($suffix, $targetDir, $vendorPath, $basePath));
        $checkPlatform = $config->get('platform-check') !== false && !($this->platformRequirementFilter instanceof IgnoreAllPlatformRequirementFilter);
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
        $filesystem->filePutContentsIfModified($targetDir.'/autoload_real.php', $this->getAutoloadRealFile(true, (bool) $includePathFileContents, $targetDirLoader, (bool) $includeFilesFileContents, $vendorPathCode, $appBaseDirCode, $suffix, $useGlobalIncludePath, $prependAutoloader, $checkPlatform));

        $filesystem->safeCopy(__DIR__.'/ClassLoader.php', $targetDir.'/ClassLoader.php');
        $filesystem->safeCopy(__DIR__.'/../../../LICENSE', $targetDir.'/LICENSE');

        if ($this->runScripts) {
            $this->eventDispatcher->dispatchScript(ScriptEvents::POST_AUTOLOAD_DUMP, $this->devMode, [], [
                'optimize' => $scanPsrPackages,
            ]);
        }

        return $classMap;
    }

    /**
     * @param array<string> $excluded
     * @return non-empty-string|null
     */
    private function buildExclusionRegex(string $dir, array $excluded): ?string
    {
        if ([] === $excluded) {
            return null;
        }

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

        return \count($excluded) > 0 ? '{(' . implode('|', $excluded) . ')}' : null;
    }

    /**
     * @param PackageInterface[] $packages
     * @return non-empty-array<int, array{0: PackageInterface, 1: string|null}>
     */
    public function buildPackageMap(InstallationManager $installationManager, PackageInterface $rootPackage, array $packages)
    {
        // build package => install path map
        $packageMap = [[$rootPackage, '']];

        foreach ($packages as $package) {
            if ($package instanceof AliasPackage) {
                continue;
            }
            $this->validatePackage($package);
            $packageMap[] = [
                $package,
                $installationManager->getInstallPath($package),
            ];
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
     * @param non-empty-array<int, array{0: PackageInterface, 1: string|null}> $packageMap array of array(package, installDir-relative-to-composer.json or null for metapackages)
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
            $packageMap = array_filter($packageMap, static function ($item) use ($filteredDevPackages): bool {
                return !in_array($item[0]->getName(), $filteredDevPackages, true);
            });
        } elseif ($filteredDevPackages) {
            $packageMap = $this->filterPackageMap($packageMap, $rootPackage);
        }
        $sortedPackageMap = $this->sortPackageMap($packageMap);
        $sortedPackageMap[] = $rootPackageMap;
        $reverseSortedMap = array_reverse($sortedPackageMap);

        // reverse-sorted means root first, then dependents, then their dependents, etc.
        // which makes sense to allow root to override classmap or psr-0/4 entries with higher precedence rules
        $psr0 = $this->parseAutoloadsType($reverseSortedMap, 'psr-0', $rootPackage);
        $psr4 = $this->parseAutoloadsType($reverseSortedMap, 'psr-4', $rootPackage);
        $classmap = $this->parseAutoloadsType($reverseSortedMap, 'classmap', $rootPackage);

        // sorted (i.e. dependents first) for files to ensure that dependencies are loaded/available once a file is included
        $files = $this->parseAutoloadsType($sortedPackageMap, 'files', $rootPackage);
        // using sorted here but it does not really matter as all are excluded equally
        $exclude = $this->parseAutoloadsType($sortedPackageMap, 'exclude-from-classmap', $rootPackage);

        krsort($psr0);
        krsort($psr4);

        return [
            'psr-0' => $psr0,
            'psr-4' => $psr4,
            'classmap' => $classmap,
            'files' => $files,
            'exclude-from-classmap' => $exclude,
        ];
    }

    /**
     * Registers an autoloader based on an autoload-map returned by parseAutoloads
     *
     * @param array<string, mixed[]> $autoloads see parseAutoloads return value
     * @return ClassLoader
     */
    public function createLoader(array $autoloads, ?string $vendorDir = null)
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
            $excluded = [];
            if (!empty($autoloads['exclude-from-classmap'])) {
                $excluded = $autoloads['exclude-from-classmap'];
            }

            $classMapGenerator = new ClassMapGenerator(['php', 'inc', 'hh']);
            $classMapGenerator->avoidDuplicateScans();

            foreach ($autoloads['classmap'] as $dir) {
                try {
                    $classMapGenerator->scanPaths($dir, $this->buildExclusionRegex($dir, $excluded));
                } catch (\RuntimeException $e) {
                    $this->io->writeError('<warning>'.$e->getMessage().'</warning>');
                }
            }

            $loader->addClassMap($classMapGenerator->getClassMap()->getMap());
        }

        return $loader;
    }

    /**
     * @param array<int, array{0: PackageInterface, 1: string|null}> $packageMap
     * @return ?string
     */
    protected function getIncludePathsFile(array $packageMap, Filesystem $filesystem, string $basePath, string $vendorPath, string $vendorPathCode, string $appBaseDirCode)
    {
        $includePaths = [];

        foreach ($packageMap as $item) {
            [$package, $installPath] = $item;

            // packages that are not installed cannot autoload anything
            if (null === $installPath) {
                continue;
            }

            if (null !== $package->getTargetDir() && strlen($package->getTargetDir()) > 0) {
                $installPath = substr($installPath, 0, -strlen('/'.$package->getTargetDir()));
            }

            foreach ($package->getIncludePaths() as $includePath) {
                $includePath = trim($includePath, '/');
                $includePaths[] = $installPath === '' ? $includePath : $installPath.'/'.$includePath;
            }
        }

        if (\count($includePaths) === 0) {
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
     * @return ?string
     */
    protected function getIncludeFilesFile(array $files, Filesystem $filesystem, string $basePath, string $vendorPath, string $vendorPathCode, string $appBaseDirCode)
    {
        // Get the path to each file, and make sure these paths are unique.
        $files = array_map(
            function (string $functionFile) use ($filesystem, $basePath, $vendorPath): string {
                return $this->getPathCode($filesystem, $basePath, $vendorPath, $functionFile);
            },
            $files
        );
        $uniqueFiles = array_unique($files);
        if (count($uniqueFiles) < count($files)) {
            $this->io->writeError('<warning>The following "files" autoload rules are included multiple times, this may cause issues and should be resolved:</warning>');
            foreach (array_unique(array_diff_assoc($files, $uniqueFiles)) as $duplicateFile) {
                $this->io->writeError('<warning> - '.$duplicateFile.'</warning>');
            }
        }
        unset($uniqueFiles);

        $filesCode = '';

        foreach ($files as $fileIdentifier => $functionFile) {
            $filesCode .= '    ' . var_export($fileIdentifier, true) . ' => ' . $functionFile . ",\n";
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
     * @return string
     */
    protected function getPathCode(Filesystem $filesystem, string $basePath, string $vendorPath, string $path)
    {
        if (!$filesystem->isAbsolutePath($path)) {
            $path = $basePath . '/' . $path;
        }
        $path = $filesystem->normalizePath($path);

        $baseDir = '';
        if (strpos($path.'/', $vendorPath.'/') === 0) {
            $path = (string) substr($path, strlen($vendorPath));
            $baseDir = '$vendorDir . ';
        } else {
            $path = $filesystem->normalizePath($filesystem->findShortestPath($basePath, $path, true));
            if (!$filesystem->isAbsolutePath($path)) {
                $baseDir = '$baseDir . ';
                $path = '/' . $path;
            }
        }

        if (Preg::isMatch('{\.phar([\\\\/]|$)}', $path)) {
            $baseDir = "'phar://' . " . $baseDir;
        }

        return $baseDir . var_export($path, true);
    }

    /**
     * @param array<int, array{0: PackageInterface, 1: string|null}> $packageMap
     * @param bool|'php-only' $checkPlatform
     * @param string[] $devPackageNames
     * @return ?string
     */
    protected function getPlatformCheck(array $packageMap, $checkPlatform, array $devPackageNames)
    {
        $lowestPhpVersion = Bound::zero();
        $requiredPhp64bit = false;
        $requiredExtensions = [];
        $extensionProviders = [];

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

                if (in_array($link->getTarget(), ['php', 'php-64bit'], true)) {
                    $constraint = $link->getConstraint();
                    if ($constraint->getLowerBound()->compareTo($lowestPhpVersion, '>')) {
                        $lowestPhpVersion = $constraint->getLowerBound();
                    }
                }

                if ('php-64bit' === $link->getTarget()) {
                    $requiredPhp64bit = true;
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

        $formatToPhpVersionId = static function (Bound $bound): int {
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

        $formatToHumanReadable = static function (Bound $bound) {
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

        if ($requiredPhp64bit) {
            $requiredPhp .= <<<PHP_CHECK

if (PHP_INT_SIZE !== 8) {
    \$issues[] = 'Your Composer dependencies require a 64-bit build of PHP.';
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
     * @return string
     */
    protected function getAutoloadFile(string $vendorPathToTargetDirCode, string $suffix)
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

if (PHP_VERSION_ID < 50600) {
    if (!headers_sent()) {
        header('HTTP/1.1 500 Internal Server Error');
    }
    \$err = 'Composer 2.3.0 dropped support for autoloading on PHP <5.6 and you are running '.PHP_VERSION.', please upgrade PHP or use Composer 2.2 LTS via "composer self-update --2.2". Aborting.'.PHP_EOL;
    if (!ini_get('display_errors')) {
        if (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') {
            fwrite(STDERR, \$err);
        } elseif (!headers_sent()) {
            echo \$err;
        }
    }
    trigger_error(
        \$err,
        E_USER_ERROR
    );
}

require_once $vendorPathToTargetDirCode;

return ComposerAutoloaderInit$suffix::getLoader();

AUTOLOAD;
    }

    /**
     * @param string $vendorPathCode unused in this method
     * @param string $appBaseDirCode unused in this method
     * @param string $prependAutoloader 'true'|'false'
     * @return string
     */
    protected function getAutoloadRealFile(bool $useClassMap, bool $useIncludePath, ?string $targetDirLoader, bool $useIncludeFiles, string $vendorPathCode, string $appBaseDirCode, string $suffix, bool $useGlobalIncludePath, string $prependAutoloader, bool $checkPlatform)
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
        self::\$loader = \$loader = new \\Composer\\Autoload\\ClassLoader(\\dirname(__DIR__));
        spl_autoload_unregister(array('ComposerAutoloaderInit$suffix', 'loadClassLoader'));


CLASSLOADER_INIT;

        if ($useIncludePath) {
            $file .= <<<'INCLUDE_PATH'
        $includePaths = require __DIR__ . '/include_paths.php';
        $includePaths[] = get_include_path();
        set_include_path(implode(PATH_SEPARATOR, $includePaths));


INCLUDE_PATH;
        }

        // keeping PHP 5.6+ compatibility for the autoloader here by using call_user_func vs getInitializer()()
        $file .= <<<STATIC_INIT
        require __DIR__ . '/autoload_static.php';
        call_user_func(\Composer\Autoload\ComposerStaticInit$suffix::getInitializer(\$loader));


STATIC_INIT;

        if ($this->classMapAuthoritative) {
            $file .= <<<'CLASSMAPAUTHORITATIVE'
        $loader->setClassMapAuthoritative(true);

CLASSMAPAUTHORITATIVE;
        }

        if ($this->apcu) {
            $apcuPrefix = var_export(($this->apcuPrefix !== null ? $this->apcuPrefix : bin2hex(random_bytes(10))), true);
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
        \$filesToLoad = \Composer\Autoload\ComposerStaticInit$suffix::\$files;
        \$requireFile = \Closure::bind(static function (\$fileIdentifier, \$file) {
            if (empty(\$GLOBALS['__composer_autoload_files'][\$fileIdentifier])) {
                \$GLOBALS['__composer_autoload_files'][\$fileIdentifier] = true;

                require \$file;
            }
        }, null, null);
        foreach (\$filesToLoad as \$fileIdentifier => \$file) {
            \$requireFile(\$fileIdentifier, \$file);
        }


INCLUDE_FILES;
        }

        $file .= <<<METHOD_FOOTER
        return \$loader;
    }

METHOD_FOOTER;

        $file .= $targetDirLoader;

        return $file . <<<FOOTER
}

FOOTER;
    }

    /**
     * @param string $vendorPath input for findShortestPathCode
     * @param string $basePath input for findShortestPathCode
     * @return string
     */
    protected function getStaticFile(string $suffix, string $targetDir, string $vendorPath, string $basePath)
    {
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

        /**
         * @var string $vendorDir
         * @var string $baseDir
         */
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
            $maps = ['files' => require $targetDir . '/autoload_files.php'];
        } else {
            $maps = [];
        }

        foreach ((array) $loader as $prop => $value) {
            if (!is_array($value) || \count($value) === 0 || !str_starts_with($prop, $prefix)) {
                continue;
            }
            $maps[substr($prop, $prefixLen)] = $value;
        }

        foreach ($maps as $prop => $value) {
            $value = strtr(
                var_export($value, true),
                [
                    $absoluteVendorPathCode => $vendorPathCode,
                    $absoluteVendorPharPathCode => $vendorPharPathCode,
                    $absoluteAppBaseDirCode => $appBaseDirCode,
                    $absoluteAppBaseDirPharCode => $appBaseDirPharCode,
                ]
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
     * @param array<int, array{0: PackageInterface, 1: string|null}> $packageMap
     * @param string $type one of: 'psr-0'|'psr-4'|'classmap'|'files'
     * @return array<int, string>|array<string, array<string>>|array<string, string>
     */
    protected function parseAutoloadsType(array $packageMap, string $type, RootPackageInterface $rootPackage)
    {
        $autoloads = [];

        foreach ($packageMap as $item) {
            [$package, $installPath] = $item;

            // packages that are not installed cannot autoload anything
            if (null === $installPath) {
                continue;
            }

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
                if (in_array($type, ['psr-4', 'psr-0'], true)) {
                    // normalize namespaces to ensure "\" becomes "" and others do not have leading separators as they are not needed
                    $namespace = ltrim($namespace, '\\');
                }
                foreach ((array) $paths as $path) {
                    if (($type === 'files' || $type === 'classmap' || $type === 'exclude-from-classmap') && $package->getTargetDir() && !Filesystem::isReadable($installPath.'/'.$path)) {
                        // remove target-dir from file paths of the root package
                        if ($package === $rootPackage) {
                            $targetDir = str_replace('\\<dirsep\\>', '[\\\\/]', preg_quote(str_replace(['/', '\\'], '<dirsep>', $package->getTargetDir())));
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
                        $path = strtr($path, ['\\*\\*' => '.+?', '\\*' => '[^/]+?']);

                        // add support for up-level relative paths
                        $updir = null;
                        $path = Preg::replaceCallback(
                            '{^((?:(?:\\\\\\.){1,2}+/)+)}',
                            static function ($matches) use (&$updir): string {
                                // undo preg_quote for the matched string
                                $updir = str_replace('\\.', '.', $matches[1]);

                                return '';
                            },
                            $path
                        );
                        if (empty($installPath)) {
                            $installPath = strtr(Platform::getCwd(), '\\', '/');
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
     * @return string
     */
    protected function getFileIdentifier(PackageInterface $package, string $path)
    {
        // TODO composer v3 change this to sha1 or xxh3? Possibly not worth the potential breakage though
        return hash('md5', $package->getName() . ':' . $path);
    }

    /**
     * Filters out dev-dependencies
     *
     * @param array<int, array{0: PackageInterface, 1: string|null}> $packageMap
     * @return array<int, array{0: PackageInterface, 1: string|null}>
     */
    protected function filterPackageMap(array $packageMap, RootPackageInterface $rootPackage)
    {
        $packages = [];
        $include = [];
        $replacedBy = [];

        foreach ($packageMap as $item) {
            $package = $item[0];
            $name = $package->getName();
            $packages[$name] = $package;
            foreach ($package->getReplaces() as $replace) {
                $replacedBy[$replace->getTarget()] = $name;
            }
        }

        $add = static function (PackageInterface $package) use (&$add, $packages, &$include, $replacedBy): void {
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
            static function ($item) use ($include): bool {
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
     * @param array<int, array{0: PackageInterface, 1: string|null}> $packageMap
     * @return array<int, array{0: PackageInterface, 1: string|null}>
     */
    protected function sortPackageMap(array $packageMap)
    {
        $packages = [];
        $paths = [];

        foreach ($packageMap as $item) {
            [$package, $path] = $item;
            $name = $package->getName();
            $packages[$name] = $package;
            $paths[$name] = $path;
        }

        $sortedPackages = PackageSorter::sortPackages($packages);

        $sortedPackageMap = [];

        foreach ($sortedPackages as $package) {
            $name = $package->getName();
            $sortedPackageMap[] = [$packages[$name], $paths[$name]];
        }

        return $sortedPackageMap;
    }
}

function composerRequire(string $fileIdentifier, string $file): void
{
    if (empty($GLOBALS['__composer_autoload_files'][$fileIdentifier])) {
        $GLOBALS['__composer_autoload_files'][$fileIdentifier] = true;

        require $file;
    }
}
