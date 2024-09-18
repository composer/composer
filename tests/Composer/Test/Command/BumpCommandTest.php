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
    public function testBump(array $composerJson, array $command, array $expected, bool $lock = true, int $exitCode = 0): void
    {
        $this->initTempComposer($composerJson);

        $packages = [
            self::getPackage('first/pkg', '2.3.4'),
            self::getPackage('second/pkg', '3.4.0'),
        ];
        $devPackages = [
            self::getPackage('dev/pkg', '2.3.4.5'),
        ];

        $this->createInstalledJson($packages, $devPackages);
        if ($lock) {
            $this->createComposerLock($packages, $devPackages);
        }

        $appTester = $this->getApplicationTester();
        self::assertSame($exitCode, $appTester->run(array_merge(['command' => 'bump'], $command)));

        $json = new JsonFile('./composer.json');
        self::assertSame($expected, $json->read());
    }

    public function testBumpFailsOnNonExistingComposerFile(): void
    {
        $dir = $this->initTempComposer([]);
        $composerJsonPath = $dir . '/composer.json';
        unlink($composerJsonPath);

        $appTester = $this->getApplicationTester();
        self::assertSame(1, $appTester->run(['command' => 'bump'], ['capture_stderr_separately' => true]));

        self::assertStringContainsString("./composer.json is not readable.", $appTester->getErrorOutput());
    }

    public function testBumpFailsOnWriteErrorToComposerFile(): void
    {
        $dir = $this->initTempComposer([]);
        $composerJsonPath = $dir . '/composer.json';
        chmod($composerJsonPath, 0444);

        $appTester = $this->getApplicationTester();
        self::assertSame(1, $appTester->run(['command' => 'bump'], ['capture_stderr_separately' => true]));

        self::assertStringContainsString("./composer.json is not writable.", $appTester->getErrorOutput());
    }

    public static function provideTests(): \Generator
    {
        yield 'bump all by default' => [
            [
                'require' => [
                    'first/pkg' => '^v2.0',
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

        yield 'bump only listed with packages arg' => [
            [
                'require' => [
                    'first/pkg' => '^2.0',
                    'second/pkg' => '3.*',
                ],
                'require-dev' => [
                    'dev/pkg' => '~2.0',
                ],
            ],
            ['packages' => ['first/pkg', 'dev/*']],
            [
                'require' => [
                    'first/pkg' => '^2.3.4',
                    'second/pkg' => '3.*',
                ],
                'require-dev' => [
                    'dev/pkg' => '^2.3.4.5',
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

        yield 'bump with --dry-run with packages to bump' => [
            [
                'require' => [
                    'first/pkg' => '^2.0',
                    'second/pkg' => '3.*',
                ],
                'require-dev' => [
                    'dev/pkg' => '~2.0',
                ],
            ],
            ['--dry-run' => true],
            [
                'require' => [
                    'first/pkg' => '^2.0',
                    'second/pkg' => '3.*',
                ],
                'require-dev' => [
                    'dev/pkg' => '~2.0',
                ],
            ],
            true,
            1,
        ];

        yield 'bump with --dry-run without packages to bump' => [
            [
                'require' => [
                    'first/pkg' => '^2.3.4',
                    'second/pkg' => '^3.4',
                ],
                'require-dev' => [
                    'dev/pkg' => '^2.3.4.5',
                ],
            ],
            ['--dry-run' => true],
            [
                'require' => [
                    'first/pkg' => '^2.3.4',
                    'second/pkg' => '^3.4',
                ],
                'require-dev' => [
                    'dev/pkg' => '^2.3.4.5',
                ],
            ],
            true,
            0,
        ];

        yield 'bump works with non-standard package' => [
            [
                'require' => [
                    'php' => '>=5.3',
                    'first/pkg' => '^2.3.4',
                    'second/pkg' => '^3.4',
                ],
                'require-dev' => [
                    'dev/pkg' => '^2.3.4.5',
                ],
            ],
            [],
            [
                'require' => [
                    'php' => '>=5.3',
                    'first/pkg' => '^2.3.4',
                    'second/pkg' => '^3.4',
                ],
                'require-dev' => [
                    'dev/pkg' => '^2.3.4.5',
                ],
            ],
        ];

        yield 'bump works with unknown package' => [
            [
                'require' => [
                    'first/pkg' => '^2.3.4',
                    'second/pkg' => '^3.4',
                    'third/pkg' => '^1.2',
                ],
            ],
            [],
            [
                'require' => [
                    'first/pkg' => '^2.3.4',
                    'second/pkg' => '^3.4',
                    'third/pkg' => '^1.2',
                ],
            ],
        ];

        yield 'bump works with aliased package' => [
            [
                'require' => [
                    'first/pkg' => '^2.3.4',
                    'second/pkg' => 'dev-bugfix as 3.4.x-dev',
                ],
            ],
            [],
            [
                'require' => [
                    'first/pkg' => '^2.3.4',
                    'second/pkg' => 'dev-bugfix as 3.4.x-dev',
                ],
            ],
        ];
    }
}
