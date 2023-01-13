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
use Generator;

class HomeCommandTest extends TestCase
{
    /**
     * @dataProvider useCaseProvider
     * @param array<mixed> $composerJson
     * @param array<mixed> $command
     * @param array<mixed> $urls
     */
    public function testHomeCommandWithShowFlag(
        array $composerJson,
        array $command,
        string $expected,
        array $urls = []
    ): void {
        $this->initTempComposer($composerJson);

        $packages = [
            'vendor/package' => self::getPackage('vendor/package', '1.2.3'),
        ];
        $devPackages = [
            'vendor/devpackage' => self::getPackage('vendor/devpackage', '2.3.4'),
        ];

        if (count($urls) !== 0) {
            foreach ($urls as $pkg => $url) {
                if (isset($packages[$pkg])) {
                    $packages[$pkg]->setHomepage($url);
                }
                if (isset($devPackages[$pkg])) {
                    $devPackages[$pkg]->setHomepage($url);
                }
            }
        }

        $this->createInstalledJson($packages, $devPackages);

        $appTester = $this->getApplicationTester();
        $appTester->run(array_merge(['command' => 'home', '--show' => true], $command));

        $this->assertSame(trim($expected), trim($appTester->getDisplay(true)));
    }

    public function useCaseProvider(): Generator
    {
        yield 'Invalid or missing repository URL' => [
            [
                'repositories' => [
                    'packages' => [
                        'type' => 'package',
                        'package' => [
                            ['name' => 'vendor/package', 'description' => 'generic description', 'version' => '1.0.0'],
                        ]
                    ]
                ],
                'require' => [
                    'vendor/package' => '^1.0'
                ]
            ],
            ['packages' => ['vendor/package']],
            <<<OUTPUT
<warning>Invalid or missing repository URL for vendor/package</warning>
OUTPUT
        ];

        yield 'No Packages Provided' => [
            ['repositories' => []],
            [],
            <<<OUTPUT
No package specified, opening homepage for the root package
<warning>Invalid or missing repository URL for __root__</warning>
OUTPUT
        ];

        yield 'Package not found' => [
            ['repositories' => []],
            ['packages' => ['vendor/anotherpackage']],
            <<<OUTPUT
<warning>Package vendor/anotherpackage not found</warning>
<warning>Invalid or missing repository URL for vendor/anotherpackage</warning>
OUTPUT
        ];

        yield 'A valid package URL' => [
            ['repositories' => []],,
            ['packages' => ['vendor/package']],
            <<<OUTPUT
https://example.org
OUTPUT
        ,
            ['vendor/package' => 'https://example.org'],
        ];

        yield 'A valid dev package URL' => [
            ['repositories' => []],,
            ['packages' => ['vendor/devpackage']],
            <<<OUTPUT
https://example.org/dev
OUTPUT
        ,
            ['vendor/devpackage' => 'https://example.org/dev'],
        ];
    }
}
