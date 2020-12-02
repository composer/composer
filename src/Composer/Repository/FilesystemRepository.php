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

namespace Composer\Repository;

use Composer\Json\JsonFile;
use Composer\Package\Loader\ArrayLoader;
use Composer\Package\RootPackageInterface;
use Composer\Package\AliasPackage;
use Composer\Package\Dumper\ArrayDumper;
use Composer\Installer\InstallationManager;
use Composer\Util\Filesystem;

/**
 * Filesystem repository.
 *
 * @author Konstantin Kudryashov <ever.zet@gmail.com>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class FilesystemRepository extends WritableArrayRepository
{
    protected $file;
    private $dumpVersions;
    private $rootPackage;

    /**
     * Initializes filesystem repository.
     *
     * @param JsonFile              $repositoryFile repository json file
     * @param bool                  $dumpVersions
     * @param ?RootPackageInterface $rootPackage    Must be provided if $dumpVersions is true
     */
    public function __construct(JsonFile $repositoryFile, $dumpVersions = false, RootPackageInterface $rootPackage = null)
    {
        parent::__construct();
        $this->file = $repositoryFile;
        $this->dumpVersions = $dumpVersions;
        $this->rootPackage = $rootPackage;
        if ($dumpVersions && !$rootPackage) {
            throw new \InvalidArgumentException('Expected a root package instance if $dumpVersions is true');
        }
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
    public function write($devMode, InstallationManager $installationManager)
    {
        $data = array('packages' => array(), 'dev' => $devMode, 'dev-package-names' => array());
        $dumper = new ArrayDumper();
        $fs = new Filesystem();
        $repoDir = dirname($fs->normalizePath($this->file->getPath()));

        foreach ($this->getCanonicalPackages() as $package) {
            $pkgArray = $dumper->dump($package);
            $path = $installationManager->getInstallPath($package);
            $pkgArray['install-path'] = ('' !== $path && null !== $path) ? $fs->findShortestPath($repoDir, $fs->isAbsolutePath($path) ? $path : getcwd() . '/' . $path, true) : null;
            $data['packages'][] = $pkgArray;

            // only write to the files the names which are really installed, as we receive the full list
            // of dev package names before they get installed during composer install
            if (in_array($package->getName(), $this->devPackageNames, true)) {
                $data['dev-package-names'][] = $package->getName();
            }
        }

        sort($data['dev-package-names']);
        usort($data['packages'], function ($a, $b) {
            return strcmp($a['name'], $b['name']);
        });

        $this->file->write($data);

        if ($this->dumpVersions) {
            $versions = array('versions' => array());
            $packages = $this->getPackages();
            $packages[] = $rootPackage = $this->rootPackage;
            while ($rootPackage instanceof AliasPackage) {
                $rootPackage = $rootPackage->getAliasOf();
                $packages[] = $rootPackage;
            }

            // add real installed packages
            foreach ($packages as $package) {
                if ($package instanceof AliasPackage) {
                    continue;
                }

                $reference = null;
                if ($package->getInstallationSource()) {
                    $reference = $package->getInstallationSource() === 'source' ? $package->getSourceReference() : $package->getDistReference();
                }
                if (null === $reference) {
                    $reference = ($package->getSourceReference() ?: $package->getDistReference()) ?: null;
                }

                $versions['versions'][$package->getName()] = array(
                    'pretty_version' => $package->getPrettyVersion(),
                    'version' => $package->getVersion(),
                    'aliases' => array(),
                    'reference' => $reference,
                );
                if ($package instanceof RootPackageInterface) {
                    $versions['root'] = $versions['versions'][$package->getName()];
                    $versions['root']['name'] = $package->getName();
                }
            }

            // add provided/replaced packages
            foreach ($packages as $package) {
                foreach ($package->getReplaces() as $replace) {
                    // exclude platform replaces as when they are really there we can not check for their presence
                    if (PlatformRepository::isPlatformPackage($replace->getTarget())) {
                        continue;
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

            $fs->filePutContentsIfModified($repoDir.'/installed.php', '<?php return '.var_export($versions, true).';'."\n");
            $installedVersionsClass = file_get_contents(__DIR__.'/../InstalledVersions.php');
            $installedVersionsClass = str_replace('private static $installed;', 'private static $installed = '.var_export($versions, true).';', $installedVersionsClass);
            $fs->filePutContentsIfModified($repoDir.'/InstalledVersions.php', $installedVersionsClass);

            // make sure the InstalledVersions class is loaded and has the latest state
            // not using the autoloader here to avoid loading the one from Composer's vendor dir
            if (!class_exists('Composer\InstalledVersions', false)) {
                include $repoDir.'/InstalledVersions.php';
            } else {
                \Composer\InstalledVersions::reload($versions);
            }
        }
    }
}
