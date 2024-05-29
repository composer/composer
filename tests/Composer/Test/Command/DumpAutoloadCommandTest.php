<?php declare(strict_types=1);

namespace Composer\Test\Command;

use Composer\Test\TestCase;
use InvalidArgumentException;

class DumpAutoloadCommandTest extends TestCase
{
    public function testDumpAutoload(): void
    {
        $appTester = $this->getApplicationTester();
        self::assertSame(0, $appTester->run(['command' => 'dump-autoload']));

        $output = $appTester->getDisplay(true);
        self::assertStringContainsString('Generating autoload files', $output);
        self::assertStringContainsString('Generated autoload files', $output);
    }

    public function testDumpDevAutoload(): void
    {
        $appTester = $this->getApplicationTester();
        self::assertSame(0, $appTester->run(['command' => 'dump-autoload', '--dev' => true]));

        $output = $appTester->getDisplay(true);
        self::assertStringContainsString('Generating autoload files', $output);
        self::assertStringContainsString('Generated autoload files', $output);
    }

    public function testDumpNoDevAutoload(): void
    {
        $appTester = $this->getApplicationTester();
        self::assertSame(0, $appTester->run(['command' => 'dump-autoload', '--dev' => true]));

        $output = $appTester->getDisplay(true);
        self::assertStringContainsString('Generating autoload files', $output);
        self::assertStringContainsString('Generated autoload files', $output);
    }

    public function testUsingOptimizeAndStrictPsr(): void
    {
        $appTester = $this->getApplicationTester();
        self::assertSame(0, $appTester->run(['command' => 'dump-autoload', '--optimize' => true, '--strict-psr' => true]));

        $output = $appTester->getDisplay(true);
        self::assertStringContainsString('Generating optimized autoload files', $output);
        self::assertMatchesRegularExpression('/Generated optimized autoload files containing \d+ classes/', $output);
    }

    public function testFailsUsingStrictPsrIfClassMapViolationsAreFound(): void
    {
        $dir = $this->initTempComposer([
            'autoload' => [
                'psr-4' => [
                    'Application\\' => 'src',
                ]
            ]
        ]);
        mkdir($dir . '/src/');
        file_put_contents($dir . '/src/Foo.php', '<?php namespace Application\Src; class Foo {}');
        $appTester = $this->getApplicationTester();
        self::assertSame(1, $appTester->run(['command' => 'dump-autoload', '--optimize' => true, '--strict-psr' => true]));

        $output = $appTester->getDisplay(true);
        self::assertMatchesRegularExpression('/Class Application\\\Src\\\Foo located in .*? does not comply with psr-4 autoloading standard. Skipping./', $output);
    }

    public function testUsingClassmapAuthoritative(): void
    {
        $appTester = $this->getApplicationTester();
        self::assertSame(0, $appTester->run(['command' => 'dump-autoload', '--classmap-authoritative' => true]));

        $output = $appTester->getDisplay(true);
        self::assertStringContainsString('Generating optimized autoload files (authoritative)', $output);
        self::assertMatchesRegularExpression('/Generated optimized autoload files \(authoritative\) containing \d+ classes/', $output);
    }

    public function testUsingClassmapAuthoritativeAndStrictPsr(): void
    {
        $appTester = $this->getApplicationTester();
        self::assertSame(0, $appTester->run(['command' => 'dump-autoload', '--classmap-authoritative' => true, '--strict-psr' => true]));

        $output = $appTester->getDisplay(true);
        self::assertStringContainsString('Generating optimized autoload files', $output);
        self::assertMatchesRegularExpression('/Generated optimized autoload files \(authoritative\) containing \d+ classes/', $output);
    }

    public function testStrictPsrDoesNotWorkWithoutOptimizedAutoloader(): void
    {
        $appTester = $this->getApplicationTester();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('--strict-psr mode only works with optimized autoloader, use --optimize or --classmap-authoritative if you want a strict return value.');
        $appTester->run(['command' => 'dump-autoload', '--strict-psr' => true]);
    }

    public function testDevAndNoDevCannotBeCombined(): void
    {
        $appTester = $this->getApplicationTester();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('You can not use both --no-dev and --dev as they conflict with each other.');
        $appTester->run(['command' => 'dump-autoload', '--dev' => true, '--no-dev' => true]);
    }

    public function testWithCustomAutoloaderSuffix(): void
    {
        $dir = $this->initTempComposer([
            'config' => [
                'autoloader-suffix' => 'Foobar',
            ],
        ]);

        $appTester = $this->getApplicationTester();
        self::assertSame(0, $appTester->run(['command' => 'dump-autoload']));

        self::assertStringContainsString('ComposerAutoloaderInitFoobar', (string) file_get_contents($dir . '/vendor/autoload.php'));
    }

    public function testWithExistingComposerLockAndAutoloaderSuffix(): void
    {
        $dir = $this->initTempComposer(
            [
                'config' => [
                    'autoloader-suffix' => 'Foobar',
                ],
            ],
            [],
            [
                "_readme" => [
                    "This file locks the dependencies of your project to a known state",
                    "Read more about it at https://getcomposer.org/doc/01-basic-usage.md#installing-dependencies",
                    "This file is @generated automatically"
                ],
                "content-hash" => "d751713988987e9331980363e24189ce",
                "packages" => [],
                "packages-dev" => [],
                "aliases" => [],
                "minimum-stability" => "stable",
                "stability-flags" => [],
                "prefer-stable" => false,
                "prefer-lowest" => false,
                "platform" => [],
                "platform-dev" => [],
                "plugin-api-version" => "2.6.0"
            ]
        );

        $appTester = $this->getApplicationTester();
        self::assertSame(0, $appTester->run(['command' => 'dump-autoload']));

        self::assertStringContainsString('ComposerAutoloaderInitFoobar', (string) file_get_contents($dir . '/vendor/autoload.php'));
    }

    public function testWithExistingComposerLockWithoutAutoloaderSuffix(): void
    {
        $dir = $this->initTempComposer(
            [
                'name' => 'foo/bar',
            ],
            [],
            [
                "_readme" => [
                    "This file locks the dependencies of your project to a known state",
                    "Read more about it at https://getcomposer.org/doc/01-basic-usage.md#installing-dependencies",
                    "This file is @generated automatically"
                ],
                "content-hash" => "2d4a6be9a93712c5d6a119b26734a047",
                "packages" => [],
                "packages-dev" => [],
                "aliases" => [],
                "minimum-stability" => "stable",
                "stability-flags" => [],
                "prefer-stable" => false,
                "prefer-lowest" => false,
                "platform" => [],
                "platform-dev" => [],
                "plugin-api-version" => "2.6.0"
            ]
        );

        $appTester = $this->getApplicationTester();
        self::assertSame(0, $appTester->run(['command' => 'dump-autoload']));

        self::assertStringContainsString('ComposerAutoloaderInit2d4a6be9a93712c5d6a119b26734a047', (string) file_get_contents($dir . '/vendor/autoload.php'));
    }
}
