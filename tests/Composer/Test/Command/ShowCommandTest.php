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
use Composer\Pcre\Preg;
use Composer\Pcre\Regex;
use Composer\Repository\PlatformRepository;
use Composer\Test\TestCase;
use DateTimeImmutable;
use InvalidArgumentException;

class ShowCommandTest extends TestCase
{
    /**
     * @dataProvider provideShow
     * @param array<mixed> $command
     * @param array<string, string> $requires
     */
    public function testShow(array $command, string $expected, array $requires = []): void
    {
        $this->initTempComposer([
            'name' => 'root/pkg',
            'version' => '1.2.3',
            'repositories' => [
                'packages' => [
                    'type' => 'package',
                    'package' => [
                        ['name' => 'vendor/package', 'description' => 'generic description', 'version' => '1.0.0'],

                        ['name' => 'outdated/major', 'description' => 'outdated/major v1.0.0 description', 'version' => '1.0.0'],
                        ['name' => 'outdated/major', 'description' => 'outdated/major v1.0.1 description', 'version' => '1.0.1'],
                        ['name' => 'outdated/major', 'description' => 'outdated/major v1.1.0 description', 'version' => '1.1.0'],
                        ['name' => 'outdated/major', 'description' => 'outdated/major v1.1.1 description', 'version' => '1.1.1'],
                        ['name' => 'outdated/major', 'description' => 'outdated/major v2.0.0 description', 'version' => '2.0.0'],

                        ['name' => 'outdated/minor', 'description' => 'outdated/minor v1.0.0 description', 'version' => '1.0.0'],
                        ['name' => 'outdated/minor', 'description' => 'outdated/minor v1.0.1 description', 'version' => '1.0.1'],
                        ['name' => 'outdated/minor', 'description' => 'outdated/minor v1.1.0 description', 'version' => '1.1.0'],
                        ['name' => 'outdated/minor', 'description' => 'outdated/minor v1.1.1 description', 'version' => '1.1.1'],

                        ['name' => 'outdated/patch', 'description' => 'outdated/patch v1.0.0 description', 'version' => '1.0.0'],
                        ['name' => 'outdated/patch', 'description' => 'outdated/patch v1.0.1 description', 'version' => '1.0.1'],
                    ],
                ],
            ],
            'require' => $requires === [] ? new \stdClass : $requires,
        ]);

        $pkg = self::getPackage('vendor/package', '1.0.0');
        $pkg->setDescription('description of installed package');
        $major = self::getPackage('outdated/major', '1.0.0');
        $major->setReleaseDate(new DateTimeImmutable());
        $minor = self::getPackage('outdated/minor', '1.0.0');
        $minor->setReleaseDate(new DateTimeImmutable('-2 years'));
        $patch = self::getPackage('outdated/patch', '1.0.0');
        $patch->setReleaseDate(new DateTimeImmutable('-2 weeks'));

        $this->createInstalledJson([$pkg, $major, $minor, $patch]);

        $pkg = self::getPackage('vendor/locked', '3.0.0');
        $pkg->setDescription('description of locked package');
        $this->createComposerLock([
            $pkg,
        ]);

        $appTester = $this->getApplicationTester();
        $appTester->run(array_merge(['command' => 'show'], $command));
        self::assertSame(trim($expected), trim($appTester->getDisplay(true)));
    }

