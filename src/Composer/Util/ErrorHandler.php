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

use Composer\IO\IOInterface;

/**
 * Convert PHP errors into exceptions
 *
 * @author Artem Lopata <biozshock@gmail.com>
 */
class ErrorHandler
{
    /** @var ?IOInterface */
    private static $io;

    /** @var int<0, 2> */
    private static $hasShownDeprecationNotice = 0;

    /**
     * Error handler
     *
     * @param int    $level   Level of the error raised
     * @param string $message Error message
     * @param string $file    Filename that the error was raised in
     * @param int    $line    Line number the error was raised at
     *
     * @static
     * @throws \ErrorException
     */
    public static function handle(int $level, string $message, string $file, int $line): bool
    {
        $isDeprecationNotice = $level === E_DEPRECATED || $level === E_USER_DEPRECATED;

        // error code is not included in error_reporting
        if (!$isDeprecationNotice && 0 === (error_reporting() & $level)) {
            return true;
        }

        if (filter_var(ini_get('xdebug.scream'), FILTER_VALIDATE_BOOLEAN)) {
            $message .= "\n\nWarning: You have xdebug.scream enabled, the warning above may be".
            "\na legitimately suppressed error that you were not supposed to see.";
        }

        if (!$isDeprecationNotice) {
            // ignore some newly introduced warnings in new php versions until dependencies
            // can be fixed as we do not want to abort execution for those
            if (in_array($level, [E_WARNING, E_USER_WARNING], true) && str_contains($message, 'should either be used or intentionally ignored by casting it as (void)')) {
                self::outputWarning('Ignored new PHP warning but it should be reported and fixed: '.$message.' in '.$file.':'.$line, true);

                return true;
            }

            throw new \ErrorException($message, 0, $level, $file, $line);
        }

        if (self::$io !== null) {
            if (self::$hasShownDeprecationNotice > 0 && !self::$io->isVerbose()) {
                if (self::$hasShownDeprecationNotice === 1) {
                    self::$io->writeError('<warning>More deprecation notices were hidden, run again with `-v` to show them.</warning>');
                    self::$hasShownDeprecationNotice = 2;
                }

                return true;
            }
            self::$hasShownDeprecationNotice = 1;
            self::outputWarning('Deprecation Notice: '.$message.' in '.$file.':'.$line);
        }

        return true;
    }

    /**
     * Register error handler.
     */
    public static function register(?IOInterface $io = null): void
    {
        set_error_handler([__CLASS__, 'handle']);
        error_reporting(E_ALL);
        self::$io = $io;
    }

    private static function outputWarning(string $message, bool $outputEvenWithoutIO = false): void
    {
        if (self::$io !== null) {
            self::$io->writeError('<warning>'.$message.'</warning>');
            if (self::$io->isVerbose()) {
                self::$io->writeError('<warning>Stack trace:</warning>');
                self::$io->writeError(array_filter(array_map(static function ($a): ?string {
                    if (isset($a['line'], $a['file'])) {
                        return '<warning> '.$a['file'].':'.$a['line'].'</warning>';
                    }

                    return null;
                }, array_slice(debug_backtrace(), 2)), static function (?string $line) {
                    return $line !== null;
                }));
            }

            return;
        }

        if ($outputEvenWithoutIO) {
            if (defined('STDERR') && is_resource(STDERR)) {
                fwrite(STDERR, 'Warning: '.$message.PHP_EOL);
            } else {
                echo 'Warning: '.$message.PHP_EOL;
            }
        }
    }
}
