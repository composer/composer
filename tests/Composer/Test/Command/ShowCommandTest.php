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

use Composer\Pcre\Preg;
use Composer\Pcre\Regex;
use Composer\Repository\PlatformRepository;
use Composer\Test\TestCase;

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

        $this->createInstalledJson([
            $pkg,
            self::getPackage('outdated/major', '1.0.0'),
            self::getPackage('outdated/minor', '1.0.0'),
            self::getPackage('outdated/patch', '1.0.0'),
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

        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => 'show', '-p' => true]);
        $output = trim($appTester->getDisplay(true));
        foreach (Regex::matchAll('{^(\w+)}m', $output)->matches as $m) {
            self::assertTrue(PlatformRepository::isPlatformPackage((string) $m[1]));
        }
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
  vendor/installed 2.0.0 description of installed package', $output);
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