    public static function provideShow(): \Generator
    {
        yield 'default shows installed with version and description' => [
            [],
'outdated/major 1.0.0
outdated/minor 1.0.0
outdated/patch 1.0.0
vendor/package 1.0.0 description of installed package',
        ];

        yield 'with -s and --installed shows list of installed + self package' => [
            ['--installed' => true, '--self' => true],
'outdated/major 1.0.0
outdated/minor 1.0.0
outdated/patch 1.0.0
root/pkg       1.2.3
vendor/package 1.0.0 description of installed package',
        ];

        yield 'with -s and --locked shows list of installed + self package' => [
            ['--locked' => true, '--self' => true],
'root/pkg      1.2.3
vendor/locked 3.0.0 description of locked package',
        ];

        yield 'with -a show available packages with description but no version' => [
            ['-a' => true],
'outdated/major outdated/major v2.0.0 description
outdated/minor outdated/minor v1.1.1 description
outdated/patch outdated/patch v1.0.1 description
vendor/package generic description',
        ];

        yield 'show with --direct shows nothing if no deps' => [
            ['--direct' => true],
            '',
        ];

        yield 'show with --direct shows only root deps' => [
            ['--direct' => true],
            'outdated/major 1.0.0',
            ['outdated/major' => '*'],
        ];

        yield 'outdated deps' => [
            ['command' => 'outdated'],
'Legend:
! patch or minor release available - update recommended
~ major release available - update possible

Direct dependencies required in composer.json:
Everything up to date

Transitive dependencies not required in composer.json:
outdated/major 1.0.0 ~ 2.0.0
outdated/minor 1.0.0 <highlight>! 1.1.1</highlight>
outdated/patch 1.0.0 <highlight>! 1.0.1</highlight>',
        ];

        yield 'outdated deps sorting by age' => [
            ['command' => 'outdated', '--sort-by-age' => true],
'Legend:
! patch or minor release available - update recommended
~ major release available - update possible

Direct dependencies required in composer.json:
Everything up to date

Transitive dependencies not required in composer.json:
outdated/minor 1.0.0 <highlight>! 1.1.1</highlight> 2 years old
outdated/patch 1.0.0 <highlight>! 1.0.1</highlight> 2 weeks old
outdated/major 1.0.0 ~ 2.0.0 from today',
        ];

        yield 'outdated deps with --direct only show direct deps with updated' => [
            ['command' => 'outdated', '--direct' => true],
'Legend:
! patch or minor release available - update recommended
~ major release available - update possible
outdated/major 1.0.0 ~ 2.0.0',
            [
                'vendor/package' => '*',
                'outdated/major' => '*',
            ],
        ];

        yield 'outdated deps with --direct show msg if all up to date' => [
            ['command' => 'outdated', '--direct' => true],
            'All your direct dependencies are up to date',
            [
                'vendor/package' => '*',
            ],
        ];

        yield 'outdated deps with --major-only only shows major updates' => [
            ['command' => 'outdated', '--major-only' => true],
'Legend:
! patch or minor release available - update recommended
~ major release available - update possible

Direct dependencies required in composer.json:
Everything up to date

Transitive dependencies not required in composer.json:
outdated/major 1.0.0 ~ 2.0.0',
        ];

        yield 'outdated deps with --minor-only only shows minor updates' => [
            ['command' => 'outdated', '--minor-only' => true],
'Legend:
! patch or minor release available - update recommended
~ major release available - update possible

Direct dependencies required in composer.json:
outdated/minor 1.0.0 <highlight>! 1.1.1</highlight>

Transitive dependencies not required in composer.json:
outdated/major 1.0.0 <highlight>! 1.1.1</highlight>
outdated/patch 1.0.0 <highlight>! 1.0.1</highlight>',
            ['outdated/minor' => '*'],
        ];

        yield 'outdated deps with --patch-only only shows patch updates' => [
            ['command' => 'outdated', '--patch-only' => true],
'Legend:
! patch or minor release available - update recommended
~ major release available - update possible

Direct dependencies required in composer.json:
Everything up to date

Transitive dependencies not required in composer.json:
outdated/major 1.0.0 <highlight>! 1.0.1</highlight>
outdated/minor 1.0.0 <highlight>! 1.0.1</highlight>
outdated/patch 1.0.0 <highlight>! 1.0.1</highlight>',
        ];
    }

