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

use Composer\Package\CompletePackage;
use Composer\Package\Link;
use Composer\Test\TestCase;
use Symfony\Component\Console\Command\Command;

class SuggestsCommandTest extends TestCase
{
    public function testInstalledPackagesWithNoSuggestions(): void
    {
        $this->initTempComposer([
            'repositories' => [
                'packages' => [
                    'type' => 'package',
                    'package' => [
                        ['name' => 'vendor1/package1', 'version' => '1.0.0'],
                        ['name' => 'vendor2/package2', 'version' => '1.0.0'],
                    ],
                ],
            ],
            'require' => [
                'vendor1/package1' => '1.*',
                'vendor2/package2' => '1.*',
            ],
        ]);

        $packages = [
            self::getPackage('vendor1/package1'),
            self::getPackage('vendor2/package2'),
        ];

        $this->createInstalledJson($packages);
        $this->createComposerLock($packages);

        $appTester = $this->getApplicationTester();
        self::assertEquals(Command::SUCCESS, $appTester->run(['command' => 'suggest']));
        self::assertEmpty($appTester->getDisplay(true));
    }

    /**
     * @dataProvider provideSuggest
     * @param array<string, bool|string|array<int, string>> $command
     */
    public function testSuggest(bool $hasLockFile, array $command, string $expected): void
    {
        $this->initTempComposer([
            'repositories' => [
                'packages' => [
                    'type' => 'package',
                    'package' => [
                        ['name' => 'vendor1/package1', 'version' => '1.0.0', 'suggests' => ['vendor3/suggested' => 'helpful for vendor1/package1'], 'require' => ['vendor6/package6' => '^1.0'], 'require-dev' => ['vendor3/suggested' => '^1.0', 'vendor4/dev-suggested' => '^1.0']],
                        ['name' => 'vendor2/package2', 'version' => '1.0.0', 'suggests' => ['vendor4/dev-suggested' => 'helpful for vendor2/package2'], 'require' => ['vendor5/dev-package' => '^1.0']],
                        ['name' => 'vendor5/dev-package', 'version' => '1.0.0', 'suggests' => ['vendor8/dev-transitive' => 'helpful for vendor5/dev-package'], 'require-dev' => ['vendor8/dev-transitive' => '^1.0']],
                        ['name' => 'vendor6/package6', 'version' => '1.0.0', 'suggests' => ['vendor7/transitive' => 'helpful for vendor6/package6']],
                    ],
                ],
            ],
            'require' => ['vendor1/package1' => '^1'],
            'require-dev' => ['vendor2/package2' => '^1'],
        ]);

        $packages = [
            self::getPackageWithSuggestAndRequires(
                'vendor1/package1',
                '1.0.0',
                [
                    'vendor3/suggested' => 'helpful for vendor1/package1',
                ],
                [
                    'vendor6/package6' => new Link('vendor1/package1', 'vendor6/package6', self::getVersionConstraint('>=', '1.0'), Link::TYPE_REQUIRE, '^1.0'),
                ],
                [
                    'vendor4/dev-suggested' => new Link('vendor1/package1', 'vendor4/dev-suggested', self::getVersionConstraint('>=', '1.0'), Link::TYPE_DEV_REQUIRE, '^1.0'),
                    'vendor3/suggested' => new Link('vendor1/package1', 'vendor3/suggested', self::getVersionConstraint('>=', '1.0'), Link::TYPE_DEV_REQUIRE, '^1.0'),
                ]
            ),
            self::getPackageWithSuggestAndRequires(
                'vendor6/package6',
                '1.0.0',
                [
                    'vendor7/transitive' => 'helpful for vendor6/package6',
                ]
            ),
        ];
        $devPackages = [
            self::getPackageWithSuggestAndRequires(
                'vendor2/package2',
                '1.0.0',
                [
                    'vendor4/dev-suggested' => 'helpful for vendor2/package2',
                ],
                [
                    'vendor5/dev-package' => new Link('vendor2/package2', 'vendor5/dev-package', self::getVersionConstraint('>=', '1.0'), Link::TYPE_REQUIRE, '^1.0'),
                ]
            ),
            self::getPackageWithSuggestAndRequires(
                'vendor5/dev-package',
                '1.0.0',
                [
                    'vendor8/dev-transitive' => 'helpful for vendor5/dev-package',
                ],
                [],
                [
                    'vendor8/dev-transitive' => new Link('vendor5/dev-package', 'vendor8/dev-transitive', self::getVersionConstraint('>=', '1.0'), Link::TYPE_DEV_REQUIRE, '^1.0'),
                ]
            ),
        ];

        $this->createInstalledJson($packages, $devPackages);
        if ($hasLockFile) {
            $this->createComposerLock($packages, $devPackages);
        }

        $appTester = $this->getApplicationTester();
        self::assertEquals(Command::SUCCESS, $appTester->run(array_merge(['command' => 'suggest'], $command)));
        self::assertSame(trim($expected), trim($appTester->getDisplay(true)));
    }

