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
            $this->getPackage('first/pkg', '2.3.4'),
        ];
        $devPackages = [
            $this->getPackage('dev/pkg', '2.3.4.5')
        ];

        if (count($funding) !== 0) {
            $packages[0]->setFunding([$funding['first']]);
            $devPackages[0]->setFunding([$funding['dev']]);
        }

        $this->createInstalledJson($packages, $devPackages);

        $appTester = $this->getApplicationTester();
        $appTester->run(array_merge(['command' => 'fund'], $command));

        $appTester->assertCommandIsSuccessful();
        $this->assertSame(trim($expected), trim($appTester->getDisplay(true)));
    }

    public function useCaseProvider(): Generator
    {
        yield 'no funding links set' => [
            [
                'require' => [
                    'first/pkg' => '^2.0',
                ],
                'require-dev' => [
                    'dev/pkg' => '~4.0',
                ]
            ],
            [],
            [],
            "Info from https://repo.packagist.org: #StandWithUkraine
No funding links were found in your package dependencies. This doesn't mean they don't need your support!"
        ];
        yield 'funding links set' => [
            [
                'require' => [
                    'first/pkg' => '^2.0',
                ],
                'require-dev' => [
                    'dev/pkg' => '~4.0',
                ]
            ],
            [],
            [
                'first' => [
                    'type' => 'github',
                    'url' => 'https://github.com/composer-test-data'
                ],
                'dev' => [
                    'type' => 'github',
                    'url' => 'https://github.com/composer-test-data-dev'
                ]
            ],
            "Info from https://repo.packagist.org: #StandWithUkraine
The following packages were found in your dependencies which publish funding information:

dev
  pkg
    https://github.com/sponsors/composer-test-data-dev

first
    https://github.com/sponsors/composer-test-data

Please consider following these links and sponsoring the work of package authors!
Thank you!"
        ];
        yield 'format funding links as JSON' => [
            [
                'require' => [
                    'first/pkg' => '^2.0',
                ],
                'require-dev' => [
                    'dev/pkg' => '~4.0',
                ]
            ],
            ['--format' => 'json'],
            [
                'first' => [
                    'type' => 'github',
                    'url' => 'https://github.com/composer-test-data'
                ],
                'dev' => [
                    'type' => 'github',
                    'url' => 'https://github.com/composer-test-data-dev'
                ]
            ],
            'Info from https://repo.packagist.org: #StandWithUkraine
{
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