    public function testOutdatedFiltersAccordingToPlatformReqsAndWarns(): void
    {
        $this->initTempComposer([
            'repositories' => [
                'packages' => [
                    'type' => 'package',
                    'package' => [
                        ['name' => 'vendor/package', 'description' => 'generic description', 'version' => '1.0.0'],
                        ['name' => 'vendor/package', 'description' => 'generic description', 'version' => '1.1.0', 'require' => ['ext-missing' => '3']],
                        ['name' => 'vendor/package', 'description' => 'generic description', 'version' => '1.2.0', 'require' => ['ext-missing' => '3']],
                        ['name' => 'vendor/package', 'description' => 'generic description', 'version' => '1.3.0', 'require' => ['ext-missing' => '3']],
                    ],
                ],
            ],
        ]);

        $this->createInstalledJson([
            self::getPackage('vendor/package', '1.1.0'),
        ]);

        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => 'outdated']);
        self::assertSame("<warning>Cannot use vendor/package 1.1.0 as it requires ext-missing 3 which is missing from your platform.
Legend:
! patch or minor release available - update recommended
~ major release available - update possible

Direct dependencies required in composer.json:
Everything up to date

Transitive dependencies not required in composer.json:
vendor/package 1.1.0 ~ 1.0.0", trim($appTester->getDisplay(true)));

        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => 'outdated', '--verbose' => true]);
        self::assertSame("<warning>Cannot use vendor/package's latest version 1.3.0 as it requires ext-missing 3 which is missing from your platform.
<warning>Cannot use vendor/package 1.2.0 as it requires ext-missing 3 which is missing from your platform.
<warning>Cannot use vendor/package 1.1.0 as it requires ext-missing 3 which is missing from your platform.
Legend:
! patch or minor release available - update recommended
~ major release available - update possible

Direct dependencies required in composer.json:
Everything up to date

Transitive dependencies not required in composer.json:
vendor/package 1.1.0 ~ 1.0.0", trim($appTester->getDisplay(true)));
    }

    public function testOutdatedFiltersAccordingToPlatformReqsWithoutWarningForHigherVersions(): void
    {
        $this->initTempComposer([
            'repositories' => [
                'packages' => [
                    'type' => 'package',
                    'package' => [
                        ['name' => 'vendor/package', 'description' => 'generic description', 'version' => '1.0.0'],
                        ['name' => 'vendor/package', 'description' => 'generic description', 'version' => '1.1.0'],
                        ['name' => 'vendor/package', 'description' => 'generic description', 'version' => '1.2.0'],
                        ['name' => 'vendor/package', 'description' => 'generic description', 'version' => '1.3.0', 'require' => ['php' => '^99']],
                    ],
                ],
            ],
        ]);

        $this->createInstalledJson([
            self::getPackage('vendor/package', '1.1.0'),
        ]);

        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => 'outdated']);
        self::assertSame("Legend:
! patch or minor release available - update recommended
~ major release available - update possible

Direct dependencies required in composer.json:
Everything up to date

Transitive dependencies not required in composer.json:
vendor/package 1.1.0 <highlight>! 1.2.0</highlight>", trim($appTester->getDisplay(true)));
    }

    public function testShowDirectWithNameDoesNotShowTransientDependencies(): void
    {
        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage('Package "vendor/package" is installed but not a direct dependent of the root package.');

        $this->initTempComposer([
            'repositories' => [],
            'require' => [
                'direct/dependent' => '*',
            ],
        ]);

        $this->createInstalledJson([
            $direct = self::getPackage('direct/dependent', '1.0.0'),
            self::getPackage('vendor/package', '1.0.0'),
        ]);

        self::configureLinks($direct, ['require' => ['vendor/package' => '*']]);

        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => 'show', '--direct' => true, 'package' => 'vendor/package']);
    }

    public function testShowDirectWithNameOnlyShowsDirectDependents(): void
    {
        $this->initTempComposer([
            'repositories' => [],
            'require' => [
                'direct/dependent' => '*',
            ],
            'require-dev' => [
                'direct/dependent2' => '*',
            ],
        ]);

        $this->createInstalledJson([
            self::getPackage('direct/dependent', '1.0.0'),
            self::getPackage('direct/dependent2', '1.0.0'),
        ]);

        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => 'show', '--direct' => true, 'package' => 'direct/dependent']);
        $appTester->assertCommandIsSuccessful();
        self::assertStringContainsString('name     : direct/dependent' . "\n", $appTester->getDisplay(true));

        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => 'show', '--direct' => true, 'package' => 'direct/dependent2']);
        $appTester->assertCommandIsSuccessful();
        self::assertStringContainsString('name     : direct/dependent2' . "\n", $appTester->getDisplay(true));
    }

    public function testShowPlatformOnlyShowsPlatformPackages(): void
    {
        $this->initTempComposer([
            'repositories' => [
                'packages' => [
                    'type' => 'package',
                    'package' => [
                        ['name' => 'vendor/package', 'description' => 'generic description', 'version' => '1.0.0'],
                    ],
                ],
            ],
        ]);

        $this->createInstalledJson([
            self::getPackage('vendor/package', '1.0.0'),
        ]);

        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => 'show', '-p' => true]);
        $output = trim($appTester->getDisplay(true));
        foreach (Regex::matchAll('{^(\w+)}m', $output)->matches as $m) {
            self::assertTrue(PlatformRepository::isPlatformPackage((string) $m[1]));
        }
    }

    public function testShowPlatformWorksWithoutComposerJson(): void
    {
        $this->initTempComposer([]);
        unlink('./composer.json');
        unlink('./auth.json');

        // listing packages
        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => 'show', '-p' => true]);
        $output = trim($appTester->getDisplay(true));
        foreach (Regex::matchAll('{^(\w+)}m', $output)->matches as $m) {
            self::assertTrue(PlatformRepository::isPlatformPackage((string) $m[1]));
        }

        // getting a single package
        $appTester->run(['command' => 'show', '-p' => true, 'package' => 'php']);
        $appTester->assertCommandIsSuccessful();
        $appTester->run(['command' => 'show', '-p' => true, '-f' => 'json', 'package' => 'php']);
        $appTester->assertCommandIsSuccessful();
    }

    public function testOutdatedWithZeroMajor(): void
    {
        $this->initTempComposer([
            'repositories' => [
                'packages' => [
                    'type' => 'package',
                    'package' => [
                        ['name' => 'zerozero/major', 'description' => 'generic description', 'version' => '0.0.1'],
                        ['name' => 'zerozero/major', 'description' => 'generic description', 'version' => '0.0.2'],
                        ['name' => 'zero/major', 'description' => 'generic description', 'version' => '0.1.0'],
                        ['name' => 'zero/major', 'description' => 'generic description', 'version' => '0.2.0'],
                        ['name' => 'zero/minor', 'description' => 'generic description', 'version' => '0.1.0'],
                        ['name' => 'zero/minor', 'description' => 'generic description', 'version' => '0.1.2'],
                        ['name' => 'zero/patch', 'description' => 'generic description', 'version' => '0.1.2'],
                        ['name' => 'zero/patch', 'description' => 'generic description', 'version' => '0.1.2.1'],
                    ],
                ],
            ],
            'require' => [
                'zerozero/major' => '^0.0.1',
                'zero/major' => '^0.1',
                'zero/minor' => '^0.1',
                'zero/patch' => '^0.1',
            ],
        ]);

        $this->createInstalledJson([
            self::getPackage('zerozero/major', '0.0.1'),
            self::getPackage('zero/major', '0.1.0'),
            self::getPackage('zero/minor', '0.1.0'),
            self::getPackage('zero/patch', '0.1.2'),
        ]);

        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => 'outdated', '--direct' => true, '--patch-only' => true]);
        self::assertSame(
'Legend:
! patch or minor release available - update recommended
~ major release available - update possible
zero/patch 0.1.2 <highlight>! 0.1.2.1</highlight>', trim($appTester->getDisplay(true)));

        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => 'outdated', '--direct' => true, '--minor-only' => true]);
        self::assertSame(
'Legend:
! patch or minor release available - update recommended
~ major release available - update possible
zero/minor 0.1.0 <highlight>! 0.1.2  </highlight>
zero/patch 0.1.2 <highlight>! 0.1.2.1</highlight>', trim($appTester->getDisplay(true)));

        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => 'outdated', '--direct' => true, '--major-only' => true]);
        self::assertSame(
'Legend:
! patch or minor release available - update recommended
~ major release available - update possible
zero/major     0.1.0 ~ 0.2.0
zerozero/major 0.0.1 ~ 0.0.2', trim($appTester->getDisplay(true)));
    }

    public function testShowAllShowsAllSections(): void
    {
        $this->initTempComposer([
            'repositories' => [
                'packages' => [
                    'type' => 'package',
                    'package' => [
                        ['name' => 'vendor/available', 'description' => 'generic description', 'version' => '1.0.0'],
                    ],
                ],
            ],
        ]);

        $pkg = self::getPackage('vendor/installed', '2.0.0');
        $pkg->setDescription('description of installed package');
        $this->createInstalledJson([
            $pkg,
        ]);

        $pkg = self::getPackage('vendor/locked', '3.0.0');
        $pkg->setDescription('description of locked package');
        $this->createComposerLock([
            $pkg,
        ]);

        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => 'show', '--all' => true]);
        $output = trim($appTester->getDisplay(true));
        $output = Preg::replace('{platform:(\n  .*)+}', 'platform: wiped', $output);

        self::assertSame('platform: wiped

locked:
  vendor/locked 3.0.0 description of locked package

available:
  vendor/available generic description

installed:
  vendor/installed 2.0.0 description of installed package',
            $output
        );
    }

    public function testLockedRequiresValidLockFile(): void
    {
        $this->initTempComposer();
        $this->expectExceptionMessage(
            "A valid composer.json and composer.lock files is required to run this command with --locked"
        );
        $this->getApplicationTester()->run(['command' => 'show', '--locked' => true]);
    }

    public function testLockedShowsAllLocked(): void
    {
        $this->initTempComposer();

        $pkg = static::getPackage('vendor/locked', '3.0.0');
        $pkg->setDescription('description of locked package');
        $this->createComposerLock([
            $pkg,
        ]);

        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => 'show', '--locked' => true]);
        $output = trim($appTester->getDisplay(true));

        self::assertSame(
            'vendor/locked 3.0.0 description of locked package',
            $output
        );

        $pkg2 = static::getPackage('vendor/locked2', '2.0.0');
        $pkg2->setDescription('description of locked2 package');
        $this->createComposerLock([
            $pkg,
            $pkg2,
        ]);

        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => 'show', '--locked' => true]);
        $output = trim($appTester->getDisplay(true));
        $shouldBe = <<<OUTPUT
