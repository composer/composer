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
use Composer\Package\PackageInterface;
use Composer\Util\Filesystem;
use Composer\Util\Platform;
use Composer\Util\ProcessExecutor;
use Composer\Util\Silencer;

/**
 * Utility to handle installation of package "bin"/binaries
 *
 * @author Jordi Boggiano <j.boggiano@seld.be>
 * @author Konstantin Kudryashov <ever.zet@gmail.com>
 * @author Helmut Hummel <info@helhum.io>
 */
class BinaryInstaller
{
    protected $binDir;
    protected $binCompat;
    protected $io;
    protected $filesystem;

    /**
     * @param IOInterface $io
     * @param string      $binDir
     * @param string      $binCompat
     * @param Filesystem  $filesystem
     */
    public function __construct(IOInterface $io, $binDir, $binCompat, Filesystem $filesystem = null)
    {
        $this->binDir = $binDir;
        $this->binCompat = $binCompat;
        $this->io = $io;
        $this->filesystem = $filesystem ?: new Filesystem();
    }

    public function installBinaries(PackageInterface $package, $installPath)
    {
        $binaries = $this->getBinaries($package);
        if (!$binaries) {
            return;
        }
        foreach ($binaries as $bin) {
            $binPath = $installPath.'/'.$bin;
            if (!file_exists($binPath)) {
                $this->io->writeError('    <warning>Skipped installation of bin '.$bin.' for package '.$package->getName().': file not found in package</warning>');
                continue;
            }

            // in case a custom installer returned a relative path for the
            // $package, we can now safely turn it into a absolute path (as we
            // already checked the binary's existence). The following helpers
            // will require absolute paths to work properly.
            $binPath = realpath($binPath);

            $this->initializeBinDir();
            $link = $this->binDir.'/'.basename($bin);
            if (file_exists($link)) {
                if (is_link($link)) {
                    // likely leftover from a previous install, make sure
                    // that the target is still executable in case this
                    // is a fresh install of the vendor.
                    Silencer::call('chmod', $link, 0777 & ~umask());
                }
                $this->io->writeError('    Skipped installation of bin '.$bin.' for package '.$package->getName().': name conflicts with an existing file');
                continue;
            }

            if ($this->binCompat === "auto") {
                if (Platform::isWindows()) {
                    $this->installFullBinaries($binPath, $link, $bin, $package);
                } else {
                    $this->installSymlinkBinaries($binPath, $link);
                }
            } elseif ($this->binCompat === "full") {
                $this->installFullBinaries($binPath, $link, $bin, $package);
            }
            Silencer::call('chmod', $link, 0777 & ~umask());
        }
    }

    public function removeBinaries(PackageInterface $package)
    {
        $this->initializeBinDir();

        $binaries = $this->getBinaries($package);
        if (!$binaries) {
            return;
        }
        foreach ($binaries as $bin) {
            $link = $this->binDir.'/'.basename($bin);
            if (is_link($link) || file_exists($link)) {
                $this->filesystem->unlink($link);
            }
            if (file_exists($link.'.bat')) {
                $this->filesystem->unlink($link.'.bat');
            }
        }

        // attempt removing the bin dir in case it is left empty
        if ((is_dir($this->binDir)) && ($this->filesystem->isDirEmpty($this->binDir))) {
            Silencer::call('rmdir', $this->binDir);
        }
    }

    protected function getBinaries(PackageInterface $package)
    {
        return $package->getBinaries();
    }

    protected function installFullBinaries($binPath, $link, $bin, PackageInterface $package)
    {
        // add unixy support for cygwin and similar environments
        if ('.bat' !== substr($binPath, -4)) {
            $this->installUnixyProxyBinaries($binPath, $link);
            @chmod($link, 0777 & ~umask());
            $link .= '.bat';
            if (file_exists($link)) {
                $this->io->writeError('    Skipped installation of bin '.$bin.'.bat proxy for package '.$package->getName().': a .bat proxy was already installed');
            }
        }
        if (!file_exists($link)) {
            file_put_contents($link, $this->generateWindowsProxyCode($binPath, $link));
        }
    }

    protected function installSymlinkBinaries($binPath, $link)
    {
        if (!$this->filesystem->relativeSymlink($binPath, $link)) {
            $this->installUnixyProxyBinaries($binPath, $link);
        }
    }

    protected function installUnixyProxyBinaries($binPath, $link)
    {
        file_put_contents($link, $this->generateUnixyProxyCode($binPath, $link));
    }

    protected function initializeBinDir()
    {
        $this->filesystem->ensureDirectoryExists($this->binDir);
        $this->binDir = realpath($this->binDir);
    }

    protected function generateWindowsProxyCode($bin, $link)
    {
        $binPath = $this->filesystem->findShortestPath($link, $bin);
        if ('.bat' === substr($bin, -4) || '.exe' === substr($bin, -4)) {
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
        }

        return "@ECHO OFF\r\n".
            "setlocal DISABLEDELAYEDEXPANSION\r\n".
            "SET BIN_TARGET=%~dp0/".trim(ProcessExecutor::escape($binPath), '"\'')."\r\n".
            "{$caller} \"%BIN_TARGET%\" %*\r\n";
    }

    protected function generateUnixyProxyCode($bin, $link)
    {
        $binPath = $this->filesystem->findShortestPath($link, $bin);

        $binDir = ProcessExecutor::escape(dirname($binPath));
        $binFile = basename($binPath);

        $proxyCode = <<<PROXY
#!/usr/bin/env sh

dir=$(d=\${0%[/\\\\]*}; cd "\$d"; cd $binDir && pwd)

# See if we are running in Cygwin by checking for cygpath program
if command -v 'cygpath' >/dev/null 2>&1; then
	# Cygwin paths start with /cygdrive/ which will break windows PHP,
	# so we need to translate the dir path to windows format. However
	# we could be using cygwin PHP which does not require this, so we
	# test if the path to PHP starts with /cygdrive/ rather than /usr/bin
	if [[ $(which php) == /cygdrive/* ]]; then
		dir=$(cygpath -m "\$dir");
	fi
fi

dir=$(echo \$dir | sed 's/ /\ /g')
"\${dir}/$binFile" "$@"

PROXY;

        return $proxyCode;
    }
}
