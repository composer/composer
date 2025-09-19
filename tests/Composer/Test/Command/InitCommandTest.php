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

namespace Composer\Test\Command;

use Composer\Command\InitCommand;
use Composer\Json\JsonFile;
use Composer\Test\TestCase;
use InvalidArgumentException;

class InitCommandTest extends TestCase
{
    private const DEFAULT_AUTHORS = [
        'name' => 'John Smith',
        'email' => 'john@example.com',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $_SERVER['COMPOSER_DEFAULT_AUTHOR'] = 'John Smith';
        $_SERVER['COMPOSER_DEFAULT_EMAIL'] = 'john@example.com';
    }

    /**
     * @dataProvider validAuthorStringProvider
     */
    public function testParseValidAuthorString(string $name, ?string $email, string $input): void
    {
        $author = $this->callParseAuthorString(new InitCommand, $input);
        self::assertSame($name, $author['name']);
        self::assertSame($email, $author['email']);
    }

    /**
     * @return iterable<string, array{0: string, 1: string|null, 2: string}>
     */
    public static function validAuthorStringProvider(): iterable
    {
        yield 'simple' => [
            'John Smith',
            'john@example.com',
            'John Smith <john@example.com>',
        ];
        yield 'without email' => [
            'John Smith',
            null,
            'John Smith',
        ];
        yield 'UTF-8' => [
            'Matti Meikäläinen',
            'matti@example.com',
            'Matti Meikäläinen <matti@example.com>',
        ];
        // \xCC\x88 is UTF-8 for U+0308 diaeresis (umlaut) combining mark
        yield 'UTF-8 with non-spacing marks' => [
            "Matti Meika\xCC\x88la\xCC\x88inen",
            'matti@example.com',
            "Matti Meika\xCC\x88la\xCC\x88inen <matti@example.com>",
        ];
        yield 'numeric author name' => [
            'h4x0r',
            'h4x@example.com',
            'h4x0r <h4x@example.com>',
        ];
        // https://github.com/composer/composer/issues/5631 Issue #5631
        yield 'alias 1' => [
            'Johnathon "Johnny" Smith',
            'john@example.com',
            'Johnathon "Johnny" Smith <john@example.com>',
        ];
        // https://github.com/composer/composer/issues/5631 Issue #5631
        yield 'alias 2' => [
            'Johnathon (Johnny) Smith',
            'john@example.com',
            'Johnathon (Johnny) Smith <john@example.com>',
        ];
    }

    public function testParseEmptyAuthorString(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->callParseAuthorString(new InitCommand, '');
    }

    public function testParseAuthorStringWithInvalidEmail(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->callParseAuthorString(new InitCommand, 'John Smith <john>');
    }

    public function testNamespaceFromValidPackageName(): void
    {
        $command = new InitCommand;
        $namespace = $command->namespaceFromPackageName('new_projects.acme-extra/package-name');
        self::assertEquals('NewProjectsAcmeExtra\PackageName', $namespace);
    }

    public function testNamespaceFromInvalidPackageName(): void
    {
        $command = new InitCommand;
        $namespace = $command->namespaceFromPackageName('invalid-package-name');
        self::assertNull($namespace);
    }

    public function testNamespaceFromMissingPackageName(): void
    {
        $command = new InitCommand;
        $namespace = $command->namespaceFromPackageName('');
        self::assertNull($namespace);
    }

    /**
     * @return array{name: string, email: string|null}
     */
    private function callParseAuthorString(InitCommand $command, string $string): array
    {
        $reflMethod = new \ReflectionMethod($command, 'parseAuthorString');
        (\PHP_VERSION_ID < 80100) and $reflMethod->setAccessible(true);

        return $reflMethod->invoke($command, $string);
    }

