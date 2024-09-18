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

use Composer\Package\Link;
use Composer\Semver\Constraint\MatchAllConstraint;
use Composer\Test\TestCase;
use InvalidArgumentException;

class UpdateCommandTest extends TestCase
{
    /**
     * @dataProvider provideUpdates
     * @param array<mixed> $composerJson
     * @param array<mixed> $command
     */
    public function testUpdate(array $composerJson, array $command, string $expected, bool $createLock = false): void
    {
        $this->initTempComposer($composerJson);

        if ($createLock) {
            $this->createComposerLock();
        }

        $appTester = $this->getApplicationTester();
        $appTester->run(array_merge(['command' => 'update', '--dry-run' => true, '--no-audit' => true], $command));

        self::assertStringMatchesFormat(trim($expected), trim($appTester->getDisplay(true)));
    }

    public static function provideUpdates(): \Generator
    {
        $rootDepAndTransitiveDep = [
            'repositories' => [
                'packages' => [
                    'type' => 'package',
                    'package' => [
                        ['name' => 'root/req', 'version' => '1.0.0', 'require' => ['dep/pkg' => '^1']],
                        ['name' => 'dep/pkg', 'version' => '1.0.0'],
                        ['name' => 'dep/pkg', 'version' => '1.0.1'],
                        ['name' => 'dep/pkg', 'version' => '1.0.2'],
                    ],
                ],
            ],
            'require' => [
                'root/req' => '1.*',
            ],
        ];

        yield 'simple update' => [
            $rootDepAndTransitiveDep,
            [],
            <<<OUTPUT
Loading composer repositories with package information
Updating dependencies
Lock file operations: 2 installs, 0 updates, 0 removals
  - Locking dep/pkg (1.0.2)
  - Locking root/req (1.0.0)
Installing dependencies from lock file (including require-dev)
Package operations: 2 installs, 0 updates, 0 removals
  - Installing dep/pkg (1.0.2)
  - Installing root/req (1.0.0)
OUTPUT
        ];

        yield 'simple update with very verbose output' => [
            $rootDepAndTransitiveDep,
            ['-vv' => true],
            <<<OUTPUT
Loading composer repositories with package information
Pool optimizer completed in %f seconds
Found %d package versions referenced in your dependency graph. %d (%d%%) were optimized away.
Updating dependencies
Dependency resolution completed in %f seconds
Analyzed %d packages to resolve dependencies
Analyzed %d rules to resolve dependencies
Lock file operations: 2 installs, 0 updates, 0 removals
Installs: dep/pkg:1.0.2, root/req:1.0.0
  - Locking dep/pkg (1.0.2) from package repo (defining 4 packages)
  - Locking root/req (1.0.0) from package repo (defining 4 packages)
Installing dependencies from lock file (including require-dev)
Package operations: 2 installs, 0 updates, 0 removals
Installs: dep/pkg:1.0.2, root/req:1.0.0
  - Installing dep/pkg (1.0.2)
  - Installing root/req (1.0.0)
OUTPUT
        ];

        yield 'update with temporary constraint + --no-install' => [
            $rootDepAndTransitiveDep,
            ['--with' => ['dep/pkg:1.0.0'], '--no-install' => true],
            <<<OUTPUT
Loading composer repositories with package information
Updating dependencies
Lock file operations: 2 installs, 0 updates, 0 removals
  - Locking dep/pkg (1.0.0)
  - Locking root/req (1.0.0)
OUTPUT
        ];

        yield 'update with temporary constraint failing resolution' => [
            $rootDepAndTransitiveDep,
            ['--with' => ['dep/pkg:^2']],
            <<<OUTPUT
Loading composer repositories with package information
Updating dependencies
Your requirements could not be resolved to an installable set of packages.

  Problem 1
    - Root composer.json requires root/req 1.* -> satisfiable by root/req[1.0.0].
    - root/req 1.0.0 requires dep/pkg ^1 -> found dep/pkg[1.0.0, 1.0.1, 1.0.2] but it conflicts with your temporary update constraint (dep/pkg:^2).
OUTPUT
        ];

        yield 'update with temporary constraint failing resolution on root package' => [
            $rootDepAndTransitiveDep,
            ['--with' => ['root/req:^2']],
            <<<OUTPUT
The temporary constraint "^2" for "root/req" must be a subset of the constraint in your composer.json (1.*)
Run `composer require root/req` or `composer require root/req:^2` instead to replace the constraint
OUTPUT
        ];

        yield 'update & bump' => [
            $rootDepAndTransitiveDep,
            ['--bump-after-update' => true],
            <<<OUTPUT
Loading composer repositories with package information
Updating dependencies
Lock file operations: 2 installs, 0 updates, 0 removals
  - Locking dep/pkg (1.0.2)
  - Locking root/req (1.0.0)
Installing dependencies from lock file (including require-dev)
Package operations: 2 installs, 0 updates, 0 removals
  - Installing dep/pkg (1.0.2)
  - Installing root/req (1.0.0)
Bumping dependencies
<warning>Warning: Bumping dependency constraints is not recommended for libraries as it will narrow down your dependencies and may cause problems for your users.</warning>
<warning>If your package is not a library, you can explicitly specify the "type" by using "composer config type project".</warning>
<warning>Alternatively you can use --dev-only to only bump dependencies within "require-dev".</warning>
No requirements to update in ./composer.json.
OUTPUT
            , true
        ];

        yield 'update & bump dev only' => [
            $rootDepAndTransitiveDep,
            ['--bump-after-update' => 'dev'],
            <<<OUTPUT
Loading composer repositories with package information
Updating dependencies
Lock file operations: 2 installs, 0 updates, 0 removals
  - Locking dep/pkg (1.0.2)
  - Locking root/req (1.0.0)
Installing dependencies from lock file (including require-dev)
Package operations: 2 installs, 0 updates, 0 removals
  - Installing dep/pkg (1.0.2)
  - Installing root/req (1.0.0)
Bumping dependencies
No requirements to update in ./composer.json.
OUTPUT
            , true
        ];

        yield 'update & dump with failing update' => [
            $rootDepAndTransitiveDep,
            ['--with' => ['dep/pkg:^2'], '--bump-after-update' => true],
            <<<OUTPUT
Loading composer repositories with package information
Updating dependencies
Your requirements could not be resolved to an installable set of packages.

  Problem 1
    - Root composer.json requires root/req 1.* -> satisfiable by root/req[1.0.0].
    - root/req 1.0.0 requires dep/pkg ^1 -> found dep/pkg[1.0.0, 1.0.1, 1.0.2] but it conflicts with your temporary update constraint (dep/pkg:^2).
OUTPUT
        ];

    }

