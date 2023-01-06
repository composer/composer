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
     */
    public function testHomeCommandWithShowFlag(
        array $composerJson,
        array $command,
        string $expected,
        string $url = ''
    ): void {
        $this->initTempComposer($composerJson);

        $packages = [
            self::getPackage('vendor/package', '1.2.3'),
        ];
        $devPackages = [
            self::getPackage('vendor/devpackage', '2.3.4'),
        ];

        if ($url) {
            foreach ($packages as $package) {
                $package->setHomepage($url);
            }

            foreach ($devPackages as $devPackage) {
                $devPackage->setHomepage($url);
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
            [],
            [],
            <<<OUTPUT
No package specified, opening homepage for the root package
Info from https://repo.packagist.org: #StandWithUkraine
<warning>Invalid or missing repository URL for __root__</warning>
OUTPUT
        ];

        yield 'Package not found' => [
            [],
            ['packages' => ['vendor/anotherpackage']],
            <<<OUTPUT
Info from https://repo.packagist.org: #StandWithUkraine
<warning>Package vendor/anotherpackage not found</warning>
<warning>Invalid or missing repository URL for vendor/anotherpackage</warning>
OUTPUT
        ];

        yield 'A valid package URL' => [
            [],
            ['packages' => ['vendor/package']],
            <<<OUTPUT
https://example.org
OUTPUT,
            'https://example.org',
        ];

        yield 'A valid dev package URL' => [
            [],
            ['packages' => ['vendor/devpackage']],
            <<<OUTPUT
https://example.org/dev
OUTPUT,
            'https://example.org/dev',
        ];
    }
}
