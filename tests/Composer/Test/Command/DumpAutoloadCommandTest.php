<?php declare(strict_types=1);

namespace Composer\Test\Command;

use Composer\Test\TestCase;
use InvalidArgumentException;

class DumpAutoloadCommandTest extends TestCase
{
    public function testDumpAutoload(): void
    {
        $appTester = $this->getApplicationTester();
        $this->assertSame(0, $appTester->run(['command' => 'dump-autoload']));

        $output = $appTester->getDisplay(true);
        $this->assertStringContainsString('Generating autoload files', $output);
        $this->assertStringContainsString('Generated autoload files', $output);
    }

    public function testDumpDevAutoload(): void
    {
        $appTester = $this->getApplicationTester();
        $this->assertSame(0, $appTester->run(['command' => 'dump-autoload', '--dev' => true]));

        $output = $appTester->getDisplay(true);
        $this->assertStringContainsString('Generating autoload files', $output);
        $this->assertStringContainsString('Generated autoload files', $output);
    }

    public function testDumpNoDevAutoload(): void
    {
        $appTester = $this->getApplicationTester();
        $this->assertSame(0, $appTester->run(['command' => 'dump-autoload', '--dev' => true]));

        $output = $appTester->getDisplay(true);
        $this->assertStringContainsString('Generating autoload files', $output);
        $this->assertStringContainsString('Generated autoload files', $output);
    }

    public function testUsingOptimizeAndStrictPsr(): void
    {
        $appTester = $this->getApplicationTester();
        $this->assertSame(0, $appTester->run(['command' => 'dump-autoload', '--optimize' => true, '--strict-psr' => true]));

        $output = $appTester->getDisplay(true);
        $this->assertStringContainsString('Generating optimized autoload files', $output);
        $this->assertMatchesRegularExpression('/Generated optimized autoload files containing \d+ classes/', $output);
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
        $this->assertSame(1, $appTester->run(['command' => 'dump-autoload', '--optimize' => true, '--strict-psr' => true]));

        $output = $appTester->getDisplay(true);
        $this->assertMatchesRegularExpression('/Class Application\\\Src\\\Foo located in .*? does not comply with psr-4 autoloading standard. Skipping./', $output);
    }

    public function testUsingClassmapAuthoritative(): void
    {
        $appTester = $this->getApplicationTester();
        $this->assertSame(0, $appTester->run(['command' => 'dump-autoload', '--classmap-authoritative' => true]));

        $output = $appTester->getDisplay(true);
        $this->assertStringContainsString('Generating optimized autoload files (authoritative)', $output);
        $this->assertMatchesRegularExpression('/Generated optimized autoload files \(authoritative\) containing \d+ classes/', $output);
    }

    public function testUsingClassmapAuthoritativeAndStrictPsr(): void
    {
        $appTester = $this->getApplicationTester();
        $this->assertSame(0, $appTester->run(['command' => 'dump-autoload', '--classmap-authoritative' => true, '--strict-psr' => true]));

        $output = $appTester->getDisplay(true);
        $this->assertStringContainsString('Generating optimized autoload files', $output);
        $this->assertMatchesRegularExpression('/Generated optimized autoload files \(authoritative\) containing \d+ classes/', $output);
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
}
