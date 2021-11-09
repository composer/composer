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
    /** @var string */
    protected $binDir;
    /** @var string */
    protected $binCompat;
    /** @var IOInterface */
    protected $io;
    /** @var Filesystem */
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

    /**
     * @param string $installPath
     * @param bool $warnOnOverwrite
     *
     * @return void
     */
    public function installBinaries(PackageInterface $package, $installPath, $warnOnOverwrite = true)
    {
        $binaries = $this->getBinaries($package);
        if (!$binaries) {
            return;
        }

        Platform::workaroundFilesystemIssues();

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
                if ($warnOnOverwrite) {
                    $this->io->writeError('    Skipped installation of bin '.$bin.' for package '.$package->getName().': name conflicts with an existing file');
                }
                continue;
            }

            if ($this->binCompat === "auto") {
                if (Platform::isWindows() || Platform::isWindowsSubsystemForLinux()) {
                    $this->installFullBinaries($binPath, $link, $bin, $package);
                } else {
                    $this->installSymlinkBinaries($binPath, $link);
                }
            } elseif ($this->binCompat === "full") {
                $this->installFullBinaries($binPath, $link, $bin, $package);
            } elseif ($this->binCompat === "symlink") {
                $this->installSymlinkBinaries($binPath, $link);
            }
            Silencer::call('chmod', $binPath, 0777 & ~umask());
        }
    }

    /**
     * @return void
     */
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
        if (is_dir($this->binDir) && $this->filesystem->isDirEmpty($this->binDir)) {
            Silencer::call('rmdir', $this->binDir);
        }
    }

    /**
     * @param string $bin
     *
     * @return string
     */
    public static function determineBinaryCaller($bin)
    {
        if ('.bat' === substr($bin, -4) || '.exe' === substr($bin, -4)) {
            return 'call';
        }

        $handle = fopen($bin, 'r');
        $line = fgets($handle);
        fclose($handle);
        if (preg_match('{^#!/(?:usr/bin/env )?(?:[^/]+/)*(.+)$}m', $line, $match)) {
            return trim($match[1]);
        }

        return 'php';
    }

    /**
     * @return string[]
     */
    protected function getBinaries(PackageInterface $package)
    {
        return $package->getBinaries();
    }

    /**
     * @param string $binPath
     * @param string $link
     * @param string $bin
     *
     * @return void
     */
    protected function installFullBinaries($binPath, $link, $bin, PackageInterface $package)
    {
        // add unixy support for cygwin and similar environments
        if ('.bat' !== substr($binPath, -4)) {
            $this->installUnixyProxyBinaries($binPath, $link);
            $link .= '.bat';
            if (file_exists($link)) {
                $this->io->writeError('    Skipped installation of bin '.$bin.'.bat proxy for package '.$package->getName().': a .bat proxy was already installed');
            }
        }
        if (!file_exists($link)) {
            file_put_contents($link, $this->generateWindowsProxyCode($binPath, $link));
            Silencer::call('chmod', $link, 0777 & ~umask());
        }
    }

    /**
     * @param string $binPath
     * @param string $link
     *
     * @return void
     */
    protected function installSymlinkBinaries($binPath, $link)
    {
        if (!$this->filesystem->relativeSymlink($binPath, $link)) {
            $this->installUnixyProxyBinaries($binPath, $link);
        }
    }

    /**
     * @param string $binPath
     * @param string $link
     *
     * @return void
     */
    protected function installUnixyProxyBinaries($binPath, $link)
    {
        file_put_contents($link, $this->generateUnixyProxyCode($binPath, $link));
        Silencer::call('chmod', $link, 0777 & ~umask());
    }

    /**
     * @return void
     */
    protected function initializeBinDir()
    {
        $this->filesystem->ensureDirectoryExists($this->binDir);
        $this->binDir = realpath($this->binDir);
    }

    /**
     * @param string $bin
     * @param string $link
     *
     * @return string
     */
    protected function generateWindowsProxyCode($bin, $link)
    {
        $binPath = $this->filesystem->findShortestPath($link, $bin);
        $caller = self::determineBinaryCaller($bin);

        return "@ECHO OFF\r\n".
            "setlocal DISABLEDELAYEDEXPANSION\r\n".
            "SET BIN_TARGET=%~dp0/".trim(ProcessExecutor::escape($binPath), '"\'')."\r\n".
            "{$caller} \"%BIN_TARGET%\" %*\r\n";
    }

    /**
     * @param string $bin
     * @param string $link
     *
     * @return string
     */
    protected function generateUnixyProxyCode($bin, $link)
    {
        $binPath = $this->filesystem->findShortestPath($link, $bin);

        $binDir = ProcessExecutor::escape(dirname($binPath));
        $binFile = basename($binPath);

        $binContents = file_get_contents($bin);
        // For php files, we generate a PHP proxy instead of a shell one,
        // which allows calling the proxy with a custom php process
        if (preg_match('{^(#!.*\r?\n)?<\?php}', $binContents, $match)) {
            // carry over the existing shebang if present, otherwise add our own
            $proxyCode = empty($match[1]) ? '#!/usr/bin/env php' : trim($match[1]);

            $binPathExported = var_export($binPath, true);

            return $proxyCode . "\n" . <<<PROXY
<?php

/**
 * Proxy PHP file generated by Composer
 *
 * This file includes the referenced bin path ($binPath) using ob_start to remove the shebang if present
 * to prevent the shebang from being output on PHP<8
 *
 * @generated
 */

namespace Composer;

\$binPath = __DIR__ . "/" . $binPathExported;

if (PHP_VERSION_ID < 80000) {
    if (!class_exists('Composer\BinProxyWrapper')) {
        /**
         * @internal
         */
        final class BinProxyWrapper
        {
            private \$handle;
            private \$position;

            public function stream_open(\$path, \$mode, \$options, &\$opened_path)
            {
                // get rid of composer-bin-proxy:// prefix for __FILE__ & __DIR__ resolution
                \$opened_path = substr(\$path, 21);
                \$opened_path = realpath(\$opened_path) ?: \$opened_path;
                \$this->handle = fopen(\$opened_path, \$mode);
                \$this->position = 0;

                // remove all traces of this stream wrapper once it has been used
                stream_wrapper_unregister('composer-bin-proxy');

                return (bool) \$this->handle;
            }

            public function stream_read(\$count)
            {
                \$data = fread(\$this->handle, \$count);

                if (\$this->position === 0) {
                    \$data = preg_replace('{^#!.*\\r?\\n}', '', \$data);
                }

                \$this->position += strlen(\$data);

                return \$data;
            }

            public function stream_cast(\$castAs)
            {
                return \$this->handle;
            }

            public function stream_close()
            {
                fclose(\$this->handle);
            }

            public function stream_lock(\$operation)
            {
                return \$operation ? flock(\$this->handle, \$operation) : true;
            }

            public function stream_tell()
            {
                return \$this->position;
            }

            public function stream_eof()
            {
                return feof(\$this->handle);
            }

            public function stream_stat()
            {
                return fstat(\$this->handle);
            }

            public function stream_set_option(\$option, \$arg1, \$arg2)
            {
                return true;
            }
        }
    }

    if (function_exists('stream_wrapper_register') && stream_wrapper_register('composer-bin-proxy', 'Composer\BinProxyWrapper')) {
        include("composer-bin-proxy://" . \$binPath);
        exit(0);
    }
}

include \$binPath;

PROXY;
        }

        return <<<PROXY
#!/usr/bin/env sh

dir=\$(cd "\${0%[/\\\\]*}" > /dev/null; cd $binDir && pwd)

if [ -d /proc/cygdrive ]; then
    case \$(which php) in
        \$(readlink -n /proc/cygdrive)/*)
            # We are in Cygwin using Windows php, so the path must be translated
            dir=\$(cygpath -m "\$dir");
            ;;
    esac
fi

"\${dir}/$binFile" "\$@"

PROXY;
    }
}
