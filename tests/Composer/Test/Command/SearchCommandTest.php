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

use Composer\Test\TestCase;
use InvalidArgumentException;

class SearchCommandTest extends TestCase
{
    /**
     * @dataProvider provideSearch
     * @param array<mixed> $command
     */
    public function testSearch(array $command, string $expected = ''): void
    {
        $this->initTempComposer([
            'repositories' => [
                'packages' => [
                    'type' => 'package',
                    'package' => [
                        ['name' => 'vendor-1/package-1', 'description' => 'generic description', 'version' => '1.0.0'],
                        ['name' => 'foo/bar', 'description' => 'generic description', 'version' => '1.0.0'],
                        ['name' => 'bar/baz', 'description' => 'fancy baz', 'version' => '1.0.0', 'abandoned' => true],
                        ['name' => 'vendor-2/fancy-package', 'fancy description', 'version' => '1.0.0', 'type' => 'foo'],
                    ],
                ],
            ],
        ]);

        $appTester = $this->getApplicationTester();
        $appTester->run(array_merge(['command' => 'search'], $command));
        self::assertSame(trim($expected), trim($appTester->getDisplay(true)));
    }

    public function testInvalidFormat(): void
    {
        $this->initTempComposer([
            'repositories' => [
                'packagist.org' => false,
            ],
        ]);

        $appTester = $this->getApplicationTester();
        $result = $appTester->run(['command' => 'search', '--format' => 'test-format', 'tokens' => ['test']]);
        self::assertSame(1, $result);
        self::assertSame('Unsupported format "test-format". See help for supported formats.', trim($appTester->getDisplay(true)));
    }

    public function testInvalidFlags(): void
    {
        $this->initTempComposer([
            'repositories' => [
                'packagist.org' => false,
            ],
        ]);

        $appTester = $this->getApplicationTester();
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('--only-name and --only-vendor cannot be used together');
        $appTester->run(['command' => 'search', '--only-vendor' => true, '--only-name' => true, 'tokens' => ['test']]);
    }

    public static function provideSearch(): \Generator
    {
        yield 'by name and description' => [
            ['tokens' => ['fancy']],
            <<<OUTPUT
bar/baz                <warning>! Abandoned !</warning> fancy baz
vendor-2/fancy-package
OUTPUT
        ];

        yield 'by name and description with multiple tokens' => [
            ['tokens' => ['fancy', 'vendor']],
            <<<OUTPUT
vendor-1/package-1     generic description
bar/baz                <warning>! Abandoned !</warning> fancy baz
vendor-2/fancy-package
OUTPUT
        ];

        yield 'by name only' => [
            ['tokens' => ['fancy'], '--only-name' => true],
            <<<OUTPUT
vendor-2/fancy-package
OUTPUT
        ];

        yield 'by vendor only' => [
            ['tokens' => ['bar'], '--only-vendor' => true],
            <<<OUTPUT
bar
OUTPUT
        ];

        yield 'by type' => [
            ['tokens' => ['vendor'], '--type' => 'foo'],
            <<<OUTPUT
vendor-2/fancy-package
OUTPUT
        ];

        yield 'json format' => [
            ['tokens' => ['vendor-2/fancy'], '--format' => 'json'],
            <<<OUTPUT
[
    {
        "name": "vendor-2/fancy-package",
        "description": null
    }
]
OUTPUT
        ];

        yield 'no results' => [
            ['tokens' => ['invalid-package-name']],
        ];
    }
}
