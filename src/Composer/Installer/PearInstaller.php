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

namespace Composer\Installer;

use Composer\IO\IOInterface;
use Composer\Composer;
use Composer\Downloader\PearPackageExtractor;
use Composer\Downloader\DownloadManager;
use Composer\Repository\InstalledRepositoryInterface;
use Composer\Package\PackageInterface;
use Composer\Util\Filesystem;

/**
 * Package installation manager.
 *
 * @author Jordi Boggiano <j.boggiano@seld.be>
 * @author Konstantin Kudryashov <ever.zet@gmail.com>
 */
class PearInstaller extends LibraryInstaller
{
    private $filesystem;

    /**
     * Initializes library installer.
     *
     * @param string          $vendorDir relative path for packages home
     * @param string          $binDir    relative path for binaries
     * @param DownloadManager $dm        download manager
     * @param IOInterface     $io        io instance
     * @param string          $type      package type that this installer handles
     */
    public function __construct(IOInterface $io, Composer $composer, $type = 'pear-library')
    {
        $this->filesystem = new Filesystem();
        parent::__construct($io, $composer, $type);
    }

    /**
     * {@inheritDoc}
     */
    public function update(InstalledRepositoryInterface $repo, PackageInterface $initial, PackageInterface $target)
    {
        $this->uninstall($repo, $initial);
        $this->install($repo, $target);
    }

    protected function installCode(PackageInterface $package)
    {
        parent::installCode($package);

        $isWindows = defined('PHP_WINDOWS_VERSION_BUILD') ? true : false;

        $vars = array(
            'os' => $isWindows ? 'windows' : 'linux',
            'php_bin' => ($isWindows ? getenv('PHPRC') .'php.exe' : `which php`),
            'pear_php' => $this->getInstallPath($package),
            'bin_dir' => $this->getInstallPath($package) . '/bin',
            'php_dir' => $this->getInstallPath($package),
            'data_dir' => '@DATA_DIR@',
            'version' => $package->getPrettyVersion(),
        );

        $packageArchive = $this->getInstallPath($package).'/'.pathinfo($package->getDistUrl(), PATHINFO_BASENAME);
        $pearExtractor = new PearPackageExtractor($packageArchive);
        $pearExtractor->extractTo($this->getInstallPath($package), array('php' => '/', 'script' => '/bin'), $vars);

        if ($this->io->isVerbose()) {
            $this->io->write('    Cleaning up');
        }
        unlink($packageArchive);
    }

    protected function getBinaries(PackageInterface $package)
    {
        $binariesPath = $this->getInstallPath($package) . '/bin/';
        $binaries = array();
        if (file_exists($binariesPath)) {
            foreach (new \FilesystemIterator($binariesPath, \FilesystemIterator::KEY_AS_FILENAME) as $fileName => $value) {
                $binaries[] = 'bin/'.$fileName;
            }
        }

        return $binaries;
    }
}
