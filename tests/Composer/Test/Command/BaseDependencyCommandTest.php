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

use Composer\Semver\Constraint\MatchAllConstraint;
use Composer\Semver\Constraint\MultiConstraint;
use Symfony\Component\Console\Command\Command;
use UnexpectedValueException;
use InvalidArgumentException;
use Composer\Test\TestCase;
use Composer\Package\Link;
use RuntimeException;
use Generator;

class BaseDependencyCommandTest extends TestCase
{
    /**
     * Test that an exception is throw when there weren't provided some parameters
     *
     * @covers       \Composer\Command\BaseDependencyCommand
     * @covers       \Composer\Command\DependsCommand
     * @covers       \Composer\Command\ProhibitsCommand
     *
     * @dataProvider noParametersCaseProvider
     *
     * @param array<string, string> $parameters
     */
    public function testExceptionWhenNoRequiredParameters(
        string $command,
        array $parameters,
        string $expectedExceptionMessage
    ): void {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($expectedExceptionMessage);

        $appTester = $this->getApplicationTester();
        self::assertEquals(Command::FAILURE, $appTester->run(['command' => $command] + $parameters));
    }

    /**
     * @return Generator<string, array{string, array<string, string>, string}>
     */
    public static function noParametersCaseProvider(): Generator
    {
        yield '`why` command without package parameter' => [
            'why',
            [],
            'Not enough arguments (missing: "package").',
        ];

        yield '`why-not` command without package and version parameters' => [
            'why-not',
            [],
            'Not enough arguments (missing: "package, version").',
        ];

        yield '`why-not` command without package parameter' => [
            'why-not',
            ['version' => '*'],
            'Not enough arguments (missing: "package").',
        ];

        yield '`why-not` command without version parameter' => [
            'why-not',
            ['package' => 'vendor1/package1'],
            'Not enough arguments (missing: "version").',
        ];
    }

    /**
     * Test that an exception is throw when there wasn't provided the locked file alongside `--locked` parameter
     *
     * @covers       \Composer\Command\BaseDependencyCommand
     * @covers       \Composer\Command\DependsCommand
     * @covers       \Composer\Command\ProhibitsCommand
     *
     * @dataProvider caseProvider
     *
     * @param array<string, string> $parameters
     */
    public function testExceptionWhenRunningLockedWithoutLockFile(string $command, array $parameters): void
    {
        $this->initTempComposer();

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('A valid composer.lock file is required to run this command with --locked');

        $appTester = $this->getApplicationTester();
        self::assertEquals(
            Command::FAILURE,
            $appTester->run(
                ['command' => $command] + $parameters + ['--locked' => true]
            )
        );
    }

    /**
     * Test that an exception is throw when the provided package to be inspected isn't required by the project
     *
     * @covers       \Composer\Command\BaseDependencyCommand
     * @covers       \Composer\Command\DependsCommand
     * @covers       \Composer\Command\ProhibitsCommand
     *
     * @dataProvider caseProvider
     *
     * @param array<string, string> $parameters
     */
    public function testExceptionWhenItCouldNotFoundThePackage(string $command, array $parameters): void
    {
        $packageToBeInspected = $parameters['package'];

        $this->initTempComposer();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf('Could not find package "%s" in your project', $packageToBeInspected));

