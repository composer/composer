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

namespace Composer\Repository;

use Composer\Json\JsonFile;
use Composer\Package\Loader\ArrayLoader;
use Composer\Package\PackageInterface;
use Composer\Package\RootAliasPackage;
use Composer\Package\RootPackageInterface;
use Composer\Package\AliasPackage;
use Composer\Package\Dumper\ArrayDumper;
use Composer\Installer\InstallationManager;
use Composer\Util\Filesystem;
use Composer\Util\Platform;

/**
 * Filesystem repository.
 *
 * @author Konstantin Kudryashov <ever.zet@gmail.com>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class FilesystemRepository extends WritableArrayRepository
{
    /** @var JsonFile */
    protected $file;
    /** @var bool */
    private $dumpVersions;
    /** @var ?RootPackageInterface */
    private $rootPackage;
    /** @var Filesystem */
    private $filesystem;
    /** @var bool|null */
    private $devMode = null;

    /**
     * Initializes filesystem repository.
     *
     * @param JsonFile              $repositoryFile repository json file
     * @param bool                  $dumpVersions
     * @param ?RootPackageInterface $rootPackage    Must be provided if $dumpVersions is true
     */
    public function __construct(JsonFile $repositoryFile, bool $dumpVersions = false, RootPackageInterface $rootPackage = null, Filesystem $filesystem = null)
    {
        parent::__construct();
        $this->file = $repositoryFile;
        $this->dumpVersions = $dumpVersions;
        $this->rootPackage = $rootPackage;
        $this->filesystem = $filesystem ?: new Filesystem();
        if ($dumpVersions && !$rootPackage) {
            throw new \InvalidArgumentException('Expected a root package instance if $dumpVersions is true');
        }
    }

    /**
     * @return bool|null true if dev requirements were installed, false if --no-dev was used, null if yet unknown
     */
    public function getDevMode()
    {
        return $this->devMode;
    }

    /**
     * Initializes repository (reads file, or remote address).
     */
    protected function initialize()
    {
        parent::initialize();

        if (!$this->file->exists()) {
            return;
        }

        try {
            $data = $this->file->read();
            if (isset($data['packages'])) {
                $packages = $data['packages'];
            } else {
                $packages = $data;
            }

            if (isset($data['dev-package-names'])) {
                $this->setDevPackageNames($data['dev-package-names']);
            }
            if (isset($data['dev'])) {
                $this->devMode = $data['dev'];
            }

            if (!is_array($packages)) {
                throw new \UnexpectedValueException('Could not parse package list from the repository');
            }
        } catch (\Exception $e) {
            throw new InvalidRepositoryException('Invalid repository data in '.$this->file->getPath().', packages could not be loaded: ['.get_class($e).'] '.$e->getMessage());
        }

        $loader = new ArrayLoader(null, true);
        foreach ($packages as $packageData) {
            $package = $loader->load($packageData);
            $this->addPackage($package);
        }
    }

    public function reload()
    {
        $this->packages = null;
        $this->initialize();
    }

    /**
     * Writes writable repository.
     */
    public function write(bool $devMode, InstallationManager $installationManager)
    {
        $data = array('packages' => array(), 'dev' => $devMode, 'dev-package-names' => array());
        $dumper = new ArrayDumper();

        // make sure the directory is created so we can realpath it
        // as realpath() does some additional normalizations with network paths that normalizePath does not
        // and we need to find shortest path correctly
        $repoDir = dirname($this->file->getPath());
        $this->filesystem->ensureDirectoryExists($repoDir);

        $repoDir = $this->filesystem->normalizePath(realpath($repoDir));
        $installPaths = array();

        foreach ($this->getCanonicalPackages() as $package) {
            $pkgArray = $dumper->dump($package);
            $path = $installationManager->getInstallPath($package);
            $installPath = null;
            if ('' !== $path && null !== $path) {
                $normalizedPath = $this->filesystem->normalizePath($this->filesystem->isAbsolutePath($path) ? $path : Platform::getCwd() . '/' . $path);
                $installPath = $this->filesystem->findShortestPath($repoDir, $normalizedPath, true);
            }
            $installPaths[$package->getName()] = $installPath;

            $pkgArray['install-path'] = $installPath;
            $data['packages'][] = $pkgArray;

            // only write to the files the names which are really installed, as we receive the full list
            // of dev package names before they get installed during composer install
            if (in_array($package->getName(), $this->devPackageNames, true)) {
                $data['dev-package-names'][] = $package->getName();
            }
        }

        sort($data['dev-package-names']);
        usort($data['packages'], function ($a, $b): int {
            return strcmp($a['name'], $b['name']);
        });

        $this->file->write($data);

        if ($this->dumpVersions) {
            $versions = $this->generateInstalledVersions($installationManager, $installPaths, $devMode, $repoDir);

            $this->filesystem->filePutContentsIfModified($repoDir.'/installed.php', '<?php return ' . $this->dumpToPhpCode($versions) . ';'."\n");
            $installedVersionsClass = file_get_contents(__DIR__.'/../InstalledVersions.php');
            $this->filesystem->filePutContentsIfModified($repoDir.'/InstalledVersions.php', $installedVersionsClass);

            \Composer\InstalledVersions::reload($versions);
        }
    }

    /**
     * @param array<mixed> $array
     * @param int $level
     *
     * @return string
     */
    private function dumpToPhpCode(array $array = array(), int $level = 0): string
    {
        $lines = "array(\n";
        $level++;

        foreach ($array as $key => $value) {
            $lines .= str_repeat('    ', $level);
            $lines .= is_int($key) ? $key . ' => ' : '\'' . $key . '\' => ';

            if (is_array($value)) {
                if (!empty($value)) {
                    $lines .= $this->dumpToPhpCode($value, $level);
                } else {
                    $lines .= "array(),\n";
                }
            } elseif ($key === 'install_path' && is_string($value)) {
                if ($this->filesystem->isAbsolutePath($value)) {
                    $lines .= var_export($value, true) . ",\n";
                } else {
                    $lines .= "__DIR__ . " . var_export('/' . $value, true) . ",\n";
                }
            } else {
                $lines .= var_export($value, true) . ",\n";
            }
        }

        $lines .= str_repeat('    ', $level - 1) . ')' . ($level - 1 == 0 ? '' : ",\n");

        return $lines;
    }

    /**
     * @param array<string, string> $installPaths
     *
     * @return array{root: array{name: string, pretty_version: string, version: string, reference: string|null, type: string, install_path: string, aliases: string[], dev: bool}, versions: array<string, array{pretty_version?: string, version?: string, reference?: string|null, type?: string, install_path?: string, aliases?: string[], dev_requirement: bool, replaced?: string[], provided?: string[]}>}
     */
    private function generateInstalledVersions(InstallationManager $installationManager, array $installPaths, bool $devMode, string $repoDir): array
    {
        $devPackages = array_flip($this->devPackageNames);
        $packages = $this->getPackages();
        if (null === $this->rootPackage) {
            throw new \LogicException('It should not be possible to dump packages if no root package is given');
        }
        $packages[] = $rootPackage = $this->rootPackage;

        while ($rootPackage instanceof RootAliasPackage) {
            $rootPackage = $rootPackage->getAliasOf();
            $packages[] = $rootPackage;
        }
        $versions = [
            'root' => $this->dumpRootPackage($rootPackage, $installPaths, $devMode, $repoDir, $devPackages),
            'versions' => [],
        ];

        // add real installed packages
        foreach ($packages as $package) {
            if ($package instanceof AliasPackage) {
                continue;
            }

            $versions['versions'][$package->getName()] = $this->dumpInstalledPackage($package, $installPaths, $repoDir, $devPackages);
        }

        // add provided/replaced packages
        foreach ($packages as $package) {
            $isDevPackage = isset($devPackages[$package->getName()]);
            foreach ($package->getReplaces() as $replace) {
                // exclude platform replaces as when they are really there we can not check for their presence
                if (PlatformRepository::isPlatformPackage($replace->getTarget())) {
                    continue;
                }
                if (!isset($versions['versions'][$replace->getTarget()]['dev_requirement'])) {
                    $versions['versions'][$replace->getTarget()]['dev_requirement'] = $isDevPackage;
                } elseif (!$isDevPackage) {
                    $versions['versions'][$replace->getTarget()]['dev_requirement'] = false;
                }
                $replaced = $replace->getPrettyConstraint();
                if ($replaced === 'self.version') {
                    $replaced = $package->getPrettyVersion();
                }
                if (!isset($versions['versions'][$replace->getTarget()]['replaced']) || !in_array($replaced, $versions['versions'][$replace->getTarget()]['replaced'], true)) {
                    $versions['versions'][$replace->getTarget()]['replaced'][] = $replaced;
                }
            }
            foreach ($package->getProvides() as $provide) {
                // exclude platform provides as when they are really there we can not check for their presence
                if (PlatformRepository::isPlatformPackage($provide->getTarget())) {
                    continue;
                }
                if (!isset($versions['versions'][$provide->getTarget()]['dev_requirement'])) {
                    $versions['versions'][$provide->getTarget()]['dev_requirement'] = $isDevPackage;
                } elseif (!$isDevPackage) {
                    $versions['versions'][$provide->getTarget()]['dev_requirement'] = false;
                }
                $provided = $provide->getPrettyConstraint();
                if ($provided === 'self.version') {
                    $provided = $package->getPrettyVersion();
                }
                if (!isset($versions['versions'][$provide->getTarget()]['provided']) || !in_array($provided, $versions['versions'][$provide->getTarget()]['provided'], true)) {
                    $versions['versions'][$provide->getTarget()]['provided'][] = $provided;
                }
            }
        }

        // add aliases
        foreach ($packages as $package) {
            if (!$package instanceof AliasPackage) {
                continue;
            }
            $versions['versions'][$package->getName()]['aliases'][] = $package->getPrettyVersion();
            if ($package instanceof RootPackageInterface) {
                $versions['root']['aliases'][] = $package->getPrettyVersion();
            }
        }

        ksort($versions['versions']);
        ksort($versions);

        return $versions;
    }

    /**
     * @param array<string, string> $installPaths
     * @param array<string, int> $devPackages
     * @return array{pretty_version: string, version: string, reference: string|null, type: string, install_path: string, aliases: string[], dev_requirement: bool}
     */
    private function dumpInstalledPackage(PackageInterface $package, array $installPaths, string $repoDir, array $devPackages): array
    {
        $reference = null;
        if ($package->getInstallationSource()) {
            $reference = $package->getInstallationSource() === 'source' ? $package->getSourceReference() : $package->getDistReference();
        }
        if (null === $reference) {
            $reference = ($package->getSourceReference() ?: $package->getDistReference()) ?: null;
        }

        if ($package instanceof RootPackageInterface) {
            $to = $this->filesystem->normalizePath(realpath(Platform::getCwd()));
            $installPath = $this->filesystem->findShortestPath($repoDir, $to, true);
        } else {
            $installPath = $installPaths[$package->getName()];
        }

        $data = [
            'pretty_version' => $package->getPrettyVersion(),
            'version' => $package->getVersion(),
            'reference' => $reference,
            'type' => $package->getType(),
            'install_path' => $installPath,
            'aliases' => array(),
            'dev_requirement' => isset($devPackages[$package->getName()]),
        ];

        return $data;
    }

    /**
     * @param array<string, string> $installPaths
     * @param array<string, int> $devPackages
     * @return array{name: string, pretty_version: string, version: string, reference: string|null, type: string, install_path: string, aliases: string[], dev: bool}
     */
    private function dumpRootPackage(RootPackageInterface $package, array $installPaths, bool $devMode, string $repoDir, array $devPackages)
    {
        $data = $this->dumpInstalledPackage($package, $installPaths, $repoDir, $devPackages);

        return [
            'name' => $package->getName(),
            'pretty_version' => $data['pretty_version'],
            'version' => $data['version'],
            'reference' => $data['reference'],
            'type' => $data['type'],
            'install_path' => $data['install_path'],
            'aliases' => $data['aliases'],
            'dev' => $devMode,
        ];
    }
}
