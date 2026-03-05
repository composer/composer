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
                        ['name' => 'dep/pkg', 'version' => '1.0.0', 'replace' => ['replaced/pkg' => '1.0.0']],
                        ['name' => 'dep/pkg', 'version' => '1.0.1', 'replace' => ['replaced/pkg' => '1.0.1']],
                        ['name' => 'dep/pkg', 'version' => '1.0.2', 'replace' => ['replaced/pkg' => '1.0.2']],
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
<warning>Alternatively you can use --bump-after-update=dev to only bump dependencies within "require-dev".</warning>
No requirements to update in ./composer.json.
OUTPUT
            , true,
        ];

        yield 'update & bump with lock' => [
            $rootDepAndTransitiveDep,
            ['--bump-after-update' => true, '--lock' => true],
            <<<OUTPUT
Loading composer repositories with package information
Updating dependencies
Nothing to modify in lock file
Installing dependencies from lock file (including require-dev)
Nothing to install, update or remove
OUTPUT
            , true,
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
            , true,
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

        yield 'update with replaced name filter fails to resolve' => [
            $rootDepAndTransitiveDep,
            ['--with' => ['replaced/pkg:^2']],
            <<<OUTPUT
Loading composer repositories with package information
Updating dependencies
Your requirements could not be resolved to an installable set of packages.

  Problem 1
    - Root composer.json requires root/req 1.* -> satisfiable by root/req[1.0.0].
    - root/req 1.0.0 requires dep/pkg ^1 -> found dep/pkg[1.0.0, 1.0.1, 1.0.2] but it conflicts with your temporary update constraint (replaced/pkg:^2).
OUTPUT
        ];
    }

    public function testUpdateWithPatchOnly(): void
    {
        $this->initTempComposer([
            'repositories' => [
                'packages' => [
                    'type' => 'package',
                    'package' => [
                        ['name' => 'root/req', 'version' => '1.0.0'],
                        ['name' => 'root/req', 'version' => '1.0.1'],
                        ['name' => 'root/req', 'version' => '1.1.0'],
                        ['name' => 'root/req2', 'version' => '1.0.0'],
                        ['name' => 'root/req2', 'version' => '1.0.1'],
                        ['name' => 'root/req2', 'version' => '1.1.0'],
                        ['name' => 'root/req3', 'version' => '1.0.0'],
                        ['name' => 'root/req3', 'version' => '1.0.1'],
                        ['name' => 'root/req3', 'version' => '1.1.0'],
                    ],
                ],
            ],
            'require' => [
                'root/req' => '1.*',
                'root/req2' => '1.*',
                'root/req3' => '1.*',
            ],
        ]);

        $package = self::getPackage('root/req', '1.0.0');
        $package2 = self::getPackage('root/req2', '1.0.0');
        $package3 = self::getPackage('root/req3', '1.0.0');
        $this->createComposerLock([$package, $package2, $package3]);

        $appTester = $this->getApplicationTester();
        // root/req fails because of incompatible --with requirement
        $appTester->run(array_merge(['command' => 'update', '--dry-run' => true, '--no-audit' => true, '--no-install' => true, '--patch-only' => true, '--with' => ['root/req:^1.1']]));

        $expected = <<<OUTPUT
Loading composer repositories with package information
Updating dependencies
Your requirements could not be resolved to an installable set of packages.

  Problem 1
    - Root composer.json requires root/req 1.*, found root/req[1.0.0, 1.0.1, 1.1.0] but it conflicts with your temporary update constraint (root/req:[[>= 1.1.0.0-dev < 2.0.0.0-dev] [>= 1.0.0.0-dev < 1.1.0.0-dev]]).
OUTPUT;

        self::assertStringMatchesFormat(trim($expected), trim($appTester->getDisplay(true)));

        $appTester = $this->getApplicationTester();
        // root/req upgrades to 1.0.1 as that is compatible with the --with requirement now
        // root/req2 upgrades to 1.0.1 only due to --patch-only
        // root/req3 does not update as it is not in the allowlist
        $appTester->run(array_merge(['command' => 'update', '--dry-run' => true, '--no-audit' => true, '--no-install' => true, '--patch-only' => true, '--with' => ['root/req:^1.0.1'], 'packages' => ['root/req', 'root/req2']]));

        $expected = <<<OUTPUT
Loading composer repositories with package information
Updating dependencies
Lock file operations: 0 installs, 2 updates, 0 removals
  - Upgrading root/req (1.0.0 => 1.0.1)
  - Upgrading root/req2 (1.0.0 => 1.0.1)
OUTPUT;

        self::assertStringMatchesFormat(trim($expected), trim($appTester->getDisplay(true)));
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

    public function testNoSecurityBlockingAllowsInsecurePackages(): void
    {
        $this->initTempComposer([
            'repositories' => [
                'packages' => [
                    'type' => 'package',
                    'package' => [
                        ['name' => 'vulnerable/pkg', 'version' => '1.0.0'],
                        ['name' => 'vulnerable/pkg', 'version' => '1.1.0'],
                    ],
                    'security-advisories' => [
                        'vulnerable/pkg' => [
                            [
                                'advisoryId' => 'PKSA-test-001',
                                'packageName' => 'vulnerable/pkg',
                                'remoteId' => 'CVE-2024-1234',
                                'title' => 'Test Security Vulnerability',
                                'link' => 'https://example.com/advisory',
                                'cve' => 'CVE-2024-1234',
                                'affectedVersions' => '>=1.1.0,<2.0.0',
                                'source' => 'test',
                                'reportedAt' => '2024-01-01 00:00:00',
                                'composerRepository' => 'Package Repository',
                                'severity' => 'high',
                                'sources' => [
                                    [
                                        'name' => 'test',
                                        'remoteId' => 'CVE-2024-1234',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'require' => [
                'vulnerable/pkg' => '^1.0',
            ],
        ]);

        // Test 1: Without --no-security-blocking, the vulnerable version 1.1.0 should be filtered out
        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => 'update', '--dry-run' => true, '--no-audit' => true, '--no-install' => true]);

        $display = $appTester->getDisplay(true);
        // Should lock the secure version 1.0.0, not the vulnerable 1.1.0
        self::assertStringContainsString('Locking vulnerable/pkg (1.0.0)', $display);
        self::assertStringNotContainsString('Locking vulnerable/pkg (1.1.0)', $display);

        // Test 2: With --no-security-blocking, the vulnerable version 1.1.0 should be allowed
        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => 'update', '--dry-run' => true, '--no-audit' => true, '--no-install' => true, '--no-security-blocking' => true]);

        $display = $appTester->getDisplay(true);
        // Should lock the latest version 1.1.0 even though it's vulnerable
        self::assertStringContainsString('Locking vulnerable/pkg (1.1.0)', $display);
        self::assertStringNotContainsString('Locking vulnerable/pkg (1.0.0)', $display);
    }

    public function testBumpAfterUpdateWithoutLockfile(): void
    {
        $this->initTempComposer([
            'repositories' => [
                'packages' => [
                    'type' => 'package',
                    'package' => [
                        ['name' => 'root/a', 'version' => '1.0.0'],
                        ['name' => 'root/a', 'version' => '1.1.0'],
                    ],
                ],
            ],
            'require-dev' => [
                'root/a' => '^1.0.0',
            ],
            'config' => [
                'lock' => false
            ]
        ]);

        $appTester = $this->getApplicationTester();
        $appTester->run(array_merge(['command' => 'update', '--dry-run' => true,  '--no-audit' => true, '--bump-after-update' => 'dev']));

        $expected = <<<OUTPUT
Loading composer repositories with package information
Updating dependencies
Package operations: 1 install, 0 updates, 0 removals
  - Installing root/a (1.1.0)
Bumping dependencies
./composer.json would be updated with:
 - require-dev.root/a: ^1.1.0
OUTPUT;

        self::assertStringMatchesFormat(trim($expected), trim($appTester->getDisplay(true)));
    }

    public function testUpdateWithTemporaryConstraintUsingWildcard(): void
    {
        $this->initTempComposer([
            'repositories' => [
                'packages' => [
                    'type' => 'package',
                    'package' => [
                        ['name' => 'root/a', 'version' => '1.0.0'],
                        ['name' => 'root/a', 'version' => '2.0.0'],
                        ['name' => 'root/ab', 'version' => '1.0.0'],
                        ['name' => 'root/ab', 'version' => '2.0.0'],
                        ['name' => 'root/abc', 'version' => '1.0.0'],
                        ['name' => 'root/abc', 'version' => '2.0.0'],
                    ],
                ],
            ],
            'require' => [
                'root/a' => '^1 || ^2',
                'root/ab' => '^1 || ^2',
                'root/abc' => '^1 || ^2',
            ],
        ]);

        $package = self::getPackage('root/a', '2.0.0');
        $package2 = self::getPackage('root/ab', '2.0.0');
        $package3 = self::getPackage('root/abc', '2.0.0');
        $this->createComposerLock([$package, $package2, $package3]);

        $appTester = $this->getApplicationTester();
        $appTester->run(array_merge(['command' => 'update', '--dry-run' => true, '--no-audit' => true, '--no-install' => true, '--with' => ['root/*:^1']]));

        $expected = <<<OUTPUT
Loading composer repositories with package information
Updating dependencies
Lock file operations: 0 installs, 3 updates, 0 removals
  - Downgrading root/a (2.0.0 => 1.0.0)
  - Downgrading root/ab (2.0.0 => 1.0.0)
  - Downgrading root/abc (2.0.0 => 1.0.0)
OUTPUT;

        self::assertStringMatchesFormat(trim($expected), trim($appTester->getDisplay(true)));

        $appTester = $this->getApplicationTester();
        $appTester->run(array_merge(['command' => 'update', '--dry-run' => true, '--no-audit' => true, '--no-install' => true, '--with' => ['root/ab*:^1']]));

        $expected = <<<OUTPUT
Loading composer repositories with package information
Updating dependencies
Lock file operations: 0 installs, 2 updates, 0 removals
  - Downgrading root/ab (2.0.0 => 1.0.0)
  - Downgrading root/abc (2.0.0 => 1.0.0)
OUTPUT;

        self::assertStringMatchesFormat(trim($expected), trim($appTester->getDisplay(true)));
    }
}
