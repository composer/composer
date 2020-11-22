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
    use PHPUnit\Framework\Constraint\LogicalNot;
    use PHPUnit\Framework\Constraint\StringContains;

    if (method_exists('PHPUnit\Framework\TestCase', 'assertStringContainsString')) {
        abstract class PolyfillTestCase extends TestCase
        {
        }
    } else {
        abstract class PolyfillTestCase extends TestCase
        {
            // all the functions below are form https://github.com/symfony/phpunit-bridge/blob/bd341a45ef79b30918376e8b8e2279fac6894c3b/Legacy/PolyfillAssertTrait.php

            /**
             * @param string $message
             *
             * @return void
             */
            public static function assertIsInt($actual, $message = '')
            {
                static::assertInternalType('int', $actual, $message);
            }

            /**
             * @param string $message
             *
             * @return void
             */
            public static function assertIsNumeric($actual, $message = '')
            {
                static::assertInternalType('numeric', $actual, $message);
            }

            /**
             * @param string $message
             *
             * @return void
             */
            public static function assertIsObject($actual, $message = '')
            {
                static::assertInternalType('object', $actual, $message);
            }

            /**
             * @param string $message
             *
             * @return void
             */
            public static function assertIsResource($actual, $message = '')
            {
                static::assertInternalType('resource', $actual, $message);
            }

            /**
             * @param string $message
             *
             * @return void
             */
            public static function assertIsString($actual, $message = '')
            {
                static::assertInternalType('string', $actual, $message);
            }

            /**
             * @param string $message
             *
             * @return void
             */
            public static function assertIsScalar($actual, $message = '')
            {
                static::assertInternalType('scalar', $actual, $message);
            }

            /**
             * @param string $message
             *
             * @return void
             */
            public static function assertIsCallable($actual, $message = '')
            {
                static::assertInternalType('callable', $actual, $message);
            }

            /**
             * @param string $message
             *
             * @return void
             */
            public static function assertIsIterable($actual, $message = '')
            {
                static::assertInternalType('iterable', $actual, $message);
            }

            /**
             * @param string $needle
             * @param string $haystack
             * @param string $message
             *
             * @return void
             */
            public static function assertStringContainsString($needle, $haystack, $message = '')
            {
                $constraint = new StringContains($needle, false);
                static::assertThat($haystack, $constraint, $message);
            }

            /**
             * @param string $needle
             * @param string $haystack
             * @param string $message
             *
             * @return void
             */
            public static function assertStringContainsStringIgnoringCase($needle, $haystack, $message = '')
            {
                $constraint = new StringContains($needle, true);
                static::assertThat($haystack, $constraint, $message);
            }

            /**
             * @param string $needle
             * @param string $haystack
             * @param string $message
             *
             * @return void
             */
            public static function assertStringNotContainsString($needle, $haystack, $message = '')
            {
                $constraint = new LogicalNot(new StringContains($needle, false));
                static::assertThat($haystack, $constraint, $message);
            }

            /**
             * @param string $needle
             * @param string $haystack
             * @param string $message
             *
             * @return void
             */
            public static function assertStringNotContainsStringIgnoringCase($needle, $haystack, $message = '')
            {
                $constraint = new LogicalNot(new StringContains($needle, true));
                static::assertThat($haystack, $constraint, $message);
            }

            /**
             * @param string $message
             *
             * @return void
             */
            public static function assertFinite($actual, $message = '')
            {
                static::assertInternalType('float', $actual, $message);
                static::assertTrue(is_finite($actual), $message ? $message : "Failed asserting that $actual is finite.");
            }

            /**
             * @param string $message
             *
             * @return void
             */
            public static function assertInfinite($actual, $message = '')
            {
                static::assertInternalType('float', $actual, $message);
                static::assertTrue(is_infinite($actual), $message ? $message : "Failed asserting that $actual is infinite.");
            }

            /**
             * @param string $message
             *
             * @return void
             */
            public static function assertNan($actual, $message = '')
            {
                static::assertInternalType('float', $actual, $message);
                static::assertTrue(is_nan($actual), $message ? $message : "Failed asserting that $actual is nan.");
            }

            /**
             * @param string $filename
             * @param string $message
             *
             * @return void
             */
            public static function assertIsReadable($filename, $message = '')
            {
                static::assertInternalType('string', $filename, $message);
                static::assertTrue(is_readable($filename), $message ? $message : "Failed asserting that $filename is readable.");
            }

            /**
             * @param string $filename
             * @param string $message
             *
             * @return void
             */
            public static function assertNotIsReadable($filename, $message = '')
            {
                static::assertInternalType('string', $filename, $message);
                static::assertFalse(is_readable($filename), $message ? $message : "Failed asserting that $filename is not readable.");
            }

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
            public static function assertIsWritable($filename, $message = '')
            {
                static::assertInternalType('string', $filename, $message);
                static::assertTrue(is_writable($filename), $message ? $message : "Failed asserting that $filename is writable.");
            }

            /**
             * @param string $filename
             * @param string $message
             *
             * @return void
             */
            public static function assertNotIsWritable($filename, $message = '')
            {
                static::assertInternalType('string', $filename, $message);
                static::assertFalse(is_writable($filename), $message ? $message : "Failed asserting that $filename is not writable.");
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
            public static function assertDirectoryExists($directory, $message = '')
            {
                static::assertInternalType('string', $directory, $message);
                static::assertTrue(is_dir($directory), $message ? $message : "Failed asserting that $directory exists.");
            }

            /**
             * @param string $directory
             * @param string $message
             *
             * @return void
             */
            public static function assertDirectoryNotExists($directory, $message = '')
            {
                static::assertInternalType('string', $directory, $message);
                static::assertFalse(is_dir($directory), $message ? $message : "Failed asserting that $directory does not exist.");
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
            public static function assertDirectoryIsReadable($directory, $message = '')
            {
                static::assertDirectoryExists($directory, $message);
                static::assertIsReadable($directory, $message);
            }

            /**
             * @param string $directory
             * @param string $message
             *
             * @return void
             */
            public static function assertDirectoryNotIsReadable($directory, $message = '')
            {
                static::assertDirectoryExists($directory, $message);
                static::assertNotIsReadable($directory, $message);
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
            public static function assertDirectoryIsWritable($directory, $message = '')
            {
                static::assertDirectoryExists($directory, $message);
                static::assertIsWritable($directory, $message);
            }

            /**
             * @param string $directory
             * @param string $message
             *
             * @return void
             */
            public static function assertDirectoryNotIsWritable($directory, $message = '')
            {
                static::assertDirectoryExists($directory, $message);
                static::assertNotIsWritable($directory, $message);
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
            public static function assertFileExists($filename, $message = '')
            {
                static::assertInternalType('string', $filename, $message);
                static::assertTrue(file_exists($filename), $message ? $message : "Failed asserting that $filename exists.");
            }

            /**
             * @param string $filename
             * @param string $message
             *
             * @return void
             */
            public static function assertFileNotExists($filename, $message = '')
            {
                static::assertInternalType('string', $filename, $message);
                static::assertFalse(file_exists($filename), $message ? $message : "Failed asserting that $filename does not exist.");
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
            public static function assertFileIsReadable($filename, $message = '')
            {
                static::assertFileExists($filename, $message);
                static::assertIsReadable($filename, $message);
            }

            /**
             * @param string $filename
             * @param string $message
             *
             * @return void
             */
            public static function assertFileNotIsReadable($filename, $message = '')
            {
                static::assertFileExists($filename, $message);
                static::assertNotIsReadable($filename, $message);
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
            public static function assertFileIsWritable($filename, $message = '')
            {
                static::assertFileExists($filename, $message);
                static::assertIsWritable($filename, $message);
            }

            /**
             * @param string $filename
             * @param string $message
             *
             * @return void
             */
            public static function assertFileNotIsWritable($filename, $message = '')
            {
                static::assertFileExists($filename, $message);
                static::assertNotIsWritable($filename, $message);
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

namespace {
    foreach (array(
        'PHPUnit\Framework\Constraint\IsEqual',
        'PHPUnit\Framework\Constraint\StringContains',
        'PHPUnit\Framework\Constraint\TraversableContains',
    ) as $class) {
        if (!class_exists($class) && class_exists(str_replace('\\', '_', $class))) {
            class_alias(str_replace('\\', '_', $class), $class);
        }
    }

    foreach (array(
        'PHPUnit\Framework\SelfDescribing',
    ) as $interface) {
        if (!interface_exists($interface) && interface_exists(str_replace('\\', '_', $interface))) {
            class_alias(str_replace('\\', '_', $interface), $interface);
        }
    }

    if (!class_exists('PHPUnit\Framework\Constraint\Constraint')) {
        class_alias('PHPUnit_Framework_Constraint', 'PHPUnit\Framework\Constraint\Constraint');
    }
}

// all the code below taken from various PHPUnit versions to make things work on PHPUnit 4.8 / PHP 5.3
/*
 * This file is part of PHPUnit.
 *
 * (c) Sebastian Bergmann <sebastian@phpunit.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PHPUnit\Framework\Constraint {
    use PHPUnit\Framework\ExpectationFailedException;

    if (!class_exists('PHPUnit\Framework\Constraint\RegularExpression')) {
        /**
         * Constraint that asserts that the string it is evaluated for matches
         * a regular expression.
         *
         * Checks a given value using the Perl Compatible Regular Expression extension
         * in PHP. The pattern is matched by executing preg_match().
         *
         * The pattern string passed in the constructor.
         */
        class RegularExpression extends Constraint
        {
            /**
             * @var string
             */
            protected $pattern;

            /**
             * @param string $pattern
             */
            public function __construct($pattern)
            {
                parent::__construct();
                $this->pattern = $pattern;
            }

            /**
             * Evaluates the constraint for parameter $other. Returns true if the
             * constraint is met, false otherwise.
             *
             * @param mixed $other Value or object to evaluate.
             *
             * @return bool
             */
            protected function matches($other)
            {
                return \preg_match($this->pattern, $other) > 0;
            }

            /**
             * Returns a string representation of the constraint.
             *
             * @return string
             */
            public function toString()
            {
                return \sprintf(
                    'matches PCRE pattern "%s"',
                    $this->pattern
                );
            }
        }
    }

    if (!class_exists('PHPUnit\Framework\Constraint\LogicalNot')) {
        /**
         * Logical NOT.
         */
        class LogicalNot extends Constraint
        {
            /**
             * @var Constraint
             */
            protected $constraint;

            /**
             * @param Constraint $constraint
             */
            public function __construct($constraint)
            {
                parent::__construct();

                if (!($constraint instanceof Constraint)) {
                    $constraint = new IsEqual($constraint);
                }

                $this->constraint = $constraint;
            }

            /**
             * @param string $string
             *
             * @return string
             */
            public static function negate($string)
            {
                $positives = array(
                    'contains ',
                    'exists',
                    'has ',
                    'is ',
                    'are ',
                    'matches ',
                    'starts with ',
                    'ends with ',
                    'reference ',
                    'not not ',
                );

                $negatives = array(
                    'does not contain ',
                    'does not exist',
                    'does not have ',
                    'is not ',
                    'are not ',
                    'does not match ',
                    'starts not with ',
                    'ends not with ',
                    'don\'t reference ',
                    'not ',
                );

                \preg_match('/(\'[\w\W]*\')([\w\W]*)("[\w\W]*")/i', $string, $matches);

                if (\count($matches) > 0) {
                    $nonInput = $matches[2];

                    $negatedString = \str_replace(
                        $nonInput,
                        \str_replace(
                            $positives,
                            $negatives,
                            $nonInput
                        ),
                        $string
                    );
                } else {
                    $negatedString = \str_replace(
                        $positives,
                        $negatives,
                        $string
                    );
                }

                return $negatedString;
            }

            /**
             * Evaluates the constraint for parameter $other
             *
             * If $returnResult is set to false (the default), an exception is thrown
             * in case of a failure. null is returned otherwise.
             *
             * If $returnResult is true, the result of the evaluation is returned as
             * a boolean value instead: true in case of success, false in case of a
             * failure.
             *
             * @param mixed  $other        Value or object to evaluate.
             * @param string $description  Additional information about the test
             * @param bool   $returnResult Whether to return a result or throw an exception
             *
             * @throws ExpectationFailedException
             * @return mixed
             */
            public function evaluate($other, $description = '', $returnResult = false)
            {
                $success = !$this->constraint->evaluate($other, $description, true);

                if ($returnResult) {
                    return $success;
                }

                if (!$success) {
                    $this->fail($other, $description);
                }
            }

            /**
             * Returns the description of the failure
             *
             * The beginning of failure messages is "Failed asserting that" in most
             * cases. This method should return the second part of that sentence.
             *
             * @param mixed $other Evaluated value or object.
             *
             * @return string
             */
            protected function failureDescription($other)
            {
                switch (\get_class($this->constraint)) {
                    case 'PHPUnit\Framework\Constraint\LogicalAnd':
                    case 'PHPUnit\Framework\Constraint\LogicalNot':
                    case 'PHPUnit\Framework\Constraint\LogicalOr':
                        return 'not( ' . $this->constraint->failureDescription($other) . ' )';

                    default:
                        return self::negate(
                            $this->constraint->failureDescription($other)
                        );
                }
            }

            /**
             * Returns a string representation of the constraint.
             *
             * @return string
             */
            public function toString()
            {
                switch (\get_class($this->constraint)) {
                    case 'PHPUnit\Framework\Constraint\LogicalAnd':
                    case 'PHPUnit\Framework\Constraint\LogicalNot':
                    case 'PHPUnit\Framework\Constraint\LogicalOr':
                        return 'not( ' . $this->constraint->toString() . ' )';

                    default:
                        return self::negate(
                            $this->constraint->toString()
                        );
                }
            }

            /**
             * Counts the number of constraint elements.
             *
             * @return int
             */
            public function count()
            {
                return \count($this->constraint);
            }
        }
    }
}
