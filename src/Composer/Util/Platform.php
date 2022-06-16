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

namespace Composer\Util;

use Composer\Pcre\Preg;

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
     * getcwd() equivalent which always returns a string
     *
     * @throws \RuntimeException
     */
    public static function getCwd(bool $allowEmpty = false): string
    {
        $cwd = getcwd();

        // fallback to realpath('') just in case this works but odds are it would break as well if we are in a case where getcwd fails
        if (false === $cwd) {
            $cwd = realpath('');
        }

        // crappy state, assume '' and hopefully relative paths allow things to continue
        if (false === $cwd) {
            if ($allowEmpty) {
                return '';
            }

            throw new \RuntimeException('Could not determine the current working directory');
        }

        return $cwd;
    }

    /**
     * getenv() equivalent but reads from the runtime global variables first
     *
     * @param  string $name
     * @return string|false
     */
    public static function getEnv(string $name)
    {
        if (array_key_exists($name, $_SERVER)) {
            return (string) $_SERVER[$name];
        }
        if (array_key_exists($name, $_ENV)) {
            return (string) $_ENV[$name];
        }

        return getenv($name);
    }

    /**
     * putenv() equivalent but updates the runtime global variables too
     *
     * @param  string $name
     * @param  string $value
     * @return void
     */
    public static function putEnv(string $name, string $value): void
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
    public static function clearEnv(string $name): void
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
    public static function expandPath(string $path): string
    {
        if (Preg::isMatch('#^~[\\/]#', $path)) {
            return self::getUserDirectory() . substr($path, 1);
        }

        return Preg::replaceCallback('#^(\$|(?P<percent>%))(?P<var>\w++)(?(percent)%)(?P<path>.*)#', function ($matches): string {
            // Treat HOME as an alias for USERPROFILE on Windows for legacy reasons
            if (Platform::isWindows() && $matches['var'] == 'HOME') {
                return (Platform::getEnv('HOME') ?: Platform::getEnv('USERPROFILE')) . $matches['path'];
            }

            return Platform::getEnv($matches['var']) . $matches['path'];
        }, $path);
    }

    /**
     * @throws \RuntimeException If the user home could not reliably be determined
     * @return string            The formal user home as detected from environment parameters
     */
    public static function getUserDirectory(): string
    {
        if (false !== ($home = self::getEnv('HOME'))) {
            return $home;
        }

        if (self::isWindows() && false !== ($home = self::getEnv('USERPROFILE'))) {
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
    public static function isWindowsSubsystemForLinux(): bool
    {
        if (null === self::$isWindowsSubsystemForLinux) {
            self::$isWindowsSubsystemForLinux = false;

            // while WSL will be hosted within windows, WSL itself cannot be windows based itself.
            if (self::isWindows()) {
                return self::$isWindowsSubsystemForLinux = false;
            }

            if (
                !ini_get('open_basedir')
                && is_readable('/proc/version')
                && false !== stripos(Silencer::call('file_get_contents', '/proc/version'), 'microsoft')
                && !file_exists('/.dockerenv') // docker running inside WSL should not be seen as WSL
            ) {
                return self::$isWindowsSubsystemForLinux = true;
            }
        }

        return self::$isWindowsSubsystemForLinux;
    }

    /**
     * @return bool Whether the host machine is running a Windows OS
     */
    public static function isWindows(): bool
    {
        return \defined('PHP_WINDOWS_VERSION_BUILD');
    }

    /**
     * @param  string $str
     * @return int    return a guaranteed binary length of the string, regardless of silly mbstring configs
     */
    public static function strlen(string $str): int
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

    /**
     * @param  ?resource $fd Open file descriptor or null to default to STDOUT
     * @return bool
     */
    public static function isTty($fd = null): bool
    {
        if ($fd === null) {
            $fd = defined('STDOUT') ? STDOUT : fopen('php://stdout', 'w');
            if ($fd === false) {
                return false;
            }
        }

        // detect msysgit/mingw and assume this is a tty because detection
        // does not work correctly, see https://github.com/composer/composer/issues/9690
        if (in_array(strtoupper(self::getEnv('MSYSTEM') ?: ''), ['MINGW32', 'MINGW64'], true)) {
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

    /**
     * @return bool Whether the current command is for bash completion
     */
    public static function isInputCompletionProcess(): bool
    {
        return '_complete' === ($_SERVER['argv'][1] ?? null);
    }

    /**
     * @return void
     */
    public static function workaroundFilesystemIssues(): void
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
    private static function isVirtualBoxGuest(): bool
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

            if (self::getEnv('COMPOSER_RUNTIME_ENV') === 'virtualbox') {
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

    /**
     * @return 'NUL'|'/dev/null'
     */
    public static function getDevNull(): string
    {
        if (self::isWindows()) {
            return 'NUL';
        }

        return '/dev/null';
    }
}