vendor/locked  3.0.0 description of locked package
vendor/locked2 2.0.0 description of locked2 package
OUTPUT;

        self::assertSame(
            $shouldBe,
            $output
        );
    }

    public function testInvalidOptionCombinations(): void
    {
        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => 'show', '--direct' => true, '--all' => true]);
        self::assertSame(1, $appTester->getStatusCode());

        $appTester->run(['command' => 'show', '--direct' => true, '--available' => true]);
        self::assertSame(1, $appTester->getStatusCode());

        $appTester->run(['command' => 'show', '--direct' => true, '--platform' => true]);
        self::assertSame(1, $appTester->getStatusCode());

        $appTester->run(['command' => 'show', '--tree' => true, '--all' => true]);
        self::assertSame(1, $appTester->getStatusCode());

        $appTester->run(['command' => 'show', '--tree' => true, '--available' => true]);
        self::assertSame(1, $appTester->getStatusCode());

        $appTester->run(['command' => 'show', '--tree' => true, '--latest' => true]);
        self::assertSame(1, $appTester->getStatusCode());

        $appTester->run(['command' => 'show', '--tree' => true, '--path' => true]);
        self::assertSame(1, $appTester->getStatusCode());

        $appTester->run(['command' => 'show', '--patch-only' => true, '--minor-only' => true]);
        self::assertSame(1, $appTester->getStatusCode());

        $appTester->run(['command' => 'show', '--patch-only' => true, '--major-only' => true]);
        self::assertSame(1, $appTester->getStatusCode());

        $appTester->run(['command' => 'show', '--minor-only' => true, '--major-only' => true]);
        self::assertSame(1, $appTester->getStatusCode());

        $appTester->run(['command' => 'show', '--minor-only' => true, '--major-only' => true, '--patch-only' => true]);
        self::assertSame(1, $appTester->getStatusCode());

        $appTester->run(['command' => 'show', '--format' => 'test']);
        self::assertSame(1, $appTester->getStatusCode());
    }

    public function testIgnoredOptionCombinations(): void
    {
        $this->initTempComposer();

        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => 'show', '--installed' => true]);
        self::assertStringContainsString(
            'You are using the deprecated option "installed".',
            $appTester->getDisplay(true)
        );

        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => 'show', '--ignore' => ['vendor/package']]);
        self::assertStringContainsString('You are using the option "ignore"', $appTester->getDisplay(true));
    }

    public function testSelfAndNameOnly(): void
    {
        $this->initTempComposer(['name' => 'vendor/package', 'version' => '1.2.3']);

        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => 'show', '--self' => true, '--name-only' => true]);
        self::assertSame('vendor/package', trim($appTester->getDisplay(true)));
    }

    public function testSelfAndPackageCombination(): void
    {
        $this->initTempComposer(['name' => 'vendor/package']);

        $appTester = $this->getApplicationTester();
        $this->expectException(\InvalidArgumentException::class);
        $appTester->run(['command' => 'show', '--self' => true, 'package' => 'vendor/package']);
    }

    public function testSelf(): void
    {
        $this->initTempComposer(['name' => 'vendor/package', 'version' => '1.2.3', 'time' => date('Y-m-d')]);

        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => 'show', '--self' => true]);
        $expected = [
            'name' => 'vendor/package',
            'descrip.' => '',
            'keywords' => '',
            'versions' => '* 1.2.3',
            'released' => date('Y-m-d'). ', today',
            'type' => 'library',
            'homepage' => '',
            'source' => '[]  ',
            'dist' => '[]  ',
            'path' => '',
            'names' => 'vendor/package',
        ];
        $expectedString = implode(
            "\n",
            array_map(
                static function ($k, $v) {
                    return sprintf('%-8s : %s', $k, $v);
                },
                array_keys($expected),
                $expected
            )
        ) . "\n";

        self::assertSame($expectedString, $appTester->getDisplay(true));
    }

    public function testNotInstalledError(): void
    {
        $this->initTempComposer([
            'require' => [
                'vendor/package' => '1.0.0',
            ],
            'require-dev' => [
                'vendor/package-dev' => '1.0.0',
            ],
        ]);
        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => 'show']);
        $output = trim($appTester->getDisplay(true));
        self::assertStringContainsString(
            'No dependencies installed. Try running composer install or update.',
            $output,
            'Should show error message when no dependencies are installed'
        );
    }

    public function testNoDevOption(): void
    {
        $this->initTempComposer([
            'require' => [
                'vendor/package' => '1.0.0',
            ],
            'require-dev' => [
                'vendor/package-dev' => '1.0.0',
            ],
        ]);
        $this->createInstalledJson([
            static::getPackage('vendor/package', '1.0.0'),
            static::getPackage('vendor/package-dev', '1.0.0'),
        ]);
        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => 'show', '--no-dev' => true]);
        $output = trim($appTester->getDisplay(true));
        self::assertSame(
            'vendor/package 1.0.0',
            $output
        );
    }

    public function testPackageFilter(): void
    {
        $this->initTempComposer([
            'require' => [
                'vendor/package' => '1.0.0',
                'vendor/other-package' => '1.0.0',
                'company/package' => '1.0.0',
                'company/other-package' => '1.0.0',
            ],
        ]);
        $this->createInstalledJson([
            static::getPackage('vendor/package', '1.0.0'),
            static::getPackage('vendor/other-package', '1.0.0'),
            static::getPackage('company/package', '1.0.0'),
            static::getPackage('company/other-package', '1.0.0'),
        ]);
        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => 'show', 'package' => 'vendor/package']);
        $output = trim($appTester->getDisplay(true));
        self::assertStringContainsString('vendor/package', $output);
        self::assertStringNotContainsString('vendor/other-package', $output);
        self::assertStringNotContainsString('company/package', $output);
        self::assertStringNotContainsString('company/other-package', $output);

        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => 'show', 'package' => 'company/*', '--name-only' => true]);
        $output = trim($appTester->getDisplay(true));
        self::assertStringNotContainsString('vendor/package', $output);
        self::assertStringNotContainsString('vendor/other-package', $output);
        self::assertStringContainsString('company/package', $output);
        self::assertStringContainsString('company/other-package', $output);
    }

    /**
     * @dataProvider provideNotExistingPackage
     * @param array<string, mixed> $options
     */
    public function testNotExistingPackage(string $package, array $options, string $expected): void
    {
        $dir = $this->initTempComposer([
            'require' => [
                'vendor/package' => '1.0.0',
            ],
        ]);
        $pkg = static::getPackage('vendor/package', '1.0.0');
        $this->createInstalledJson([$pkg]);
        $this->createComposerLock([$pkg]);
        if (isset($options['--working-dir'])) {
            $options['--working-dir'] = $dir;
        }
        $this->expectExceptionMessageMatches("/^" . preg_quote($expected, '/') . "/");
        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => 'show', 'package' => $package] + $options);
    }

    public function provideNotExistingPackage(): \Generator
    {
        yield 'package with no options' => [
            'not/existing',
            [],
            'Package "not/existing" not found, try using --available (-a) to show all available packages.',
        ];
        yield 'package with --all option' => [
            'not/existing',
            ['--all' => true],
            'Package "not/existing" not found.',
        ];
        yield 'package with --locked option' => [
            'not/existing',
            ['--locked' => true],
            'Package "not/existing" not found in lock file, try using --available (-a) to show all available packages.',
        ];
        yield 'platform with --platform' => [
            'ext-nonexisting',
            ['--platform' => true],
            'Package "ext-nonexisting" not found, try using --available (-a) to show all available packages.',
        ];
        yield 'platform without --platform' => [
            'ext-nonexisting',
            [],
            'Package "ext-nonexisting" not found, try using --platform (-p) to show platform packages, try using --available (-a) to show all available packages.',
        ];
    }

    public function testNotExistingPackageWithWorkingDir(): void
    {
        $dir = $this->initTempComposer([
            'require' => [
                'vendor/package' => '1.0.0',
            ],
        ]);
        $pkg = static::getPackage('vendor/package', '1.0.0');
        $this->createInstalledJson([$pkg]);

        $this->expectExceptionMessageMatches("/^" . preg_quote("Package \"not/existing\" not found in {$dir}/composer.json, try using --available (-a) to show all available packages.", '/') . "/");
        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => 'show', 'package' => 'not/existing', '--working-dir' => $dir]);
    }

    /**
     * @dataProvider providePackageAndTree
     * @param array<string, mixed> $options
     */
    public function testSpecificPackageAndTree(callable $callable, array $options, string $expected): void
    {
        $this->initTempComposer([
            'require' => [
                'vendor/package' => '1.0.0',
            ],
        ]);

        $this->createInstalledJson($callable());

        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => 'show', 'package' => 'vendor/package', '--tree' => true] + $options);
        self::assertSame($expected, trim($appTester->getDisplay(true)));
    }

    public function providePackageAndTree(): \Generator
    {
        yield 'just package' => [
            function () {
                $pgk = static::getPackage('vendor/package', '1.0.0');

                return [$pgk];
            },
            [],
            'vendor/package 1.0.0',
        ];
        yield 'package with one package requirement' => [
            function () {
                $pgk = static::getPackage('vendor/package', '1.0.0');
                $pgk->setRequires(['vendor/required-package' => new Link(
                    'vendor/package',
                    'vendor/required-package',
                    static::getVersionConstraint('=', '1.0.0'),
                    Link::TYPE_REQUIRE,
                    '1.0.0'
                )]);

                return [$pgk];
            },
            [],
            'vendor/package 1.0.0
`--vendor/required-package 1.0.0',
        ];
        yield 'package with platform requirement' => [
            function () {
                $pgk = static::getPackage('vendor/package', '1.0.0');
                $pgk->setRequires(['php' => new Link(
                    'vendor/package',
                    'php',
                    static::getVersionConstraint('=', '8.2.0'),
                    Link::TYPE_REQUIRE,
                    '8.2.0'
                )]);

                return [$pgk];
            },
            [],
            'vendor/package 1.0.0
`--php 8.2.0',
        ];
        yield 'package with json format' => [
            function () {
                $pgk = static::getPackage('vendor/package', '1.0.0');

                return [$pgk];
            },
            ['--format' => 'json'],
            '{
    "installed": [
        {
            "name": "vendor/package",
            "version": "1.0.0",
            "description": null
        }
    ]
}',
        ];
    }

    public function testNameOnlyPrintsNoTrailingWhitespace(): void
    {
        $this->initTempComposer([
            'repositories' => [
                'packages' => [
                    'type' => 'package',
                    'package' => [
                        // CAUTION: package names matter - output is sorted, and we want shorter before longer ones
                        ['name' => 'vendor/apackage', 'description' => 'generic description', 'version' => '1.0.0'],
                        ['name' => 'vendor/apackage', 'description' => 'generic description', 'version' => '1.1.0'],
                        ['name' => 'vendor/longpackagename', 'description' => 'generic description', 'version' => '1.0.0'],
                        ['name' => 'vendor/longpackagename', 'description' => 'generic description', 'version' => '1.1.0'],
                        ['name' => 'vendor/somepackage', 'description' => 'generic description', 'version' => '1.0.0'],
                    ],
                ],
            ],
        ]);

        $this->createInstalledJson([
            self::getPackage('vendor/apackage', '1.0.0'),
            self::getPackage('vendor/longpackagename', '1.0.0'),
            self::getPackage('vendor/somepackage', '1.0.0'),
        ]);

        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => 'show', '-N' => true]);
        self::assertSame(
'vendor/apackage
vendor/longpackagename
vendor/somepackage', trim($appTester->getDisplay(true))); // trim() is fine here, but see CAUTION above

        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => 'show', '--outdated' => true, '-N' => true]);
        self::assertSame(
'Legend:
! patch or minor release available - update recommended
~ major release available - update possible
vendor/apackage
vendor/longpackagename', trim($appTester->getDisplay(true))); // trim() is fine here, but see CAUTION above
    }
}
