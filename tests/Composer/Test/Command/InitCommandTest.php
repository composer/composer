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
use Symfony\Component\Console\Tester\ApplicationTester;

class InitCommandTest extends TestCase
{
    public function testParseValidAuthorString(): void
    {
        $command = new InitCommand;
        $author = $this->callParseAuthorString($command, 'John Smith <john@example.com>');
        self::assertEquals('John Smith', $author['name']);
        self::assertEquals('john@example.com', $author['email']);
    }

    public function testParseValidAuthorStringWithoutEmail(): void
    {
        $command = new InitCommand;
        $author = $this->callParseAuthorString($command, 'John Smith');
        self::assertEquals('John Smith', $author['name']);
        self::assertNull($author['email']);
    }

    public function testParseValidUtf8AuthorString(): void
    {
        $command = new InitCommand;
        $author = $this->callParseAuthorString($command, 'Matti Meikäläinen <matti@example.com>');
        self::assertEquals('Matti Meikäläinen', $author['name']);
        self::assertEquals('matti@example.com', $author['email']);
    }

    public function testParseValidUtf8AuthorStringWithNonSpacingMarks(): void
    {
        // \xCC\x88 is UTF-8 for U+0308 diaeresis (umlaut) combining mark
        $utf8_expected = "Matti Meika\xCC\x88la\xCC\x88inen";
        $command = new InitCommand;
        $author = $this->callParseAuthorString($command, $utf8_expected." <matti@example.com>");
        self::assertEquals($utf8_expected, $author['name']);
        self::assertEquals('matti@example.com', $author['email']);
    }

    public function testParseNumericAuthorString(): void
    {
        $command = new InitCommand;
        $author = $this->callParseAuthorString($command, 'h4x0r <h4x@example.com>');
        self::assertEquals('h4x0r', $author['name']);
        self::assertEquals('h4x@example.com', $author['email']);
    }

    /**
     * Test scenario for issue #5631
     * @link https://github.com/composer/composer/issues/5631 Issue #5631
     */
    public function testParseValidAlias1AuthorString(): void
    {
        $command = new InitCommand;
        $author = $this->callParseAuthorString(
            $command,
            'Johnathon "Johnny" Smith <john@example.com>'
        );
        self::assertEquals('Johnathon "Johnny" Smith', $author['name']);
        self::assertEquals('john@example.com', $author['email']);
    }

    /**
     * Test scenario for issue #5631
     * @link https://github.com/composer/composer/issues/5631 Issue #5631
     */
    public function testParseValidAlias2AuthorString(): void
    {
        $command = new InitCommand;
        $author = $this->callParseAuthorString(
            $command,
            'Johnathon (Johnny) Smith <john@example.com>'
        );
        self::assertEquals('Johnathon (Johnny) Smith', $author['name']);
        self::assertEquals('john@example.com', $author['email']);
    }

    public function testParseEmptyAuthorString(): void
    {
        $command = new InitCommand;
        self::expectException('InvalidArgumentException');
        $this->callParseAuthorString($command, '');
    }

    public function testParseAuthorStringWithInvalidEmail(): void
    {
        $command = new InitCommand;
        self::expectException('InvalidArgumentException');
        $this->callParseAuthorString($command, 'John Smith <john>');
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
        $reflMethod->setAccessible(true);

        return $reflMethod->invoke($command, $string);
    }

