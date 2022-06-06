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

namespace Composer\Installer;

use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Pcre\Preg;
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
    /** @var string|null */
    private $vendorDir;

    /**
     * @param IOInterface $io
     * @param string      $binDir
     * @param string      $binCompat
     * @param Filesystem  $filesystem
     * @param string|null $vendorDir
     */
    public function __construct(IOInterface $io, string $binDir, string $binCompat, Filesystem $filesystem = null, ?string $vendorDir = null)
    {
        $this->binDir = $binDir;
        $this->binCompat = $binCompat;
        $this->io = $io;
        $this->filesystem = $filesystem ?: new Filesystem();
        $this->vendorDir = $vendorDir;
    }

    /**
     * @param string $installPath
     * @param bool $warnOnOverwrite
     *
     * @return void
     */
    public function installBinaries(PackageInterface $package, string $installPath, bool $warnOnOverwrite = true): void
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
            if (is_dir($binPath)) {
                $this->io->writeError('    <warning>Skipped installation of bin '.$bin.' for package '.$package->getName().': found a directory at that path</warning>');
                continue;
            }
            if (!$this->filesystem->isAbsolutePath($binPath)) {
                // in case a custom installer returned a relative path for the
                // $package, we can now safely turn it into a absolute path (as we
                // already checked the binary's existence). The following helpers
                // will require absolute paths to work properly.
                $binPath = realpath($binPath);
            }
            $this->initializeBinDir();
            $link = $this->binDir.'/'.basename($bin);
            if (file_exists($link)) {
                if (!is_link($link)) {
                    if ($warnOnOverwrite) {
                        $this->io->writeError('    Skipped installation of bin '.$bin.' for package '.$package->getName().': name conflicts with an existing file');
                    }
                    continue;
                }
                if (realpath($link) === realpath($binPath)) {
                    // It is a linked binary from a previous installation, which can be replaced with a proxy file
                    $this->filesystem->unlink($link);
                }
            }

            $binCompat = $this->binCompat;
            if ($binCompat === "auto" && (Platform::isWindows() || Platform::isWindowsSubsystemForLinux())) {
                $binCompat = 'full';
            }

            if ($binCompat === "full") {
                $this->installFullBinaries($binPath, $link, $bin, $package);
            } else {
                $this->installUnixyProxyBinaries($binPath, $link);
            }
            Silencer::call('chmod', $binPath, 0777 & ~umask());
        }
    }

    /**
     * @return void
     */
    public function removeBinaries(PackageInterface $package): void
    {
        $this->initializeBinDir();

        $binaries = $this->getBinaries($package);
        if (!$binaries) {
            return;
        }
        foreach ($binaries as $bin) {
            $link = $this->binDir.'/'.basename($bin);
            if (is_link($link) || file_exists($link)) { // still checking for symlinks here for legacy support
                $this->filesystem->unlink($link);
            }
            if (is_file($link.'.bat')) {
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
    public static function determineBinaryCaller(string $bin): string
    {
        if ('.bat' === substr($bin, -4) || '.exe' === substr($bin, -4)) {
            return 'call';
        }

        $handle = fopen($bin, 'r');
        $line = fgets($handle);
        fclose($handle);
        if (Preg::isMatch('{^#!/(?:usr/bin/env )?(?:[^/]+/)*(.+)$}m', $line, $match)) {
            return trim($match[1]);
        }

        return 'php';
    }

    /**
     * @return string[]
     */
    protected function getBinaries(PackageInterface $package): array
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
    protected function installFullBinaries(string $binPath, string $link, string $bin, PackageInterface $package): void
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
    protected function installUnixyProxyBinaries(string $binPath, string $link): void
    {
        file_put_contents($link, $this->generateUnixyProxyCode($binPath, $link));
        Silencer::call('chmod', $link, 0777 & ~umask());
    }

    /**
     * @return void
     */
    protected function initializeBinDir(): void
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
    protected function generateWindowsProxyCode(string $bin, string $link): string
    {
        $binPath = $this->filesystem->findShortestPath($link, $bin);
        $caller = self::determineBinaryCaller($bin);

        // if the target is a php file, we run the unixy proxy file
        // to ensure that _composer_autoload_path gets defined, instead
        // of running the binary directly
        if ($caller === 'php') {
            return "@ECHO OFF\r\n".
                "setlocal DISABLEDELAYEDEXPANSION\r\n".
                "SET BIN_TARGET=%~dp0/".trim(ProcessExecutor::escape(basename($link, '.bat')), '"\'')."\r\n".
                "SET COMPOSER_RUNTIME_BIN_DIR=%~dp0\r\n".
                "{$caller} \"%BIN_TARGET%\" %*\r\n";
        }

        return "@ECHO OFF\r\n".
            "setlocal DISABLEDELAYEDEXPANSION\r\n".
            "SET BIN_TARGET=%~dp0/".trim(ProcessExecutor::escape($binPath), '"\'')."\r\n".
            "SET COMPOSER_RUNTIME_BIN_DIR=%~dp0\r\n".
            "{$caller} \"%BIN_TARGET%\" %*\r\n";
    }

    /**
     * @param string $bin
     * @param string $link
     *
     * @return string
     */
    protected function generateUnixyProxyCode(string $bin, string $link): string
    {
        $binPath = $this->filesystem->findShortestPath($link, $bin);

        $binDir = ProcessExecutor::escape(dirname($binPath));
        $binFile = basename($binPath);

        $binContents = file_get_contents($bin);
        // For php files, we generate a PHP proxy instead of a shell one,
        // which allows calling the proxy with a custom php process
        if (Preg::isMatch('{^(#!.*\r?\n)?[\r\n\t ]*<\?php}', $binContents, $match)) {
            // carry over the existing shebang if present, otherwise add our own
            $proxyCode = empty($match[1]) ? '#!/usr/bin/env php' : trim($match[1]);
            $binPathExported = $this->filesystem->findShortestPathCode($link, $bin, false, true);
            $streamProxyCode = $streamHint = '';
            $globalsCode = '$GLOBALS[\'_composer_bin_dir\'] = __DIR__;'."\n";
            $phpunitHack1 = $phpunitHack2 = '';
            // Don't expose autoload path when vendor dir was not set in custom installers
            if ($this->vendorDir) {
                $globalsCode .= '$GLOBALS[\'_composer_autoload_path\'] = ' . $this->filesystem->findShortestPathCode($link, $this->vendorDir . '/autoload.php', false, true).";\n";
            }
            // Add workaround for PHPUnit process isolation
            if ($this->filesystem->normalizePath($bin) === $this->filesystem->normalizePath($this->vendorDir.'/phpunit/phpunit/phpunit')) {
                // workaround issue on PHPUnit 6.5+ running on PHP 8+
                $globalsCode .= '$GLOBALS[\'__PHPUNIT_ISOLATION_EXCLUDE_LIST\'] = $GLOBALS[\'__PHPUNIT_ISOLATION_BLACKLIST\'] = array(realpath('.$binPathExported.'));'."\n";
                // workaround issue on all PHPUnit versions running on PHP <8
                $phpunitHack1 = "'phpvfscomposer://'.";
                $phpunitHack2 = '
                $data = str_replace(\'__DIR__\', var_export(dirname($this->realpath), true), $data);
                $data = str_replace(\'__FILE__\', var_export($this->realpath, true), $data);';
            }
            if (trim($match[0]) !== '<?php') {
                $streamHint = ' using a stream wrapper to prevent the shebang from being output on PHP<8'."\n *";
                $streamProxyCode = <<<STREAMPROXY
if (PHP_VERSION_ID < 80000) {
    if (!class_exists('Composer\BinProxyWrapper')) {
        /**
         * @internal
         */
        final class BinProxyWrapper
        {
            private \$handle;
            private \$position;
            private \$realpath;

            public function stream_open(\$path, \$mode, \$options, &\$opened_path)
            {
                // get rid of phpvfscomposer:// prefix for __FILE__ & __DIR__ resolution
                \$opened_path = substr(\$path, 17);
                \$this->realpath = realpath(\$opened_path) ?: \$opened_path;
                \$opened_path = $phpunitHack1\$this->realpath;
                \$this->handle = fopen(\$this->realpath, \$mode);
                \$this->position = 0;

                return (bool) \$this->handle;
            }

            public function stream_read(\$count)
            {
                \$data = fread(\$this->handle, \$count);

                if (\$this->position === 0) {
                    \$data = preg_replace('{^#!.*\\r?\\n}', '', \$data);
                }$phpunitHack2

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

            public function stream_seek(\$offset, \$whence)
            {
                if (0 === fseek(\$this->handle, \$offset, \$whence)) {
                    \$this->position = ftell(\$this->handle);
                    return true;
                }

                return false;
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
                return array();
            }

            public function stream_set_option(\$option, \$arg1, \$arg2)
            {
                return true;
            }

            public function url_stat(\$path, \$flags)
            {
                \$path = substr(\$path, 17);
                if (file_exists(\$path)) {
                    return stat(\$path);
                }

                return false;
            }
        }
    }

    if (
        (function_exists('stream_get_wrappers') && in_array('phpvfscomposer', stream_get_wrappers(), true))
        || (function_exists('stream_wrapper_register') && stream_wrapper_register('phpvfscomposer', 'Composer\BinProxyWrapper'))
    ) {
        include("phpvfscomposer://" . $binPathExported);
        exit(0);
    }
}

STREAMPROXY;
            }

            return $proxyCode . "\n" . <<<PROXY
<?php

/**
 * Proxy PHP file generated by Composer
 *
 * This file includes the referenced bin path ($binPath)
 *$streamHint
 * @generated
 */

namespace Composer;

$globalsCode
$streamProxyCode
include $binPathExported;

PROXY;
        }

        return <<<PROXY
#!/usr/bin/env sh

# Support bash to support `source` with fallback on $0 if this does not run with bash
# https://stackoverflow.com/a/35006505/6512
selfArg="\$BASH_SOURCE"
if [ -z "\$selfArg" ]; then
    selfArg="\$0"
fi

self=\$(realpath \$selfArg 2> /dev/null)
if [ -z "\$self" ]; then
    self="\$selfArg"
fi

dir=\$(cd "\${self%[/\\\\]*}" > /dev/null; cd $binDir && pwd)

if [ -d /proc/cygdrive ]; then
    case \$(which php) in
        \$(readlink -n /proc/cygdrive)/*)
            # We are in Cygwin using Windows php, so the path must be translated
            dir=\$(cygpath -m "\$dir");
            ;;
    esac
fi

export COMPOSER_RUNTIME_BIN_DIR=\$(cd "\${self%[/\\\\]*}" > /dev/null; pwd)

# If bash is sourcing this file, we have to source the target as well
bashSource="\$BASH_SOURCE"
if [ -n "\$bashSource" ]; then
    if [ "\$bashSource" != "\$0" ]; then
        source "\${dir}/$binFile" "\$@"
        return
    fi
fi

"\${dir}/$binFile" "\$@"

PROXY;
    }
}
