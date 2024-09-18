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
use InvalidArgumentException;

class RequireCommandTest extends TestCase
{
    public function testRequireThrowsIfNoneMatches(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Package required/pkg has requirements incompatible with your PHP version, PHP extensions and Composer version:' . PHP_EOL .
            '  - required/pkg 1.0.0 requires ext-foobar ^1 but it is not present.'
        );

        $this->initTempComposer([
            'repositories' => [
                'packages' => [
                    'type' => 'package',
                    'package' => [
                        ['name' => 'required/pkg', 'version' => '1.0.0', 'require' => ['ext-foobar' => '^1']],
                    ],
                ],
            ],
        ]);

        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => 'require', '--dry-run' => true, '--no-audit' => true, 'packages' => ['required/pkg']]);
    }

    public function testRequireWarnsIfResolvedToFeatureBranch(): void
    {
        $this->initTempComposer([
            'repositories' => [
                'packages' => [
                    'type' => 'package',
                    'package' => [
                        ['name' => 'required/pkg', 'version' => '2.0.0', 'require' => ['common/dep' => '^1']],
                        ['name' => 'required/pkg', 'version' => 'dev-foo-bar', 'require' => ['common/dep' => '^2']],
                        ['name' => 'common/dep', 'version' => '2.0.0'],
                    ],
                ],
            ],
            'require' => [
                'common/dep' => '^2.0',
            ],
            'minimum-stability' => 'dev',
            'prefer-stable' => true,
        ]);

        $appTester = $this->getApplicationTester();
        $appTester->setInputs(['n']);
        $appTester->run(['command' => 'require', '--dry-run' => true, '--no-audit' => true, 'packages' => ['required/pkg']], ['interactive' => true]);
        self::assertSame(
'./composer.json has been updated
Running composer update required/pkg
Loading composer repositories with package information
Updating dependencies
Lock file operations: 2 installs, 0 updates, 0 removals
  - Locking common/dep (2.0.0)
  - Locking required/pkg (dev-foo-bar)
Installing dependencies from lock file (including require-dev)
Package operations: 2 installs, 0 updates, 0 removals
  - Installing common/dep (2.0.0)
  - Installing required/pkg (dev-foo-bar)
Using version dev-foo-bar for required/pkg
<warning>Version dev-foo-bar looks like it may be a feature branch which is unlikely to keep working in the long run and may be in an unstable state</warning>
Are you sure you want to use this constraint (Y) or would you rather abort (n) the whole operation [Y,n]? '.'
Installation failed, reverting ./composer.json to its original content.
', $appTester->getDisplay(true));
    }

    /**
     * @dataProvider provideRequire
     * @param array<mixed> $composerJson
     * @param array<mixed> $command
     */
    public function testRequire(array $composerJson, array $command, string $expected): void
    {
        $this->initTempComposer($composerJson);

        $appTester = $this->getApplicationTester();
        $appTester->run(array_merge(['command' => 'require', '--dry-run' => true, '--no-audit' => true], $command));

        if (str_contains($expected, '%d')) {
            $pattern = '{^'.str_replace('%d', '[0-9.]+', preg_quote(trim($expected))).'$}';
            self::assertMatchesRegularExpression($pattern, trim($appTester->getDisplay(true)));
        } else {
            self::assertSame(trim($expected), trim($appTester->getDisplay(true)));
        }
    }

    public static function provideRequire(): \Generator
    {
        yield 'warn once for missing ext but a lower package matches' => [
            [
                'repositories' => [
                    'packages' => [
                        'type' => 'package',
                        'package' => [
                            ['name' => 'required/pkg', 'version' => '1.2.0', 'require' => ['ext-foobar' => '^1']],
                            ['name' => 'required/pkg', 'version' => '1.1.0', 'require' => ['ext-foobar' => '^1']],
                            ['name' => 'required/pkg', 'version' => '1.0.0'],
                        ],
                    ],
                ],
            ],
            ['packages' => ['required/pkg']],
            <<<OUTPUT
<warning>Cannot use required/pkg's latest version 1.2.0 as it requires ext-foobar ^1 which is missing from your platform.
./composer.json has been updated
Running composer update required/pkg
Loading composer repositories with package information
Updating dependencies
Lock file operations: 1 install, 0 updates, 0 removals
  - Locking required/pkg (1.0.0)
Installing dependencies from lock file (including require-dev)
Package operations: 1 install, 0 updates, 0 removals
  - Installing required/pkg (1.0.0)
Using version ^1.0 for required/pkg
OUTPUT
        ];

        yield 'warn multiple times when verbose' => [
            [
                'repositories' => [
                    'packages' => [
                        'type' => 'package',
                        'package' => [
                            ['name' => 'required/pkg', 'version' => '1.2.0', 'require' => ['ext-foobar' => '^1']],
                            ['name' => 'required/pkg', 'version' => '1.1.0', 'require' => ['ext-foobar' => '^1']],
                            ['name' => 'required/pkg', 'version' => '1.0.0'],
                        ],
                    ],
                ],
            ],
            ['packages' => ['required/pkg'], '--no-install' => true, '-v' => true],
            <<<OUTPUT
<warning>Cannot use required/pkg's latest version 1.2.0 as it requires ext-foobar ^1 which is missing from your platform.
<warning>Cannot use required/pkg 1.1.0 as it requires ext-foobar ^1 which is missing from your platform.
./composer.json has been updated
Running composer update required/pkg
Loading composer repositories with package information
Updating dependencies
Dependency resolution completed in %d seconds
Analyzed %d packages to resolve dependencies
Analyzed %d rules to resolve dependencies
Lock file operations: 1 install, 0 updates, 0 removals
Installs: required/pkg:1.0.0
  - Locking required/pkg (1.0.0)
Using version ^1.0 for required/pkg
OUTPUT
        ];

        yield 'warn for not satisfied req which is satisfied by lower version' => [
            [
                'repositories' => [
                    'packages' => [
                        'type' => 'package',
                        'package' => [
                            ['name' => 'required/pkg', 'version' => '1.1.0', 'require' => ['php' => '^20']],
                            ['name' => 'required/pkg', 'version' => '1.0.0', 'require' => ['php' => '>=7']],
                        ],
                    ],
                ],
            ],
            ['packages' => ['required/pkg'], '--no-install' => true],
            <<<OUTPUT
<warning>Cannot use required/pkg's latest version 1.1.0 as it requires php ^20 which is not satisfied by your platform.
./composer.json has been updated
Running composer update required/pkg
Loading composer repositories with package information
Updating dependencies
Lock file operations: 1 install, 0 updates, 0 removals
  - Locking required/pkg (1.0.0)
Using version ^1.0 for required/pkg
OUTPUT
        ];

        yield 'version selection happens early even if not completely accurate if no update is requested' => [
            [
                'repositories' => [
                    'packages' => [
                        'type' => 'package',
                        'package' => [
                            ['name' => 'required/pkg', 'version' => '1.1.0', 'require' => ['php' => '^20']],
                            ['name' => 'required/pkg', 'version' => '1.0.0', 'require' => ['php' => '>=7']],
                        ],
                    ],
                ],
            ],
            ['packages' => ['required/pkg'], '--no-update' => true],
            <<<OUTPUT
<warning>Cannot use required/pkg's latest version 1.1.0 as it requires php ^20 which is not satisfied by your platform.
Using version ^1.0 for required/pkg
./composer.json has been updated
OUTPUT
        ];

        yield 'pick best matching version when not provided' => [
            [
                'repositories' => [
                    'packages' => [
                        'type' => 'package',
                        'package' => [
                            ['name' => 'existing/dep', 'version' => '1.1.0', 'require' => ['required/pkg' => '^1']],
                            ['name' => 'required/pkg', 'version' => '2.0.0'],
                            ['name' => 'required/pkg', 'version' => '1.1.0'],
                            ['name' => 'required/pkg', 'version' => '1.0.0'],
                        ],
                    ],
                ],
                'require' => [
                    'existing/dep' => '^1'
                ],
            ],
            ['packages' => ['required/pkg'], '--no-install' => true],
            <<<OUTPUT
./composer.json has been updated
Running composer update required/pkg
Loading composer repositories with package information
Updating dependencies
Lock file operations: 2 installs, 0 updates, 0 removals
  - Locking existing/dep (1.1.0)
  - Locking required/pkg (1.1.0)
Using version ^1.1 for required/pkg
OUTPUT
        ];

        yield 'use exact constraint with --fixed' => [
            [
                'type' => 'project',
                'repositories' => [
                    'packages' => [
                        'type' => 'package',
                        'package' => [
                            ['name' => 'required/pkg', 'version' => '1.1.0'],
                        ],
                    ],
                ],
            ],
            ['packages' => ['required/pkg'], '--no-install' => true, '--fixed' => true],
            <<<OUTPUT
./composer.json has been updated
Running composer update required/pkg
Loading composer repositories with package information
Updating dependencies
Lock file operations: 1 install, 0 updates, 0 removals
  - Locking required/pkg (1.1.0)
Using version 1.1.0 for required/pkg
OUTPUT
        ];
    }

    /**
     * @dataProvider provideInconsistentRequireKeys
     * @param bool $isDev
     * @param bool $isInteractive
     * @param string $expectedWarning
     */
    public function testInconsistentRequireKeys(bool $isDev, bool $isInteractive, string $expectedWarning): void
    {
        $currentKey = $isDev ? "require" : "require-dev";
        $otherKey = $isDev ? "require-dev" : "require";

        $dir = $this->initTempComposer([
            'repositories' => [
                'packages' => [
                    'type' => 'package',
                    'package' => [
                        ['name' => 'required/pkg', 'version' => '1.0.0'],
                    ],
                ],
            ],
            $currentKey => [
                "required/pkg" => "^1.0",
            ],
        ]);

        $package = self::getPackage('required/pkg');
        if ($isDev) {
            $this->createComposerLock([], [$package]);
            $this->createInstalledJson([], [$package]);
        } else {
            $this->createComposerLock([$package], []);
            $this->createInstalledJson([$package], []);
        }

        $appTester = $this->getApplicationTester();
        $command = [
            'command' => 'require',
            '--no-audit' => true,
            '--dev' => $isDev,
            '--no-install' => true,
            'packages' => ['required/pkg']
        ];

        if ($isInteractive)
            $appTester->setInputs(['yes']);
        else
            $command['--no-interaction'] = true;

        $appTester->run($command);

        self::assertStringContainsString(
            $expectedWarning,
            $appTester->getDisplay(true)
        );

        $composer_content = (new JsonFile($dir . '/composer.json'))->read();
        self::assertArrayHasKey($otherKey, $composer_content);
        self::assertArrayNotHasKey($currentKey, $composer_content);
    }

    public function provideInconsistentRequireKeys(): \Generator
    {
        yield [
            true,
            false,
            '<warning>required/pkg is currently present in the require key and you ran the command with the --dev flag, which will move it to the require-dev key.</warning>'
        ];

        yield [
            false,
            false,
            '<warning>required/pkg is currently present in the require-dev key and you ran the command without the --dev flag, which will move it to the require key.</warning>'
        ];

        yield [
            true,
            true,
            '<warning>required/pkg is currently present in the require key and you ran the command with the --dev flag, which will move it to the require-dev key.</warning>'
        ];

        yield [
            false,
            true,
            '<warning>required/pkg is currently present in the require-dev key and you ran the command without the --dev flag, which will move it to the require key.</warning>'
        ];
    }
}