    public function testRunNoInteraction(): void
    {
        $this->expectException(\RuntimeException::class);
        $dir = $this->initTempComposer();
        unlink($dir . '/composer.json');
        unlink($dir . '/auth.json');

        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => 'init', '--no-interaction' => true]);
    }

    public function testRunInvalidNameArgument(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $dir = $this->initTempComposer();
        unlink($dir . '/composer.json');
        unlink($dir . '/auth.json');

        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => 'init', '--no-interaction' => true, '--name' => 'test']);
    }

    public function testRunNameArgument(): void
    {
        $dir = $this->initTempComposer();
        unlink($dir . '/composer.json');
        unlink($dir . '/auth.json');

        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => 'init', '--no-interaction' => true, '--name' => 'test/pkg']);

        self::assertSame(0, $appTester->getStatusCode());

        $expected = [
            'name' => 'test/pkg',
            'require' => [],
        ];

        $file = new JsonFile($dir . '/composer.json');
        self::assertEquals($expected, $file->read());
    }

    public function testRunInvalidAuthorArgumentInvalidEmail(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $dir = $this->initTempComposer();
        unlink($dir . '/composer.json');
        unlink($dir . '/auth.json');

        $appTester = $this->getApplicationTester();
        $appTester->run([
            'command' => 'init',
            '--no-interaction' => true,
            '--name' => 'test/pkg',
            '--author' => 'Mr. Test <test>',
        ]);
    }

    public function testRunAuthorArgument(): void
    {
        $dir = $this->initTempComposer();
        unlink($dir . '/composer.json');
        unlink($dir . '/auth.json');

        $appTester = $this->getApplicationTester();
        $appTester->run([
            'command' => 'init',
            '--no-interaction' => true,
            '--name' => 'test/pkg',
            '--author' => 'Mr. Test <test@example.org>',
        ]);

        self::assertSame(0, $appTester->getStatusCode());

        $expected = [
            'name' => 'test/pkg',
            'require' => [],
            'authors' => [
                [
                    'name' => 'Mr. Test',
                    'email' => 'test@example.org',
                ]
            ],
        ];

        $file = new JsonFile($dir . '/composer.json');
        self::assertEquals($expected, $file->read());
    }

    public function testRunAuthorArgumentMissingEmail(): void
    {
        $dir = $this->initTempComposer();
        unlink($dir . '/composer.json');
        unlink($dir . '/auth.json');

        $appTester = $this->getApplicationTester();
        $appTester->run([
            'command' => 'init',
            '--no-interaction' => true,
            '--name' => 'test/pkg',
            '--author' => 'Mr. Test',
        ]);

        self::assertSame(0, $appTester->getStatusCode());

        $expected = [
            'name' => 'test/pkg',
            'require' => [],
            'authors' => [
                [
                    'name' => 'Mr. Test',
                ]
            ],
        ];

        $file = new JsonFile($dir . '/composer.json');
        self::assertEquals($expected, $file->read());
    }

    public function testRunSingleRepositoryArgument(): void
    {
        $dir = $this->initTempComposer();
        unlink($dir . '/composer.json');
        unlink($dir . '/auth.json');

        $appTester = $this->getApplicationTester();
        $appTester->run([
            'command' => 'init',
            '--no-interaction' => true,
            '--name' => 'test/pkg',
            '--repository' => [
                '{"type":"vcs","url":"http://packages.example.com"}'
            ],
        ]);

        self::assertSame(0, $appTester->getStatusCode());

        $expected = [
            'name' => 'test/pkg',
            'require' => [],
            'repositories' => [
                [
                    'type' => 'vcs',
                    'url' => 'http://packages.example.com'
                ]
            ]
        ];

        $file = new JsonFile($dir . '/composer.json');
        self::assertEquals($expected, $file->read());
    }

    public function testRunMultipleRepositoryArguments(): void
    {
        $dir = $this->initTempComposer();
        unlink($dir . '/composer.json');
        unlink($dir . '/auth.json');

        $appTester = $this->getApplicationTester();
        $appTester->run([
            'command' => 'init',
            '--no-interaction' => true,
            '--name' => 'test/pkg',
            '--repository' => [
                '{"type":"vcs","url":"http://vcs.example.com"}',
                '{"type":"composer","url":"http://composer.example.com"}',
                '{"type":"composer","url":"http://composer2.example.com","options":{"ssl":{"verify_peer":"true"}}}',
            ],
        ]);

        self::assertSame(0, $appTester->getStatusCode());

        $expected = [
            'name' => 'test/pkg',
            'require' => [],
            'repositories' => [
                [
                    'type' => 'vcs',
                    'url' => 'http://vcs.example.com'
                ],
                [
                    'type' => 'composer',
                    'url' => 'http://composer.example.com'
                ],
                [
                    'type' => 'composer',
                    'url' => 'http://composer2.example.com',
                    'options' => [
                        'ssl' => [
                            'verify_peer' => 'true'
                        ]
                    ]
                ]
            ]
        ];

        $file = new JsonFile($dir . '/composer.json');
        self::assertEquals($expected, $file->read());
    }

    public function testRunStability(): void
    {
        $dir = $this->initTempComposer();
        unlink($dir . '/composer.json');
        unlink($dir . '/auth.json');

        $appTester = $this->getApplicationTester();
        $appTester->run([
            'command' => 'init',
            '--no-interaction' => true,
            '--name' => 'test/pkg',
            '--stability' => 'dev',
        ]);

        self::assertSame(0, $appTester->getStatusCode());

        $expected = [
            'name' => 'test/pkg',
            'require' => [],
            'minimum-stability' => 'dev',
        ];

        $file = new JsonFile($dir . '/composer.json');
        self::assertEquals($expected, $file->read());
    }

    public function testRunInvalidStability(): void
    {
        $dir = $this->initTempComposer();
        unlink($dir . '/composer.json');
        unlink($dir . '/auth.json');

        $appTester = $this->getApplicationTester();
        $appTester->run([
            'command' => 'init',
            '--no-interaction' => true,
            '--name' => 'test/pkg',
            '--stability' => 'bogus',
        ], ['capture_stderr_separately' => true]);

        self::assertSame(1, $appTester->getStatusCode());

        self::assertMatchesRegularExpression("/minimum-stability\s+:\s+Does not have a value in the enumeration/", $appTester->getErrorOutput());
    }

    public function testRunRequireOne(): void
    {
        $dir = $this->initTempComposer();
        unlink($dir . '/composer.json');
        unlink($dir . '/auth.json');

        $appTester = $this->getApplicationTester();
        $appTester->run([
            'command' => 'init',
            '--no-interaction' => true,
            '--name' => 'test/pkg',
            '--require' => [
                'first/pkg:1.0.0'
            ],
        ]);

        self::assertSame(0, $appTester->getStatusCode());

        $expected = [
            'name' => 'test/pkg',
            'require' => [
                'first/pkg' => '1.0.0'
            ],
        ];

        $file = new JsonFile($dir . '/composer.json');
        self::assertEquals($expected, $file->read());
    }

    public function testRunRequireMultiple(): void
    {
        $dir = $this->initTempComposer();
        unlink($dir . '/composer.json');
        unlink($dir . '/auth.json');

        $appTester = $this->getApplicationTester();
        $appTester->run([
            'command' => 'init',
            '--no-interaction' => true,
            '--name' => 'test/pkg',
            '--require' => [
                'first/pkg:1.0.0',
                'second/pkg:^3.4'
            ],
        ]);

        self::assertSame(0, $appTester->getStatusCode());

        $expected = [
            'name' => 'test/pkg',
            'require' => [
                'first/pkg' => '1.0.0',
                'second/pkg' => '^3.4',
            ],
        ];

        $file = new JsonFile($dir . '/composer.json');
        self::assertEquals($expected, $file->read());
    }

    public function testRunInvalidRequire(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage("Option first is missing a version constraint, use e.g. first:^1.0");

        $dir = $this->initTempComposer();
        unlink($dir . '/composer.json');
        unlink($dir . '/auth.json');

        $appTester = $this->getApplicationTester();
        $appTester->run([
            'command' => 'init',
            '--no-interaction' => true,
            '--name' => 'test/pkg',
            '--require' => [
                'first',
            ],
        ]);
    }

    public function testRunRequireDevOne(): void
    {
        $dir = $this->initTempComposer();
        unlink($dir . '/composer.json');
        unlink($dir . '/auth.json');

        $appTester = $this->getApplicationTester();
        $appTester->run([
            'command' => 'init',
            '--no-interaction' => true,
            '--name' => 'test/pkg',
            '--require-dev' => [
                'first/pkg:1.0.0'
            ],
        ]);

        self::assertSame(0, $appTester->getStatusCode());

        $expected = [
            'name' => 'test/pkg',
            'require' => [],
            'require-dev' => [
                'first/pkg' => '1.0.0'
            ],
        ];

        $file = new JsonFile($dir . '/composer.json');
        self::assertEquals($expected, $file->read());
    }

    public function testRunRequireDevMultiple(): void
    {
        $dir = $this->initTempComposer();
        unlink($dir . '/composer.json');
        unlink($dir . '/auth.json');

        $appTester = $this->getApplicationTester();
        $appTester->run([
            'command' => 'init',
            '--no-interaction' => true,
            '--name' => 'test/pkg',
            '--require-dev' => [
                'first/pkg:1.0.0',
                'second/pkg:^3.4'
            ],
        ]);

        self::assertSame(0, $appTester->getStatusCode());

        $expected = [
            'name' => 'test/pkg',
            'require' => [],
            'require-dev' => [
                'first/pkg' => '1.0.0',
                'second/pkg' => '^3.4',
            ],
        ];

        $file = new JsonFile($dir . '/composer.json');
        self::assertEquals($expected, $file->read());
    }

    public function testRunInvalidRequireDev(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage("Option first is missing a version constraint, use e.g. first:^1.0");

        $dir = $this->initTempComposer();
        unlink($dir . '/composer.json');
        unlink($dir . '/auth.json');

        $appTester = $this->getApplicationTester();
        $appTester->run([
            'command' => 'init',
            '--no-interaction' => true,
            '--name' => 'test/pkg',
            '--require-dev' => [
                'first',
            ],
        ]);
    }

    public function testRunAutoload(): void
    {
        $dir = $this->initTempComposer();
        unlink($dir . '/composer.json');
        unlink($dir . '/auth.json');

        $appTester = $this->getApplicationTester();
        $appTester->run([
            'command' => 'init',
            '--no-interaction' => true,
            '--name' => 'test/pkg',
            '--autoload' => 'testMapping/'
        ]);

        self::assertSame(0, $appTester->getStatusCode());

        $expected = [
            'name' => 'test/pkg',
            'require' => [],
            'autoload' => [
                'psr-4' => [
                    'Test\\Pkg\\' => 'testMapping/',
                ]
            ],
        ];

        $file = new JsonFile($dir . '/composer.json');
        self::assertEquals($expected, $file->read());
    }

    public function testRunHomepage(): void
    {
        $dir = $this->initTempComposer();
        unlink($dir . '/composer.json');
        unlink($dir . '/auth.json');

        $appTester = $this->getApplicationTester();
        $appTester->run([
            'command' => 'init',
            '--no-interaction' => true,
            '--name' => 'test/pkg',
            '--homepage' => 'https://example.org/'
        ]);

        self::assertSame(0, $appTester->getStatusCode());

        $expected = [
            'name' => 'test/pkg',
            'require' => [],
            'homepage' => 'https://example.org/'
        ];

        $file = new JsonFile($dir . '/composer.json');
        self::assertEquals($expected, $file->read());
    }

    public function testRunInvalidHomepage(): void
    {
        $dir = $this->initTempComposer();
        unlink($dir . '/composer.json');
        unlink($dir . '/auth.json');

        $appTester = $this->getApplicationTester();
        $appTester->run([
            'command' => 'init',
            '--no-interaction' => true,
            '--name' => 'test/pkg',
            '--homepage' => 'not-a-url',
        ], ['capture_stderr_separately' => true]);

        self::assertSame(1, $appTester->getStatusCode());
        self::assertMatchesRegularExpression("/homepage\s*:\s*Invalid URL format/", $appTester->getErrorOutput());
    }

    public function testRunDescription(): void
    {
        $dir = $this->initTempComposer();
        unlink($dir . '/composer.json');
        unlink($dir . '/auth.json');

        $appTester = $this->getApplicationTester();
        $appTester->run([
            'command' => 'init',
            '--no-interaction' => true,
            '--name' => 'test/pkg',
            '--description' => 'My first example package'
        ]);

        self::assertSame(0, $appTester->getStatusCode());

        $expected = [
            'name' => 'test/pkg',
            'require' => [],
            'description' => 'My first example package'
        ];

        $file = new JsonFile($dir . '/composer.json');
        self::assertEquals($expected, $file->read());
    }

    public function testRunType(): void
    {
        $dir = $this->initTempComposer();
        unlink($dir . '/composer.json');
        unlink($dir . '/auth.json');

        $appTester = $this->getApplicationTester();
        $appTester->run([
            'command' => 'init',
            '--no-interaction' => true,
            '--name' => 'test/pkg',
            '--type' => 'library'
        ]);

        self::assertSame(0, $appTester->getStatusCode());

        $expected = [
            'name' => 'test/pkg',
            'require' => [],
            'type' => 'library'
        ];

        $file = new JsonFile($dir . '/composer.json');
        self::assertEquals($expected, $file->read());
    }

    public function testRunLicense(): void
    {
        $dir = $this->initTempComposer();
        unlink($dir . '/composer.json');
        unlink($dir . '/auth.json');

        $appTester = $this->getApplicationTester();
        $appTester->run([
            'command' => 'init',
            '--no-interaction' => true,
            '--name' => 'test/pkg',
            '--license' => 'MIT'
        ]);

        self::assertSame(0, $appTester->getStatusCode());

        $expected = [
            'name' => 'test/pkg',
            'require' => [],
            'license' => 'MIT'
        ];

        $file = new JsonFile($dir . '/composer.json');
        self::assertEquals($expected, $file->read());
    }

    public function testInteractiveRunDescription(): void
    {
        $dir = $this->initTempComposer();
        unlink($dir . '/composer.json');
        unlink($dir . '/auth.json');

        $appTester = $this->getApplicationTester();

        $inputs = self::generateInteractiveInputs([
            'package_name' => ['vendor/my-package'],
        ]);
        $appTester->setInputs($inputs);

        $appTester->run(['command' => 'init']);

        self::assertSame(0, $appTester->getStatusCode());

        $expected = [
            "name" => "vendor/my-package",
            "description" => "my desciption",
            "type" => "library",
            "license" => "Custom Liscence",
            "authors" => [["name" => "Mr. Test", "email" => "test@example.org"]],
            "minimum-stability" => "stable",
            "require" => [],
        ];

        $file = new JsonFile($dir . '/composer.json');
        self::assertEquals($expected, $file->read());
    }

    /**
     * @param array<string, array<string>> $inputs
     * @return array<string>
     */
    static public function generateInteractiveInputs(array $inputs): array
    {
        $default_inputs = [
            'package_name' => ['pkg/dep'],
            'description' => ['my desciption'],
            'author' => ['Mr. Test <test@example.org>'],
            'minimum_stability' => ['stable'],
            'type' => ['library'],
            'liscene' => ['Custom Liscence'],
            'define_dep' => ['no'],
            'define_dev_dependencies' => ['no'],
            // Add PSR-4 autoload mapping
            'add_psr' => ['n'],
            'confirm' => [''],
        ];
        $flat_inputs = [];

        foreach (array_keys($default_inputs) as $key) {
            $flat_inputs = array_merge(
                $flat_inputs,
                $inputs[$key] ?? $default_inputs[$key]
            );
        }

        return $flat_inputs;
    }
}
