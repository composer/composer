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

class InstallCommandTest extends TestCase
{
    /**
     * @dataProvider useCaseProvider
     * @param array<mixed> $composerJson
     * @param array<mixed> $command
     */
    public function testInstallCommand(
        array $composerJson,
        array $command,
        string $expected,
        bool $lock = false
    ): void {
        $this->initTempComposer($composerJson);

        $packages = [
            self::getPackage('vendor/package', '1.2.3'),
        ];
        $devPackages = [
            self::getPackage('vendor/devpackage', '2.3.4'),
        ];

        if ($lock) {
            $this->createComposerLock($packages, $devPackages);
        }

        $this->createInstalledJson($packages, $devPackages);

        $appTester = $this->getApplicationTester();
        $appTester->run(array_merge(['command' => 'install'], $command));

        $this->assertSame(trim($expected), trim($appTester->getDisplay(true)));
    }

    public function useCaseProvider(): Generator
    {
        yield 'it writes an error when the dev flag is passed' => [
            [
                'repositories' => [
                    'packages' => [
                        'type' => 'package',
                        'package' => [
                            [
                                'name' => 'vendor/package',
                                'description' => 'generic description',
                                'version' => '1.0.0',
                                'dist' => [
                                    'url' =>  'https://example.org',
                                    'type' => 'zip'
                                ]
                            ],
                        ]
                    ]
                ],
                'require' => [
                    'vendor/package' => '^1.0'
                ]
            ],
            ['--dev' => true],
            <<<OUTPUT
<warning>You are using the deprecated option "--dev". It has no effect and will break in Composer 3.</warning>
Installing dependencies from lock file (including require-dev)
Verifying lock file contents can be installed on current platform.
Nothing to install, update or remove
Generating autoload files
OUTPUT
            ,
            true
        ];

        yield 'it writes an error when no-suggest flag passed' => [
            [
                'repositories' => [
                    'packages' => [
                        'type' => 'package',
                        'package' => [
                            [
                                'name' => 'vendor/package',
                                'description' => 'generic description',
                                'version' => '1.0.0',
                                'dist' => [
                                    'url' =>  'https://example.org',
                                    'type' => 'zip'
                                ]
                            ],
                        ]
                    ]
                ],
                'require' => [
                    'vendor/package' => '^1.0'
                ]
            ],
            ['--no-suggest' => true],
            <<<OUTPUT
<warning>You are using the deprecated option "--no-suggest". It has no effect and will break in Composer 3.</warning>
Installing dependencies from lock file (including require-dev)
Verifying lock file contents can be installed on current platform.
Nothing to install, update or remove
Generating autoload files
OUTPUT
            ,
            true
        ];

        yield 'it writes an error when packages passed' => [
            [
                'repositories' => [
                    'packages' => [
                        'type' => 'package',
                        'package' => [
                            [
                                'name' => 'vendor/package',
                                'description' => 'generic description',
                                'version' => '1.0.0',
                                'dist' => [
                                    'url' =>  'https://example.org',
                                    'type' => 'zip'
                                ]
                            ],
                        ]
                    ]
                ],
                'require' => [
                    'vendor/package' => '^1.0'
                ]
            ],
            ['packages' => ['vendor/package']],
            <<<OUTPUT
Invalid argument vendor/package. Use "composer require vendor/package" instead to add packages to your composer.json.
OUTPUT
            ,
            true
        ];

        yield 'it writes an error when no-install flag is passed' => [
            [
                'repositories' => [
                    'packages' => [
                        'type' => 'package',
                        'package' => [
                            [
                                'name' => 'vendor/package',
                                'description' => 'generic description',
                                'version' => '1.0.0',
                                'dist' => [
                                    'url' =>  'https://example.org',
                                    'type' => 'zip'
                                ]
                            ],
                        ]
                    ]
                ],
                'require' => [
                    'vendor/package' => '^1.0'
                ]
            ],
            ['--no-install' => true],
            <<<OUTPUT
Invalid option "--no-install". Use "composer update --no-install" instead if you are trying to update the composer.lock file.
OUTPUT
            ,
            true
        ];
    }
}
