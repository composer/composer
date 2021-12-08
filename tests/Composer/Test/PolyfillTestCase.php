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

namespace Composer\Test {
    use PHPUnit\Framework\TestCase;

    if (method_exists('PHPUnit\Framework\TestCase', 'assertFileDoesNotExist')) {
        abstract class PolyfillTestCase extends TestCase
        {
        }
    } else {
        abstract class PolyfillTestCase extends TestCase
        {
            // all the functions below are form https://github.com/symfony/phpunit-bridge/blob/bd341a45ef79b30918376e8b8e2279fac6894c3b/Legacy/PolyfillAssertTrait.php
            /**
             * @param string $filename
             * @param string $message
             *
             * @return void
             */
            public static function assertIsNotReadable($filename, $message = '')
            {
                static::assertNotIsReadable($filename, $message);
            }

            /**
             * @param string $filename
             * @param string $message
             *
             * @return void
             */
            public static function assertIsNotWritable($filename, $message = '')
            {
                static::assertNotIsWritable($filename, $message);
            }

            /**
             * @param string $directory
             * @param string $message
             *
             * @return void
             */
            public static function assertDirectoryDoesNotExist($directory, $message = '')
            {
                static::assertDirectoryNotExists($directory, $message);
            }

            /**
             * @param string $directory
             * @param string $message
             *
             * @return void
             */
            public static function assertDirectoryIsNotReadable($directory, $message = '')
            {
                static::assertDirectoryNotIsReadable($directory, $message);
            }

            /**
             * @param string $directory
             * @param string $message
             *
             * @return void
             */
            public static function assertDirectoryIsNotWritable($directory, $message = '')
            {
                static::assertDirectoryNotIsWritable($directory, $message);
            }

            /**
             * @param string $filename
             * @param string $message
             *
             * @return void
             */
            public static function assertFileDoesNotExist($filename, $message = '')
            {
                static::assertFileNotExists($filename, $message);
            }

            /**
             * @param string $filename
             * @param string $message
             *
             * @return void
             */
            public static function assertFileIsNotReadable($filename, $message = '')
            {
                static::assertFileNotIsReadable($filename, $message);
            }

            /**
             * @param string $filename
             * @param string $message
             *
             * @return void
             */
            public static function assertFileIsNotWritable($filename, $message = '')
            {
                static::assertFileNotIsWritable($filename, $message);
            }

            /**
             * @param string $pattern
             * @param string $string
             * @param string $message
             *
             * @return void
             */
            public static function assertMatchesRegularExpression($pattern, $string, $message = '')
            {
                static::assertRegExp($pattern, $string, $message);
            }

            /**
             * @param string $pattern
             * @param string $string
             * @param string $message
             *
             * @return void
             */
            public static function assertDoesNotMatchRegularExpression($pattern, $string, $message = '')
            {
                static::assertNotRegExp($pattern, $string, $message);
            }
        }
    }
}