    public static function provideSuggest(): \Generator
    {
        yield 'with lockfile, show suggested' => [
            true,
            [],
            'vendor1/package1 suggests:
 - vendor3/suggested: helpful for vendor1/package1

vendor2/package2 suggests:
 - vendor4/dev-suggested: helpful for vendor2/package2

2 additional suggestions by transitive dependencies can be shown with --all',
        ];

        yield 'without lockfile, show suggested' => [
            false,
            [],
            'vendor1/package1 suggests:
 - vendor3/suggested: helpful for vendor1/package1

vendor2/package2 suggests:
 - vendor4/dev-suggested: helpful for vendor2/package2

2 additional suggestions by transitive dependencies can be shown with --all',
        ];

        yield 'with lockfile, show suggested (excluding dev)' => [
            true,
            ['--no-dev' => true],
            'vendor1/package1 suggests:
 - vendor3/suggested: helpful for vendor1/package1

1 additional suggestions by transitive dependencies can be shown with --all',
        ];

        yield 'without lockfile, show suggested (excluding dev)' => [
            false,
            ['--no-dev' => true],
            'vendor1/package1 suggests:
 - vendor3/suggested: helpful for vendor1/package1

vendor2/package2 suggests:
 - vendor4/dev-suggested: helpful for vendor2/package2

2 additional suggestions by transitive dependencies can be shown with --all',
        ];

        yield 'with lockfile, show all suggested' => [
            true,
            ['--all' => true],
            'vendor1/package1 suggests:
 - vendor3/suggested: helpful for vendor1/package1

vendor2/package2 suggests:
 - vendor4/dev-suggested: helpful for vendor2/package2

vendor5/dev-package suggests:
 - vendor8/dev-transitive: helpful for vendor5/dev-package

vendor6/package6 suggests:
 - vendor7/transitive: helpful for vendor6/package6',
        ];

        yield 'without lockfile, show all suggested' => [
            false,
            ['--all' => true],
            'vendor1/package1 suggests:
 - vendor3/suggested: helpful for vendor1/package1

vendor2/package2 suggests:
 - vendor4/dev-suggested: helpful for vendor2/package2

vendor5/dev-package suggests:
 - vendor8/dev-transitive: helpful for vendor5/dev-package

vendor6/package6 suggests:
 - vendor7/transitive: helpful for vendor6/package6',
        ];

        yield 'with lockfile, show all suggested (excluding dev)' => [
            true,
            ['--all' => true, '--no-dev' => true],
            'vendor1/package1 suggests:
 - vendor3/suggested: helpful for vendor1/package1

vendor6/package6 suggests:
 - vendor7/transitive: helpful for vendor6/package6',
        ];

        yield 'without lockfile, show all suggested (excluding dev)' => [
            false,
            ['--all' => true, '--no-dev' => true],
            'vendor1/package1 suggests:
 - vendor3/suggested: helpful for vendor1/package1

vendor2/package2 suggests:
 - vendor4/dev-suggested: helpful for vendor2/package2

vendor5/dev-package suggests:
 - vendor8/dev-transitive: helpful for vendor5/dev-package

vendor6/package6 suggests:
 - vendor7/transitive: helpful for vendor6/package6',
        ];

        yield 'with lockfile, show suggested grouped by package' => [
            true,
            ['--by-package' => true],
            'vendor1/package1 suggests:
 - vendor3/suggested: helpful for vendor1/package1

vendor2/package2 suggests:
 - vendor4/dev-suggested: helpful for vendor2/package2

2 additional suggestions by transitive dependencies can be shown with --all',
        ];

        yield 'without lockfile, show suggested grouped by package' => [
            false,
            ['--by-package' => true],
            'vendor1/package1 suggests:
 - vendor3/suggested: helpful for vendor1/package1

vendor2/package2 suggests:
 - vendor4/dev-suggested: helpful for vendor2/package2

2 additional suggestions by transitive dependencies can be shown with --all',
        ];

        yield 'with lockfile, show suggested grouped by package (excluding dev)' => [
            true,
            ['--by-package' => true, '--no-dev' => true],
            'vendor1/package1 suggests:
 - vendor3/suggested: helpful for vendor1/package1

1 additional suggestions by transitive dependencies can be shown with --all',
        ];

        yield 'without lockfile, show suggested grouped by package (excluding dev)' => [
            false,
            ['--by-package' => true, '--no-dev' => true],
            'vendor1/package1 suggests:
 - vendor3/suggested: helpful for vendor1/package1

vendor2/package2 suggests:
 - vendor4/dev-suggested: helpful for vendor2/package2

2 additional suggestions by transitive dependencies can be shown with --all',
        ];

        yield 'with lockfile, show suggested grouped by suggestion' => [
            true,
            ['--by-suggestion' => true],
            'vendor3/suggested is suggested by:
 - vendor1/package1: helpful for vendor1/package1

vendor4/dev-suggested is suggested by:
 - vendor2/package2: helpful for vendor2/package2

2 additional suggestions by transitive dependencies can be shown with --all',
        ];

        yield 'without lockfile, show suggested grouped by suggestion' => [
            false,
            ['--by-suggestion' => true],
            'vendor3/suggested is suggested by:
 - vendor1/package1: helpful for vendor1/package1

vendor4/dev-suggested is suggested by:
 - vendor2/package2: helpful for vendor2/package2

2 additional suggestions by transitive dependencies can be shown with --all',
        ];

        yield 'with lockfile, show suggested grouped by suggestion (excluding dev)' => [
            true,
            ['--by-suggestion' => true, '--no-dev' => true],
            'vendor3/suggested is suggested by:
 - vendor1/package1: helpful for vendor1/package1

1 additional suggestions by transitive dependencies can be shown with --all',
        ];

        yield 'without lockfile, show suggested grouped by suggestion (excluding dev)' => [
            false,
            ['--by-suggestion' => true, '--no-dev' => true],
            'vendor3/suggested is suggested by:
 - vendor1/package1: helpful for vendor1/package1

vendor4/dev-suggested is suggested by:
 - vendor2/package2: helpful for vendor2/package2

2 additional suggestions by transitive dependencies can be shown with --all',
        ];

        yield 'with lockfile, show suggested grouped by package and suggestion' => [
            true,
            ['--by-package' => true, '--by-suggestion' => true],
            'vendor1/package1 suggests:
 - vendor3/suggested: helpful for vendor1/package1

vendor2/package2 suggests:
 - vendor4/dev-suggested: helpful for vendor2/package2

------------------------------------------------------------------------------
vendor3/suggested is suggested by:
 - vendor1/package1: helpful for vendor1/package1

vendor4/dev-suggested is suggested by:
 - vendor2/package2: helpful for vendor2/package2

2 additional suggestions by transitive dependencies can be shown with --all',
        ];

        yield 'without lockfile, show suggested grouped by package and suggestion' => [
            false,
            ['--by-package' => true, '--by-suggestion' => true],
            'vendor1/package1 suggests:
 - vendor3/suggested: helpful for vendor1/package1

vendor2/package2 suggests:
 - vendor4/dev-suggested: helpful for vendor2/package2

------------------------------------------------------------------------------
vendor3/suggested is suggested by:
 - vendor1/package1: helpful for vendor1/package1

vendor4/dev-suggested is suggested by:
 - vendor2/package2: helpful for vendor2/package2

2 additional suggestions by transitive dependencies can be shown with --all',
        ];

        yield 'with lockfile, show suggested grouped by package and suggestion (excluding dev)' => [
            true,
            ['--by-package' => true, '--by-suggestion' => true, '--no-dev' => true],
            'vendor1/package1 suggests:
 - vendor3/suggested: helpful for vendor1/package1

------------------------------------------------------------------------------
vendor3/suggested is suggested by:
 - vendor1/package1: helpful for vendor1/package1

1 additional suggestions by transitive dependencies can be shown with --all',
        ];

        yield 'without lockfile, show suggested grouped by package and suggestion (excluding dev)' => [
            false,
            ['--by-package' => true, '--by-suggestion' => true, '--no-dev' => true],
            'vendor1/package1 suggests:
 - vendor3/suggested: helpful for vendor1/package1

vendor2/package2 suggests:
 - vendor4/dev-suggested: helpful for vendor2/package2

------------------------------------------------------------------------------
vendor3/suggested is suggested by:
 - vendor1/package1: helpful for vendor1/package1

vendor4/dev-suggested is suggested by:
 - vendor2/package2: helpful for vendor2/package2

2 additional suggestions by transitive dependencies can be shown with --all',
        ];

        yield 'with lockfile, show suggested for package' => [
            true,
            ['packages' => ['vendor2/package2']],
            'vendor2/package2 suggests:
 - vendor4/dev-suggested: helpful for vendor2/package2',
        ];

        yield 'without lockfile, show suggested for package' => [
            false,
            ['packages' => ['vendor2/package2']],
            'vendor2/package2 suggests:
 - vendor4/dev-suggested: helpful for vendor2/package2',
        ];

        yield 'with lockfile, list suggested' => [
            true,
            ['--list' => true],
            'vendor3/suggested
vendor4/dev-suggested',
        ];

        yield 'without lockfile, list suggested' => [
            false,
            ['--list' => true],
            'vendor3/suggested
vendor4/dev-suggested',
        ];

        yield 'with lockfile, list suggested with no transitive or no-dev dependencies' => [
            true,
            ['--list' => true, '--no-dev' => true],
            'vendor3/suggested',
        ];

        yield 'without lockfile, list suggested with no transitive or no-dev dependencies' => [
            false,
            ['--list' => true, '--no-dev' => true],
            'vendor3/suggested
vendor4/dev-suggested',
        ];

        yield 'with lockfile, list suggested with all dependencies including transitive and dev dependencies' => [
            true,
            ['--list' => true, '--all' => true],
            'vendor3/suggested
vendor4/dev-suggested
vendor7/transitive
vendor8/dev-transitive',
        ];

        yield 'without lockfile, list suggested with all dependencies including transitive and dev dependencies' => [
            false,
            ['--list' => true, '--all' => true],
            'vendor3/suggested
vendor4/dev-suggested
vendor7/transitive
vendor8/dev-transitive',
        ];

        yield 'with lockfile, list all suggested (excluding dev)' => [
            true,
            ['--list' => true, '--all' => true, '--no-dev' => true],
            'vendor3/suggested
vendor7/transitive',
        ];

        yield 'without lockfile, list all suggested (excluding dev)' => [
            false,
            ['--list' => true, '--all' => true, '--no-dev' => true],
            'vendor3/suggested
vendor4/dev-suggested
vendor7/transitive
vendor8/dev-transitive',
        ];
    }

    /**
     * @param array<string, string> $suggests
     * @param array<string, Link> $requires
     * @param array<string, Link> $requireDevs
     */
    private function getPackageWithSuggestAndRequires(string $name = 'dummy/pkg', string $version = '1.0.0', array $suggests = [], array $requires = [], array $requireDevs = []): CompletePackage
    {
        $normVersion = self::getVersionParser()->normalize($version);

        $pkg = new CompletePackage($name, $normVersion, $version);
        $pkg->setSuggests($suggests);
        $pkg->setRequires($requires);
        $pkg->setDevRequires($requireDevs);

        return $pkg;
    }
}