    /**
     * @dataProvider runDataProvider
     *
     * @param array<string, mixed> $expected
     * @param array<string, mixed> $arguments
     */
    public function testRunCommand(array $expected, array $arguments = []): void
    {
        $dir = $this->initTempComposer();
        unlink($dir . '/composer.json');
        unlink($dir . '/auth.json');

        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => 'init', '--no-interaction' => true] + $arguments);

        self::assertSame(0, $appTester->getStatusCode());

        $file = new JsonFile($dir . '/composer.json');
        self::assertEquals($expected, $file->read());
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: array<string, mixed>}>
     */
    public static function runDataProvider(): iterable
    {
        yield 'name argument' => [
            [
                'name' => 'test/pkg',
                'authors' => [self::DEFAULT_AUTHORS],
                'require' => [],
            ],
            ['--name' => 'test/pkg'],
        ];
        yield 'name and author arguments' => [
            [
                'name' => 'test/pkg',
                'require' => [],
                'authors' => [
                    [
                        'name' => 'Mr. Test',
                        'email' => 'test@example.org',
                    ],
                ],
            ],
            ['--name' => 'test/pkg', '--author' => 'Mr. Test <test@example.org>']
        ];
        yield 'name and author arguments without email' => [
            [
                'name' => 'test/pkg',
                'require' => [],
                'authors' => [
                    [
                        'name' => 'Mr. Test',
                    ],
                ],
            ],
            ['--name' => 'test/pkg', '--author' => 'Mr. Test']
        ];
        yield 'single repository argument' => [
            [
                'name' => 'test/pkg',
                'authors' => [self::DEFAULT_AUTHORS],
                'require' => [],
                'repositories' => [
                    [
                        'type' => 'vcs',
                        'url' => 'http://packages.example.com',
                    ],
                ],
            ],
            ['--name' => 'test/pkg', '--repository' => ['{"type":"vcs","url":"http://packages.example.com"}']],
        ];
        yield 'multiple repository arguments' => [
            [
                'name' => 'test/pkg',
                'authors' => [self::DEFAULT_AUTHORS],
                'require' => [],
                'repositories' => [
                    [
                        'type' => 'vcs',
                        'url' => 'http://vcs.example.com',
                    ],
                    [
                        'type' => 'composer',
                        'url' => 'http://composer.example.com',
                    ],
                    [
                        'type' => 'composer',
                        'url' => 'http://composer2.example.com',
                        'options' => [
                            'ssl' => [
                                'verify_peer' => 'true',
                            ],
                        ],
                    ],
                ],
            ],
            ['--name' => 'test/pkg', '--repository' => [
                '{"type":"vcs","url":"http://vcs.example.com"}',
                '{"type":"composer","url":"http://composer.example.com"}',
                '{"type":"composer","url":"http://composer2.example.com","options":{"ssl":{"verify_peer":"true"}}}',
            ]],
        ];
        yield 'stability argument' => [
            [
                'name' => 'test/pkg',
                'authors' => [self::DEFAULT_AUTHORS],
                'require' => [],
                'minimum-stability' => 'dev',
            ],
            ['--name' => 'test/pkg', '--stability' => 'dev'],
        ];
        yield 'require one argument' => [
            [
                'name' => 'test/pkg',
                'authors' => [self::DEFAULT_AUTHORS],
                'require' => [
                    'first/pkg' => '1.0.0',
                ],
            ],
            ['--name' => 'test/pkg', '--require' => ['first/pkg:1.0.0']],
        ];
        yield 'require multiple arguments' => [
            [
                'name' => 'test/pkg',
                'authors' => [self::DEFAULT_AUTHORS],
                'require' => [
                    'first/pkg' => '1.0.0',
                    'second/pkg' => '^3.4',
                ],
            ],
            ['--name' => 'test/pkg', '--require' => ['first/pkg:1.0.0', 'second/pkg:^3.4']],
        ];
        yield 'require-dev one argument' => [
            [
                'name' => 'test/pkg',
                'authors' => [self::DEFAULT_AUTHORS],
                'require' => [],
                'require-dev' => [
                    'first/pkg' => '1.0.0',
                ],
            ],
            ['--name' => 'test/pkg', '--require-dev' => ['first/pkg:1.0.0']],
        ];
        yield 'require-dev multiple arguments' => [
            [
                'name' => 'test/pkg',
                'authors' => [self::DEFAULT_AUTHORS],
                'require' => [],
                'require-dev' => [
                    'first/pkg' => '1.0.0',
                    'second/pkg' => '^3.4',
                ],
            ],
            ['--name' => 'test/pkg', '--require-dev' => ['first/pkg:1.0.0', 'second/pkg:^3.4']],
        ];
        yield 'autoload argument' => [
            [
                'name' => 'test/pkg',
                'authors' => [self::DEFAULT_AUTHORS],
                'require' => [],
                'autoload' => [
                    'psr-4' => [
                        'Test\\Pkg\\' => 'testMapping/',
                    ],
                ],
            ],
            ['--name' => 'test/pkg', '--autoload' => 'testMapping/'],
        ];
        yield 'homepage argument' => [
            [
                'name' => 'test/pkg',
                'authors' => [self::DEFAULT_AUTHORS],
                'require' => [],
                'homepage' => 'https://example.org/',
            ],
            ['--name' => 'test/pkg', '--homepage' => 'https://example.org/'],
        ];
        yield 'description argument' => [
            [
                'name' => 'test/pkg',
                'authors' => [self::DEFAULT_AUTHORS],
                'require' => [],
                'description' => 'My first example package',
            ],
            ['--name' => 'test/pkg', '--description' => 'My first example package'],
        ];
        yield 'type argument' => [
            [
                'name' => 'test/pkg',
                'authors' => [self::DEFAULT_AUTHORS],
                'require' => [],
                'type' => 'project',
            ],
            ['--name' => 'test/pkg', '--type' => 'project'],
        ];
        yield 'license argument' => [
            [
                'name' => 'test/pkg',
                'authors' => [self::DEFAULT_AUTHORS],
                'require' => [],
                'license' => 'MIT',
            ],
            ['--name' => 'test/pkg', '--license' => 'MIT'],
        ];
    }

    /**
     * @dataProvider runInvalidDataProvider
     *
     * @param class-string<\Throwable>|null $exception
     * @param string|null $message
     * @param array<string, mixed> $arguments
     */
    public function testRunCommandInvalid(?string $exception, ?string $message, array $arguments): void
    {
        if ($exception !== null) {
            $this->expectException($exception);

            if ($message !== null) {
                $this->expectExceptionMessage($message);
            }
        }

        $dir = $this->initTempComposer();
        unlink($dir . '/composer.json');
        unlink($dir . '/auth.json');

        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => 'init', '--no-interaction' => true] + $arguments, ['capture_stderr_separately' => true]);

        if ($exception === null && $message !== null) {
            self::assertSame(1, $appTester->getStatusCode());
            self::assertMatchesRegularExpression($message, $appTester->getErrorOutput());
        }
    }

    /**
     * @return iterable<string, array{0: class-string<\Throwable>|null, 1: string|null, 2: array<string, mixed>}>
     */
    public static function runInvalidDataProvider(): iterable
    {
        yield 'invalid name argument' => [
            \InvalidArgumentException::class,
            null,
            ['--name' => 'test'],
        ];
        yield 'invalid author argument' => [
            \InvalidArgumentException::class,
            null,
            ['--name' => 'test/pkg', '--author' => 'Mr. Test <test>'],
        ];
        yield 'invalid stability argument' => [
            null,
            '/minimum-stability\s+:\s+Does not have a value in the enumeration/',
            ['--name' => 'test/pkg', '--stability' => 'bogus'],
        ];
        yield 'invalid require argument' => [
            \UnexpectedValueException::class,
            "Option first is missing a version constraint, use e.g. first:^1.0",
            ['--name' => 'test/pkg', '--require' => ['first']],
        ];
        yield 'invalid require-dev argument' => [
            \UnexpectedValueException::class,
            "Option first is missing a version constraint, use e.g. first:^1.0",
            ['--name' => 'test/pkg', '--require-dev' => ['first']],
        ];
        yield 'invalid homepage argument' => [
            null,
            "/homepage\s*:\s*Invalid URL format/",
            ['--name' => 'test/pkg', '--homepage' => 'not-a-url'],
        ];
    }

    public function testRunGuessNameFromDirSanitizesDir(): void
    {
        $dir = $this->initTempComposer();
        mkdir($dirName = '_foo_--bar__baz.--..qux__');
        chdir($dirName);

        $_SERVER['COMPOSER_DEFAULT_VENDOR'] = '.vendorName';

        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => 'init', '--no-interaction' => true]);

        self::assertSame(0, $appTester->getStatusCode());

        $expected = [
            'name' => 'vendor-name/foo-bar_baz.qux',
            'authors' => [self::DEFAULT_AUTHORS],
            'require' => [],
        ];

        $file = new JsonFile('./composer.json');
        self::assertEquals($expected, $file->read());

        unset($_SERVER['COMPOSER_DEFAULT_VENDOR']);
    }

    public function testInteractiveRun(): void
    {
        $dir = $this->initTempComposer();
        unlink($dir . '/composer.json');
        unlink($dir . '/auth.json');

        $appTester = $this->getApplicationTester();

        $appTester->setInputs([
            'vendor/pkg',                   // Pkg name
            'my description',               // Description
            'Mr. Test <test@example.org>',  // Author
            'stable',                       // Minimum stability
            'library',                      // Type
            'AGPL-3.0-only',                // License
            'no',                           // Define dependencies
            'no',                           // Define dev dependencies
            'n',                            // Add PSR-4 autoload mapping
            '',                             // Confirm generation
        ]);

        $appTester->run(['command' => 'init']);

        self::assertSame(0, $appTester->getStatusCode());

        $expected = [
            'name' => 'vendor/pkg',
            'description' => 'my description',
            'type' => 'library',
            'license' => 'AGPL-3.0-only',
            'authors' => [['name' => 'Mr. Test', 'email' => 'test@example.org']],
            'minimum-stability' => 'stable',
            'require' => [],
        ];

        $file = new JsonFile($dir . '/composer.json');
        self::assertEquals($expected, $file->read());
    }
}
