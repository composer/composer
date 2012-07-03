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
    /**
     * Initializes library installer.
     *
     * @param IOInterface     $io        io instance
     * @param Composer        $composer
     * @param string          $type      package type that this installer handles
     */
    public function __construct(IOInterface $io, Composer $composer, $type = 'pear-library')
    {
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
            'php_bin' => ($isWindows ? getenv('PHPRC') .'php.exe' : trim(`which php`)),
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

    protected function initializeBinDir()
    {
        parent::initializeBinDir();
        file_put_contents($this->binDir.'/composer-php', $this->generateUnixyPhpProxyCode());
        chmod($this->binDir.'/composer-php', 0777);
        file_put_contents($this->binDir.'/composer-php.bat', $this->generateWindowsPhpProxyCode());
        chmod($this->binDir.'/composer-php.bat', 0777);
    }

    private function generateWindowsPhpProxyCode()
    {
        return
            "@echo off\r\n" .
            "setlocal enabledelayedexpansion\r\n" .
            "set BIN_DIR=%~dp0\r\n" .
            "set VENDOR_DIR=%BIN_DIR%..\\\r\n" .
            "    set DIRS=.\r\n" .
            "FOR /D %%V IN (%VENDOR_DIR%*) DO (\r\n" .
            "    FOR /D %%P IN (%%V\\*) DO (\r\n" .
            "        set DIRS=!DIRS!;%%~fP\r\n" .
            "    )\r\n" .
            ")\r\n" .
            "php.exe -d include_path=!DIRS! %*\r\n";
    }

    private function generateUnixyPhpProxyCode()
    {
        return
            "#!/usr/bin/env sh\n".
            "SRC_DIR=`pwd`\n".
            "BIN_DIR=`dirname $(readlink -f $0)`\n".
            "VENDOR_DIR=`dirname \$BIN_DIR`\n".
            "cd \$BIN_DIR\n".
            "DIRS=\"\"\n".
            "for vendor in \$VENDOR_DIR/*; do\n".
            "    if [ -d \"\$vendor\" ]; then\n".
            "        for package in \$vendor/*; do\n".
            "            if [ -d \"\$package\" ]; then\n".
            "                DIRS=\"\${DIRS}:\${package}\"\n".
            "            fi\n".
            "        done\n".
            "    fi\n".
            "done\n".
            "cd \$SRC_DIR\n".
            "`which php` -d include_path=\".\$DIRS\" $@\n";
    }
}
