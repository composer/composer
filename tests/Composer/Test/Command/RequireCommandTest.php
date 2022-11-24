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
            $this->assertMatchesRegularExpression($pattern, trim($appTester->getDisplay(true)));
        } else {
            $this->assertSame(trim($expected), trim($appTester->getDisplay(true)));
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
    }
}