    public function testInteractiveModeThrowsIfNoPackageToUpdate(): void
    {
        $this->initTempComposer([
            'repositories' => [
                'packages' => [
                    'type' => 'package',
                    'package' => [
                        ['name' => 'root/req', 'version' => '1.0.0'],
                    ],
                ],
            ],
            'require' => [
                'root/req' => '1.*',
            ],
        ]);
        $this->createComposerLock([self::getPackage('root/req', '1.0.0')]);
        self::expectExceptionMessage('Could not find any package with new versions available');

        $appTester = $this->getApplicationTester();
        $appTester->setInputs(['']);
        $appTester->run(['command' => 'update', '--interactive' => true]);
    }

    public function testInteractiveModeThrowsIfNoPackageEntered(): void
    {
        $this->initTempComposer([
            'repositories' => [
                'packages' => [
                    'type' => 'package',
                    'package' => [
                        ['name' => 'root/req', 'version' => '1.0.0'],
                        ['name' => 'root/req', 'version' => '1.0.1'],
                    ],
                ],
            ],
            'require' => [
                'root/req' => '1.*',
            ],
        ]);
        $this->createComposerLock([self::getPackage('root/req', '1.0.0')]);
        self::expectExceptionMessage('No package named "" is installed.');

        $appTester = $this->getApplicationTester();
        $appTester->setInputs(['']);
        $appTester->run(['command' => 'update', '--interactive' => true]);
    }

    /**
     * @dataProvider provideInteractiveUpdates
     * @param array<mixed> $packageNames
     */
    public function testInteractiveTmp(array $packageNames, string $expected): void
    {
        $this->initTempComposer([
            'repositories' => [
                'packages' => [
                    'type' => 'package',
                    'package' => [
                        ['name' => 'root/req', 'version' => '1.0.0', 'require' => ['dep/pkg' => '^1']],
                        ['name' => 'dep/pkg', 'version' => '1.0.0'],
                        ['name' => 'dep/pkg', 'version' => '1.0.1'],
                        ['name' => 'dep/pkg', 'version' => '1.0.2'],
                        ['name' => 'another-dep/pkg', 'version' => '1.0.2'],
                    ],
                ],
            ],
            'require' => [
                'root/req' => '1.*',
            ],
        ]);

        $rootPackage = self::getPackage('root/req');
        $packages = [$rootPackage];

        foreach ($packageNames as $pkg => $ver) {
            $currentPkg = self::getPackage($pkg, $ver);
            array_push($packages, $currentPkg);
        }

        $rootPackage->setRequires([
            'dep/pkg' => new Link(
                'root/req',
                'dep/pkg',
                new MatchAllConstraint(),
                Link::TYPE_REQUIRE,
                '^1'
            ),
            'another-dep/pkg' => new Link(
                'root/req',
                'another-dep/pkg',
                new MatchAllConstraint(),
                Link::TYPE_REQUIRE,
                '^1'
            ),
        ]);

        $this->createComposerLock($packages);
        $this->createInstalledJson($packages);

        $appTester = $this->getApplicationTester();
        $appTester->setInputs(array_merge(array_keys($packageNames), ['', 'yes']));
        $appTester->run([
            'command' => 'update', '--interactive' => true,
            '--no-audit' => true,
            '--dry-run' => true,
        ]);

        self::assertStringEndsWith(
            trim($expected),
            trim($appTester->getDisplay(true))
        );
    }

    public function provideInteractiveUpdates(): \Generator
    {
        yield [
            ['dep/pkg' => '1.0.1'],
            <<<OUTPUT
Lock file operations: 1 install, 1 update, 0 removals
  - Locking another-dep/pkg (1.0.2)
  - Upgrading dep/pkg (1.0.1 => 1.0.2)
Installing dependencies from lock file (including require-dev)
Package operations: 1 install, 1 update, 0 removals
  - Upgrading dep/pkg (1.0.1 => 1.0.2)
  - Installing another-dep/pkg (1.0.2)
OUTPUT
        ];

        yield [
            ['dep/pkg' => '1.0.1', 'another-dep/pkg' => '1.0.2'],
            <<<OUTPUT
Lock file operations: 0 installs, 1 update, 0 removals
  - Upgrading dep/pkg (1.0.1 => 1.0.2)
Installing dependencies from lock file (including require-dev)
Package operations: 0 installs, 1 update, 0 removals
  - Upgrading dep/pkg (1.0.1 => 1.0.2)
OUTPUT
        ];
    }
}
