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

class FundCommandTest extends TestCase
{
    /**
     * @dataProvider useCaseProvider
     * @param  array<mixed>  $composerJson
     * @param  array<mixed>  $command
     * @param  array<mixed>  $funding
     */
    public function testFundCommand(
        array $composerJson,
        array $command,
        array $funding,
        string $expected
    ): void {
        $this->initTempComposer($composerJson);

        $packages = [
            'first/pkg' => self::getPackage('first/pkg', '2.3.4'),
            'stable/pkg' => self::getPackage('stable/pkg', '1.0.0'),
        ];
        $devPackages = [
            'dev/pkg' => self::getPackage('dev/pkg', '2.3.4.5')
        ];

        if (count($funding) !== 0) {
            foreach ($funding as $pkg => $fundingInfo) {
                if (isset($packages[$pkg])) {
                    $packages[$pkg]->setFunding([$fundingInfo]);
                }
                if (isset($devPackages[$pkg])) {
                    $devPackages[$pkg]->setFunding([$fundingInfo]);
                }
            }
        }

        $this->createInstalledJson($packages, $devPackages);

        $appTester = $this->getApplicationTester();
        $appTester->run(array_merge(['command' => 'fund'], $command));

        $appTester->assertCommandIsSuccessful();
        $this->assertSame(trim($expected), trim($appTester->getDisplay(true)));
    }

    public static function useCaseProvider(): Generator
    {
        yield 'no funding links present, locally or remotely' => [
            [
                'repositories' => [],
                'require' => [
                    'first/pkg' => '^2.0',
                ],
                'require-dev' => [
                    'dev/pkg' => '~4.0',
                ]
            ],
            [],
            [],
            "No funding links were found in your package dependencies. This doesn't mean they don't need your support!"
        ];

        yield 'funding links set locally are used as fallback if not found remotely' => [
            [
                'repositories' => [],
                'require' => [
                    'first/pkg' => '^2.0',
                ],
                'require-dev' => [
                    'dev/pkg' => '~4.0',
                ]
            ],
            [],
            [
                'first/pkg' => [
                    'type' => 'github',
                    'url' => 'https://github.com/composer-test-data'
                ],
                'dev/pkg' => [
                    'type' => 'github',
                    'url' => 'https://github.com/composer-test-data-dev'
                ]
            ],
            "The following packages were found in your dependencies which publish funding information:

dev
  pkg
    https://github.com/sponsors/composer-test-data-dev

first
    https://github.com/sponsors/composer-test-data

Please consider following these links and sponsoring the work of package authors!
Thank you!"
        ];

        yield 'funding links set remotely are used as primary if found' => [
            [
                'repositories' => [
                    [
                        'type' => 'package',
                        'package' => [
                            // should not be used as there is a default branch version of this package available
                            ['name' => 'first/pkg', 'version' => 'dev-foo', 'funding' => [['type' => 'github', 'url' => 'https://github.com/test-should-not-be-used']]],
                            // should be used as default branch from remote repo takes precedence
                            ['name' => 'first/pkg', 'version' => 'dev-main', 'default-branch' => true, 'funding' => [['type' => 'custom', 'url' => 'https://example.org']]],
                            // should be used as default branch from remote repo takes precedence
                            ['name' => 'dev/pkg', 'version' => 'dev-foo', 'default-branch' => true,  'funding' => [['type' => 'github', 'url' => 'https://github.com/org']]],
                            // no default branch available so falling back to locally installed data
                            ['name' => 'stable/pkg', 'version' => '1.0.0', 'funding' => [['type' => 'github', 'url' => 'org2']]],
                        ],
                    ]
                ],
                'require' => [
                    'first/pkg' => '^2.0',
                    'stable/pkg' => '^1.0',
                ],
                'require-dev' => [
                    'dev/pkg' => '~4.0',
                ]
            ],
            [],
            [
                'first/pkg' => [
                    'type' => 'github',
                    'url' => 'https://github.com/composer-test-data'
                ],
                'dev/pkg' => [
                    'type' => 'github',
                    'url' => 'https://github.com/composer-test-data-dev'
                ],
                'stable/pkg' => [
                    'type' => 'github',
                    'url' => 'https://github.com/composer-test-data-stable'
                ]
            ],
            "The following packages were found in your dependencies which publish funding information:

dev
  pkg
    https://github.com/sponsors/org

first
    https://example.org

stable
    https://github.com/sponsors/composer-test-data-stable

Please consider following these links and sponsoring the work of package authors!
Thank you!"
        ];

        yield 'format funding links as JSON' => [
            [
                'repositories' => [],
                'require' => [
                    'first/pkg' => '^2.0',
                ],
                'require-dev' => [
                    'dev/pkg' => '~4.0',
                ]
            ],
            ['--format' => 'json'],
            [
                'first/pkg' => [
                    'type' => 'github',
                    'url' => 'https://github.com/composer-test-data'
                ],
                'dev/pkg' => [
                    'type' => 'github',
                    'url' => 'https://github.com/composer-test-data-dev'
                ]
            ],
            '{
    "dev": {
        "https://github.com/sponsors/composer-test-data-dev": [
            "pkg"
        ]
    },
    "first": {
        "https://github.com/sponsors/composer-test-data": [
            "pkg"
        ]
    }
}'
        ];
    }
}
