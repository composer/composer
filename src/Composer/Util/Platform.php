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

namespace Composer\Util;

/**
 * Platform helper for uniform platform-specific tests.
 *
 * @author Niels Keurentjes <niels.keurentjes@omines.com>
 */
class Platform
{
    /** @var ?bool */
    private static $isVirtualBoxGuest = null;
    /** @var ?bool */
    private static $isWindowsSubsystemForLinux = null;

    /**
     * putenv() equivalent but updates the runtime global variables too
     *
     * @param  string $name
     * @param  string $value
     * @return void
     */
    public static function putEnv($name, $value)
    {
        $value = (string) $value;
        putenv($name . '=' . $value);
        $_SERVER[$name] = $_ENV[$name] = $value;
    }

    /**
     * putenv('X') equivalent but updates the runtime global variables too
     *
     * @param  string $name
     * @return void
     */
    public static function clearEnv($name)
    {
        putenv($name);
        unset($_SERVER[$name], $_ENV[$name]);
    }

    /**
     * Parses tildes and environment variables in paths.
     *
     * @param  string $path
     * @return string
     */
    public static function expandPath($path)
    {
        if (preg_match('#^~[\\/]#', $path)) {
            return self::getUserDirectory() . substr($path, 1);
        }

        return preg_replace_callback('#^(\$|(?P<percent>%))(?P<var>\w++)(?(percent)%)(?P<path>.*)#', function ($matches) {
            // Treat HOME as an alias for USERPROFILE on Windows for legacy reasons
            if (Platform::isWindows() && $matches['var'] == 'HOME') {
                return (getenv('HOME') ?: getenv('USERPROFILE')) . $matches['path'];
            }

            return getenv($matches['var']) . $matches['path'];
        }, $path);
    }

    /**
     * @throws \RuntimeException If the user home could not reliably be determined
     * @return string            The formal user home as detected from environment parameters
     */
    public static function getUserDirectory()
    {
        if (false !== ($home = getenv('HOME'))) {
            return $home;
        }

        if (self::isWindows() && false !== ($home = getenv('USERPROFILE'))) {
            return $home;
        }

        if (\function_exists('posix_getuid') && \function_exists('posix_getpwuid')) {
            $info = posix_getpwuid(posix_getuid());

            return $info['dir'];
        }

        throw new \RuntimeException('Could not determine user directory');
    }

    /**
     * @return bool Whether the host machine is running on the Windows Subsystem for Linux (WSL)
     */
    public static function isWindowsSubsystemForLinux()
    {
        if (null === self::$isWindowsSubsystemForLinux) {
            self::$isWindowsSubsystemForLinux = false;

            // while WSL will be hosted within windows, WSL itself cannot be windows based itself.
            if (self::isWindows()) {
                return self::$isWindowsSubsystemForLinux = false;
            }

            if (is_readable('/proc/version') && false !== stripos(file_get_contents('/proc/version'), 'microsoft')) {
                return self::$isWindowsSubsystemForLinux = true;
            }
        }

        return self::$isWindowsSubsystemForLinux;
    }

    /**
     * @return bool Whether the host machine is running a Windows OS
     */
    public static function isWindows()
    {
        return \defined('PHP_WINDOWS_VERSION_BUILD');
    }

    /**
     * @param  string $str
     * @return int    return a guaranteed binary length of the string, regardless of silly mbstring configs
     */
    public static function strlen($str)
    {
        static $useMbString = null;
        if (null === $useMbString) {
            $useMbString = \function_exists('mb_strlen') && ini_get('mbstring.func_overload');
        }

        if ($useMbString) {
            return mb_strlen($str, '8bit');
        }

        return \strlen($str);
    }

    public static function isTty($fd = null)
    {
        if ($fd === null) {
            $fd = defined('STDOUT') ? STDOUT : fopen('php://stdout', 'w');
        }

        // detect msysgit/mingw and assume this is a tty because detection
        // does not work correctly, see https://github.com/composer/composer/issues/9690
        if (in_array(strtoupper(getenv('MSYSTEM') ?: ''), array('MINGW32', 'MINGW64'), true)) {
            return true;
        }

        // modern cross-platform function, includes the fstat
        // fallback so if it is present we trust it
        if (function_exists('stream_isatty')) {
            return stream_isatty($fd);
        }

        // only trusting this if it is positive, otherwise prefer fstat fallback
        if (function_exists('posix_isatty') && posix_isatty($fd)) {
            return true;
        }

        $stat = @fstat($fd);
        // Check if formatted mode is S_IFCHR
        return $stat ? 0020000 === ($stat['mode'] & 0170000) : false;
    }

    public static function workaroundFilesystemIssues()
    {
        if (self::isVirtualBoxGuest()) {
            usleep(200000);
        }
    }

    /**
     * Attempts detection of VirtualBox guest VMs
     *
     * This works based on the process' user being "vagrant", the COMPOSER_RUNTIME_ENV env var being set to "virtualbox", or lsmod showing the virtualbox guest additions are loaded
     *
     * @return bool
     */
    private static function isVirtualBoxGuest()
    {
        if (null === self::$isVirtualBoxGuest) {
            self::$isVirtualBoxGuest = false;
            if (self::isWindows()) {
                return self::$isVirtualBoxGuest;
            }

            if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
                $processUser = posix_getpwuid(posix_geteuid());
                if ($processUser && $processUser['name'] === 'vagrant') {
                    return self::$isVirtualBoxGuest = true;
                }
            }

            if (getenv('COMPOSER_RUNTIME_ENV') === 'virtualbox') {
                return self::$isVirtualBoxGuest = true;
            }

            if (defined('PHP_OS_FAMILY') && PHP_OS_FAMILY === 'Linux') {
                $process = new ProcessExecutor();
                try {
                    if (0 === $process->execute('lsmod | grep vboxguest', $ignoredOutput)) {
                        return self::$isVirtualBoxGuest = true;
                    }
                } catch (\Exception $e) {
                    // noop
                }
            }
        }

        return self::$isVirtualBoxGuest;
    }
}