        $appTester = $this->getApplicationTester();
        self::assertEquals(
            Command::FAILURE,
            $appTester->run(['command' => $command] + $parameters)
        );
    }

    /**
     * Test that it shows a warning message when the package to be inspected wasn't found in the project
     *
     * @covers       \Composer\Command\BaseDependencyCommand
     * @covers       \Composer\Command\DependsCommand
     * @covers       \Composer\Command\ProhibitsCommand
     *
     * @dataProvider caseProvider
     *
     * @param array<string, string> $parameters
     */
    public function testExceptionWhenPackageWasNotFoundInProject(string $command, array $parameters): void
    {
        $packageToBeInspected = $parameters['package'];

        $this->initTempComposer([
            'require' => [
                'vendor1/package2' => '1.*',
                'vendor2/package1' => '2.*',
            ],
        ]);

        $firstRequiredPackage = self::getPackage('vendor1/package2');
        $secondRequiredPackage = self::getPackage('vendor2/package1');

        $this->createInstalledJson([$firstRequiredPackage, $secondRequiredPackage]);
        $this->createComposerLock([$firstRequiredPackage, $secondRequiredPackage]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf('Could not find package "%s" in your project', $packageToBeInspected));

        $appTester = $this->getApplicationTester();

        self::assertEquals(Command::FAILURE, $appTester->run(['command' => $command] + $parameters));
    }

    /**
     * Test that it shows a warning message when the dependencies haven't been installed yet
     *
     * @covers       \Composer\Command\BaseDependencyCommand
     * @covers       \Composer\Command\DependsCommand
     * @covers       \Composer\Command\ProhibitsCommand
     *
     * @dataProvider caseProvider
     *
     * @param array<string, string> $parameters
     */
    public function testWarningWhenDependenciesAreNotInstalled(string $command, array $parameters): void
    {
        $expectedWarningMessage = '<warning>No dependencies installed. Try running composer install or update, or use --locked.</warning>';

        $this->initTempComposer([
            'require' => [
                'vendor1/package1' => '1.*',
            ],
            'require-dev' => [
                'vendor2/package1' => '2.*',
            ],
        ]);

        $someRequiredPackage = self::getPackage('vendor1/package1');
        $someDevRequiredPackage = self::getPackage('vendor2/package1');

        $this->createComposerLock([$someRequiredPackage], [$someDevRequiredPackage]);

        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => $command] + $parameters);

        self::assertSame($expectedWarningMessage, trim($appTester->getDisplay(true)));
    }

    /**
     * @return Generator<string, array{string, array<string, string>}>
     */
    public static function caseProvider(): Generator
    {
        yield '`why` command' => [
            'why',
            ['package' => 'vendor1/package1'],
        ];

        yield '`why-not` command' => [
            'why-not',
            ['package' => 'vendor1/package1', 'version' => '1.*'],
        ];
    }

    /**
     * Test that it finishes successfully and show some expected outputs depending on different command parameters
     *
     * @covers       \Composer\Command\BaseDependencyCommand
     * @covers       \Composer\Command\DependsCommand
     *
     * @dataProvider caseWhyProvider
     *
     * @param array<string, string|bool> $parameters
     */
    public function testWhyCommandOutputs(array $parameters, string $expectedOutput, int $expectedStatusCode): void
    {
        $packageToBeInspected = $parameters['package'];
        $renderAsTree = $parameters['--tree'] ?? false;
        $renderRecursively = $parameters['--recursive'] ?? false;

        $this->initTempComposer([
            'repositories' => [
                'packages' => [
                    'type' => 'package',
                    'package' => [
                        ['name' => 'vendor1/package1', 'version' => '1.3.0', 'require' => ['vendor1/package2' => '^2']],
                        ['name' => 'vendor1/package2', 'version' => '2.3.0', 'require' => ['vendor1/package3' => '^1']],
                        ['name' => 'vendor1/package3', 'version' => '2.1.0'],
                    ],
                ],
            ],
            'require' => [
                'vendor1/package2' => '1.3.0',
                'vendor1/package3' => '2.3.0',
            ],
            'require-dev' => [
                'vendor2/package1' => '2.*',
            ],
        ]);

        $firstRequiredPackage = self::getPackage('vendor1/package1', '1.3.0');
        $firstRequiredPackage->setRequires([
            'vendor1/package2' => new Link(
                'vendor1/package1',
                'vendor1/package2',
                new MatchAllConstraint(),
                Link::TYPE_REQUIRE,
                '^2'
            ),
        ]);
        $secondRequiredPackage = self::getPackage('vendor1/package2', '2.3.0');
        $secondRequiredPackage->setRequires([
            'vendor1/package3' => new Link(
                'vendor1/package2',
                'vendor1/package3',
                new MatchAllConstraint(),
                Link::TYPE_REQUIRE,
                '^1'
            ),
        ]);
        $thirdRequiredPackage = self::getPackage('vendor1/package3', '2.1.0');
        $someDevRequiredPackage = self::getPackage('vendor2/package1');
        $this->createComposerLock(
            [$firstRequiredPackage, $secondRequiredPackage, $thirdRequiredPackage],
            [$someDevRequiredPackage]
        );
        $this->createInstalledJson(
            [$firstRequiredPackage, $secondRequiredPackage, $thirdRequiredPackage],
            [$someDevRequiredPackage]
        );

        $appTester = $this->getApplicationTester();
        $appTester->run([
            'command' => 'why',
            'package' => $packageToBeInspected,
            '--tree' => $renderAsTree,
            '--recursive' => $renderRecursively,
            '--locked' => true,
        ]);

        self::assertSame($expectedStatusCode, $appTester->getStatusCode());

        self::assertEquals(trim($expectedOutput), $this->trimLines($appTester->getDisplay(true)));
    }

    /**
     * @return Generator<string, array{array<string, string|bool>, string}>
     */
    public static function caseWhyProvider(): Generator
    {
        yield 'there is no installed package depending on the package' => [
            ['package' => 'vendor1/package1'],
            'There is no installed package depending on "vendor1/package1"',
            1,
        ];

        yield 'a nested package dependency' => [
            ['package' => 'vendor1/package3'],
            <<<OUTPUT
__root__         -     requires vendor1/package3 (2.3.0)
vendor1/package2 2.3.0 requires vendor1/package3 (^1)
OUTPUT
,
            0,
        ];

        yield 'a nested package dependency (tree mode)' => [
            ['package' => 'vendor1/package3', '--tree' => true],
            <<<OUTPUT
vendor1/package3 2.1.0
|--__root__ (requires vendor1/package3 2.3.0)
`--vendor1/package2 2.3.0 (requires vendor1/package3 ^1)
   |--__root__ (requires vendor1/package2 1.3.0)
   `--vendor1/package1 1.3.0 (requires vendor1/package2 ^2)
OUTPUT
,
            0,
        ];

        yield 'a nested package dependency (recursive mode)' => [
            ['package' => 'vendor1/package3', '--recursive' => true],
            <<<OUTPUT
__root__         -     requires vendor1/package2 (1.3.0)
vendor1/package1 1.3.0 requires vendor1/package2 (^2)
__root__         -     requires vendor1/package3 (2.3.0)
vendor1/package2 2.3.0 requires vendor1/package3 (^1)
OUTPUT
,
            0,
        ];

        yield 'a simple package dev dependency' => [
            ['package' => 'vendor2/package1'],
            '__root__ - requires (for development) vendor2/package1 (2.*)',
            0,
        ];
    }

    /**
     * Test that it finishes successfully and show some expected outputs depending on different command parameters
     *
     * @covers       \Composer\Command\BaseDependencyCommand
     * @covers       \Composer\Command\ProhibitsCommand
     *
     * @dataProvider caseWhyNotProvider
     *
     * @param array<string, string> $parameters
     */
    public function testWhyNotCommandOutputs(array $parameters, string $expectedOutput, int $expectedStatusCode): void
    {
        $packageToBeInspected = $parameters['package'];
        $packageVersionToBeInspected = $parameters['version'];

        $this->initTempComposer([
            'repositories' => [
                'packages' => [
                    'type' => 'package',
                    'package' => [
                        ['name' => 'vendor1/package1', 'version' => '1.3.0'],
                        ['name' => 'vendor2/package1', 'version' => '2.0.0'],
                        ['name' => 'vendor2/package2', 'version' => '1.0.0', 'require' => ['vendor2/package3' => '1.4.*', 'php' => '^8.2']],
                        ['name' => 'vendor2/package3', 'version' => '1.4.0'],
                        ['name' => 'vendor2/package3', 'version' => '1.5.0'],
                    ],
                ],
            ],
            'require' => [
                'vendor1/package1' => '1.*',
                'php' => '^8',
            ],
            'require-dev' => [
                'vendor2/package1' => '2.*',
                'vendor2/package2' => '^1',
            ],
            'config' => [
                'platform' => [
                    'php' => '8.3.2',
                ],
            ],
        ]);

        $someRequiredPackage = self::getPackage('vendor1/package1', '1.3.0');
        $firstDevRequiredPackage = self::getPackage('vendor2/package1', '2.0.0');
        $secondDevRequiredPackage = self::getPackage('vendor2/package2', '1.0.0');
        $secondDevRequiredPackage->setRequires([
            'vendor2/package3' => new Link(
                'vendor2/package2',
                'vendor2/package3',
                new MatchAllConstraint(),
                Link::TYPE_REQUIRE,
                '1.4.*'
            ),
            'php' => new Link(
                'vendor2/package2',
                'php',
                new MultiConstraint([self::getVersionConstraint('>=', '8.2.0.0'), self::getVersionConstraint('<', '9.0.0.0-dev')]),
                Link::TYPE_REQUIRE,
                '^8.2'
            ),
        ]);
        $secondDevNestedRequiredPackage = self::getPackage('vendor2/package3', '1.4.0');

        $this->createComposerLock(
            [$someRequiredPackage],
            [$firstDevRequiredPackage, $secondDevRequiredPackage]
        );
        $this->createInstalledJson(
            [$someRequiredPackage],
            [$firstDevRequiredPackage, $secondDevRequiredPackage, $secondDevNestedRequiredPackage]
        );

        $appTester = $this->getApplicationTester();
        $appTester->run([
            'command' => 'why-not',
            'package' => $packageToBeInspected,
            'version' => $packageVersionToBeInspected,
        ]);

        self::assertSame($expectedStatusCode, $appTester->getStatusCode());
        self::assertSame(trim($expectedOutput), $this->trimLines($appTester->getDisplay(true)));
    }

    /**
     * @return Generator<string, array{array<string, string>, string}>
     */
    public function caseWhyNotProvider(): Generator
    {
        yield 'it could not found the package with a specific version' => [
            ['package' => 'vendor1/package1', 'version' => '3.*'],
            <<<OUTPUT
Package "vendor1/package1" could not be found with constraint "3.*", results below will most likely be incomplete.
__root__ - requires vendor1/package1 (1.*)
Not finding what you were looking for? Try calling `composer require "vendor1/package1:3.*" --dry-run` to get another view on the problem.
OUTPUT
,
            1,
        ];

        yield 'it could not found the package and there is no installed package with a specific version' => [
            ['package' => 'vendor1/package1', 'version' => '^1.4'],
            <<<OUTPUT
Package "vendor1/package1" could not be found with constraint "^1.4", results below will most likely be incomplete.
There is no installed package depending on "vendor1/package1" in versions not matching ^1.4
Not finding what you were looking for? Try calling `composer require "vendor1/package1:^1.4" --dry-run` to get another view on the problem.
OUTPUT
,
            0,
        ];

        yield 'Package is already installed!' => [
            ['package' => 'vendor1/package1', 'version' => '^1.3'],
            <<<OUTPUT
Package "vendor1/package1" 1.3.0 is already installed! To find out why, run `composer why vendor1/package1`
OUTPUT
,
            0,
        ];

        yield 'an installed package requires an incompatible version of the inspected package' => [
            ['package' => 'vendor2/package3', 'version' => '1.5.0'],
            <<<OUTPUT
vendor2/package2 1.0.0 requires vendor2/package3 (1.4.*)
Not finding what you were looking for? Try calling `composer update "vendor2/package3:1.5.0" --dry-run` to get another view on the problem.
OUTPUT
,
            1,
        ];

        yield 'all compatible with the inspected platform package (range matching installed)' => [
            ['package' => 'php', 'version' => '^8'],
            <<<OUTPUT
Package "php ^8" found in version "8.3.2" (version provided by config.platform).
There is no installed package depending on "php" in versions not matching ^8
OUTPUT
,
            0,
        ];

        yield 'an installed package requires an incompatible version of the inspected platform package (fixed non-matching package)' => [
            ['package' => 'php', 'version' => '9.1.0'],
            <<<OUTPUT
__root__         -     requires php (^8)
vendor2/package2 1.0.0 requires php (^8.2)
OUTPUT
,
            1,
        ];
    }
}
