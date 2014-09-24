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
use Composer\Repository\InstalledRepositoryInterface;
use Composer\Package\PackageInterface;
use Composer\Util\ProcessExecutor;

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
     * @param IOInterface $io       io instance
     * @param Composer    $composer
     * @param string      $type     package type that this installer handles
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
        parent::initializeBinDir();

        $isWindows = defined('PHP_WINDOWS_VERSION_BUILD');
        $php_bin = $this->binDir . ($isWindows ? '/composer-php.bat' : '/composer-php');

        if (!$isWindows) {
            $php_bin = '/usr/bin/env ' . $php_bin;
        }

        $installPath = $this->getInstallPath($package);
        $vars = array(
            'os' => $isWindows ? 'windows' : 'linux',
            'php_bin' => $php_bin,
            'pear_php' => $installPath,
            'php_dir' => $installPath,
            'bin_dir' => $installPath . '/bin',
            'data_dir' => $installPath . '/data',
            'version' => $package->getPrettyVersion(),
        );

        $packageArchive = $this->getInstallPath($package).'/'.pathinfo($package->getDistUrl(), PATHINFO_BASENAME);
        $pearExtractor = new PearPackageExtractor($packageArchive);
        $pearExtractor->extractTo($this->getInstallPath($package), array('php' => '/', 'script' => '/bin', 'data' => '/data'), $vars);

        if ($this->io->isVerbose()) {
            $this->io->write('    Cleaning up');
        }
        $this->filesystem->unlink($packageArchive);
    }

    protected function getBinaries(PackageInterface $package)
    {
        $binariesPath = $this->getInstallPath($package) . '/bin/';
        $binaries = array();
        if (file_exists($binariesPath)) {
            foreach (new \FilesystemIterator($binariesPath, \FilesystemIterator::KEY_AS_FILENAME | \FilesystemIterator::CURRENT_AS_FILEINFO) as $fileName => $value) {
                if (!$value->isDir()) {
                    $binaries[] = 'bin/'.$fileName;
                }
            }
        }

        return $binaries;
    }

    protected function initializeBinDir()
    {
        parent::initializeBinDir();
        file_put_contents($this->binDir.'/composer-php', $this->generateUnixyPhpProxyCode());
        @chmod($this->binDir.'/composer-php', 0777);
        file_put_contents($this->binDir.'/composer-php.bat', $this->generateWindowsPhpProxyCode());
        @chmod($this->binDir.'/composer-php.bat', 0777);
    }

    protected function generateWindowsProxyCode($bin, $link)
    {
        $binPath = $this->filesystem->findShortestPath($link, $bin);
        if ('.bat' === substr($bin, -4)) {
            $caller = 'call';
        } else {
            $handle = fopen($bin, 'r');
            $line = fgets($handle);
            fclose($handle);
            if (preg_match('{^#!/(?:usr/bin/env )?(?:[^/]+/)*(.+)$}m', $line, $match)) {
                $caller = trim($match[1]);
            } else {
                $caller = 'php';
            }

            if ($caller === 'php') {
                return "@echo off\r\n".
                    "pushd .\r\n".
                    "cd %~dp0\r\n".
                    "set PHP_PROXY=%CD%\\composer-php.bat\r\n".
                    "cd ".ProcessExecutor::escape(dirname($binPath))."\r\n".
                    "set BIN_TARGET=%CD%\\".basename($binPath)."\r\n".
                    "popd\r\n".
                    "%PHP_PROXY% \"%BIN_TARGET%\" %*\r\n";
            }
        }

        return "@echo off\r\n".
            "pushd .\r\n".
            "cd %~dp0\r\n".
            "cd ".ProcessExecutor::escape(dirname($binPath))."\r\n".
            "set BIN_TARGET=%CD%\\".basename($binPath)."\r\n".
            "popd\r\n".
            $caller." \"%BIN_TARGET%\" %*\r\n";
    }

    private function generateWindowsPhpProxyCode()
    {
        $binToVendor = $this->filesystem->findShortestPath($this->binDir, $this->vendorDir, true);

        return
            "@echo off\r\n" .
            "setlocal enabledelayedexpansion\r\n" .
            "set BIN_DIR=%~dp0\r\n" .
            "set VENDOR_DIR=%BIN_DIR%\\".$binToVendor."\r\n" .
            "set DIRS=.\r\n" .
            "FOR /D %%V IN (%VENDOR_DIR%\\*) DO (\r\n" .
            "    FOR /D %%P IN (%%V\\*) DO (\r\n" .
            "        set DIRS=!DIRS!;%%~fP\r\n" .
            "    )\r\n" .
            ")\r\n" .
            "php.exe -d include_path=!DIRS! %*\r\n";
    }

    private function generateUnixyPhpProxyCode()
    {
        $binToVendor = $this->filesystem->findShortestPath($this->binDir, $this->vendorDir, true);

        return
            "#!/usr/bin/env sh\n".
            "SRC_DIR=`pwd`\n".
            "BIN_DIR=`dirname $0`\n".
            "VENDOR_DIR=\$BIN_DIR/".escapeshellarg($binToVendor)."\n".
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
            "php -d include_path=\".\$DIRS\" $@\n";
    }
}
