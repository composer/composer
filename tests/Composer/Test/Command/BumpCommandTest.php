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

use Composer\Json\JsonFile;
use Composer\Test\TestCase;

class BumpCommandTest extends TestCase
{
    /**
     * @dataProvider provideTests
     * @param array<mixed> $composerJson
     * @param array<mixed> $command
     * @param array<mixed> $expected
     */
    public function testBump(array $composerJson, array $command, array $expected, bool $lock = true): void
    {
        $this->initTempComposer($composerJson);

        $packages = [
            $this->getPackage('first/pkg', '2.3.4'),
            $this->getPackage('second/pkg', '3.4.0'),
        ];
        $devPackages = [
            $this->getPackage('dev/pkg', '2.3.4.5'),
        ];

        $this->createInstalledJson($packages, $devPackages);
        if ($lock) {
            $this->createComposerLock($packages, $devPackages);
        }

        $appTester = $this->getApplicationTester();
        $appTester->run(array_merge(['command' => 'bump'], $command));

        $json = new JsonFile('./composer.json');
        $this->assertSame($expected, $json->read());
    }

    public function provideTests(): \Generator
    {
        yield 'bump all by default' => [
            [
                'require' => [
                    'first/pkg' => '^2.0',
                    'second/pkg' => '3.*',
                ],
                'require-dev' => [
                    'dev/pkg' => '~2.0',
                ],
            ],
            [],
            [
                'require' => [
                    'first/pkg' => '^2.3.4',
                    'second/pkg' => '^3.4',
                ],
                'require-dev' => [
                    'dev/pkg' => '^2.3.4.5',
                ],
            ],
        ];

        yield 'bump only dev with --dev-only' => [
            [
                'require' => [
                    'first/pkg' => '^2.0',
                    'second/pkg' => '3.*',
                ],
                'require-dev' => [
                    'dev/pkg' => '~2.0',
                ],
            ],
            ['--dev-only' => true],
            [
                'require' => [
                    'first/pkg' => '^2.0',
                    'second/pkg' => '3.*',
                ],
                'require-dev' => [
                    'dev/pkg' => '^2.3.4.5',
                ],
            ],
        ];

        yield 'bump only non-dev with --no-dev-only' => [
            [
                'require' => [
                    'first/pkg' => '^2.0',
                    'second/pkg' => '3.*',
                ],
                'require-dev' => [
                    'dev/pkg' => '~2.0',
                ],
            ],
            ['--no-dev-only' => true],
            [
                'require' => [
                    'first/pkg' => '^2.3.4',
                    'second/pkg' => '^3.4',
                ],
                'require-dev' => [
                    'dev/pkg' => '~2.0',
                ],
            ],
        ];

        yield 'bump works from installed repo without lock file' => [
            [
                'require' => [
                    'first/pkg' => '^2.0',
                    'second/pkg' => '3.*',
                ],
            ],
            [],
            [
                'require' => [
                    'first/pkg' => '^2.3.4',
                    'second/pkg' => '^3.4',
                ],
            ],
            false,
        ];
    }
}
