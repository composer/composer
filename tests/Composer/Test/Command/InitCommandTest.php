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
        $this->assertEquals('John Smith', $author['name']);
        $this->assertEquals('john@example.com', $author['email']);
    }

    public function testParseValidAuthorStringWithoutEmail(): void
    {
        $command = new InitCommand;
        $author = $this->callParseAuthorString($command, 'John Smith');
        $this->assertEquals('John Smith', $author['name']);
        $this->assertNull($author['email']);
    }

    public function testParseValidUtf8AuthorString(): void
    {
        $command = new InitCommand;
        $author = $this->callParseAuthorString($command, 'Matti Meikäläinen <matti@example.com>');
        $this->assertEquals('Matti Meikäläinen', $author['name']);
        $this->assertEquals('matti@example.com', $author['email']);
    }

    public function testParseValidUtf8AuthorStringWithNonSpacingMarks(): void
    {
        // \xCC\x88 is UTF-8 for U+0308 diaeresis (umlaut) combining mark
        $utf8_expected = "Matti Meika\xCC\x88la\xCC\x88inen";
        $command = new InitCommand;
        $author = $this->callParseAuthorString($command, $utf8_expected." <matti@example.com>");
        $this->assertEquals($utf8_expected, $author['name']);
        $this->assertEquals('matti@example.com', $author['email']);
    }

    public function testParseNumericAuthorString(): void
    {
        $command = new InitCommand;
        $author = $this->callParseAuthorString($command, 'h4x0r <h4x@example.com>');
        $this->assertEquals('h4x0r', $author['name']);
        $this->assertEquals('h4x@example.com', $author['email']);
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
        $this->assertEquals('Johnathon "Johnny" Smith', $author['name']);
        $this->assertEquals('john@example.com', $author['email']);
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
        $this->assertEquals('Johnathon (Johnny) Smith', $author['name']);
        $this->assertEquals('john@example.com', $author['email']);
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
        $this->assertEquals('NewProjectsAcmeExtra\PackageName', $namespace);
    }

    public function testNamespaceFromInvalidPackageName(): void
    {
        $command = new InitCommand;
        $namespace = $command->namespaceFromPackageName('invalid-package-name');
        $this->assertNull($namespace);
    }

    public function testNamespaceFromMissingPackageName(): void
    {
        $command = new InitCommand;
        $namespace = $command->namespaceFromPackageName('');
        $this->assertNull($namespace);
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

        $this->assertSame(0, $appTester->getStatusCode());

        $expected = [
            'name' => 'test/pkg',
            'require' => [],
        ];

        $file = new JsonFile($dir . '/composer.json');
        $this->assertEquals($expected, $file->read());
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

        $this->assertSame(0, $appTester->getStatusCode());

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
        $this->assertEquals($expected, $file->read());
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

        $this->assertSame(0, $appTester->getStatusCode());

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
        $this->assertEquals($expected, $file->read());
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

        $this->assertSame(0, $appTester->getStatusCode());

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
        $this->assertEquals($expected, $file->read());
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

        $this->assertSame(0, $appTester->getStatusCode());

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
        $this->assertEquals($expected, $file->read());
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

        $this->assertSame(0, $appTester->getStatusCode());

        $expected = [
            'name' => 'test/pkg',
            'require' => [],
            'minimum-stability' => 'dev',
        ];

        $file = new JsonFile($dir . '/composer.json');
        $this->assertEquals($expected, $file->read());
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

        $this->assertSame(1, $appTester->getStatusCode());

        $this->assertMatchesRegularExpression("/minimum-stability\s+:\s+Does not have a value in the enumeration/", $appTester->getErrorOutput());
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

        $this->assertSame(0, $appTester->getStatusCode());

        $expected = [
            'name' => 'test/pkg',
            'require' => [
                'first/pkg' => '1.0.0'
            ],
        ];

        $file = new JsonFile($dir . '/composer.json');
        $this->assertEquals($expected, $file->read());
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

        $this->assertSame(0, $appTester->getStatusCode());

        $expected = [
            'name' => 'test/pkg',
            'require' => [
                'first/pkg' => '1.0.0',
                'second/pkg' => '^3.4',
            ],
        ];

        $file = new JsonFile($dir . '/composer.json');
        $this->assertEquals($expected, $file->read());
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

        $this->assertSame(0, $appTester->getStatusCode());

        $expected = [
            'name' => 'test/pkg',
            'require' => [],
            'require-dev' => [
                'first/pkg' => '1.0.0'
            ],
        ];

        $file = new JsonFile($dir . '/composer.json');
        $this->assertEquals($expected, $file->read());
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

        $this->assertSame(0, $appTester->getStatusCode());

        $expected = [
            'name' => 'test/pkg',
            'require' => [],
            'require-dev' => [
                'first/pkg' => '1.0.0',
                'second/pkg' => '^3.4',
            ],
        ];

        $file = new JsonFile($dir . '/composer.json');
        $this->assertEquals($expected, $file->read());
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

        $this->assertSame(0, $appTester->getStatusCode());

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
        $this->assertEquals($expected, $file->read());
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

        $this->assertSame(0, $appTester->getStatusCode());

        $expected = [
            'name' => 'test/pkg',
            'require' => [],
            'homepage' => 'https://example.org/'
        ];

        $file = new JsonFile($dir . '/composer.json');
        $this->assertEquals($expected, $file->read());
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

        $this->assertSame(1, $appTester->getStatusCode());
        $this->assertMatchesRegularExpression("/homepage\s*:\s*Invalid URL format/", $appTester->getErrorOutput());
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

        $this->assertSame(0, $appTester->getStatusCode());

        $expected = [
            'name' => 'test/pkg',
            'require' => [],
            'description' => 'My first example package'
        ];

        $file = new JsonFile($dir . '/composer.json');
        $this->assertEquals($expected, $file->read());
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

        $this->assertSame(0, $appTester->getStatusCode());

        $expected = [
            'name' => 'test/pkg',
            'require' => [],
            'type' => 'library'
        ];

        $file = new JsonFile($dir . '/composer.json');
        $this->assertEquals($expected, $file->read());
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

        $this->assertSame(0, $appTester->getStatusCode());

        $expected = [
            'name' => 'test/pkg',
            'require' => [],
            'license' => 'MIT'
        ];

        $file = new JsonFile($dir . '/composer.json');
        $this->assertEquals($expected, $file->read());
    }
}
