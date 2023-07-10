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
     * Test that SUT will throw an exception when there were not provided some parameters
     *
     * @covers       \Composer\Command\BaseDependencyCommand
     * @covers       \Composer\Command\DependsCommand
     * @covers       \Composer\Command\ProhibitsCommand
     *
     * @dataProvider noParametersCaseProvider
     *
     * @param string $command
     * @param array<mixed> $parameters
     * @param string $expectedExceptionMessage
     *
     */
    public function testExceptionWhenNoRequiredParameters(
        string $command,
        array $parameters,
        string $expectedExceptionMessage
    ): void {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($expectedExceptionMessage);

        $appTester = $this->getApplicationTester();
        $this->assertEquals(Command::FAILURE, $appTester->run(['command' => $command] + $parameters));
    }

    /**
     * @return Generator [$command, $parameters, $expectedExceptionMessage]
     */
    public function noParametersCaseProvider(): Generator
    {
        yield '`why` command without package parameter' => [
            'why',
            [],
            'Not enough arguments (missing: "package").'
        ];

        yield '`why-not` command without package and version parameters' => [
            'why-not',
            [],
            'Not enough arguments (missing: "package, version").'
        ];

        yield '`why-not` command without package parameter' => [
            'why-not',
            ['version' => '*'],
            'Not enough arguments (missing: "package").'
        ];

        yield '`why-not` command without version parameter' => [
            'why-not',
            ['package' => 'vendor1/package1'],
            'Not enough arguments (missing: "version").'
        ];
    }

    /**
     * Test that SUT will throw an exception when there is not a provided locked file alongside `--locked` parameter
     *
     * @covers       \Composer\Command\BaseDependencyCommand
     * @covers       \Composer\Command\DependsCommand
     * @covers       \Composer\Command\ProhibitsCommand
     *
     * @dataProvider caseProvider
     *
     * @param string $command
     * @param array<mixed> $parameters
     */
    public function testExceptionWhenRunningLockedWithoutLockFile(string $command, array $parameters): void
    {
        $this->initTempComposer();

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('A valid composer.lock file is required to run this command with --locked');

        $appTester = $this->getApplicationTester();
        $this->assertEquals(
            Command::FAILURE,
            $appTester->run(['command' => $command] + $parameters + ['--locked' => true]
            )
        );
    }

    /**
     * Test that SUT will throw an exception when the provided package to be inspected is not required by the project
     *
     * @covers       \Composer\Command\BaseDependencyCommand
     * @covers       \Composer\Command\DependsCommand
     * @covers       \Composer\Command\ProhibitsCommand
     *
     * @dataProvider caseProvider
     *
     * @param string $command
     * @param array<mixed> $parameters
     */
    public function testExceptionWhenItCouldNotFoundThePackage(string $command, array $parameters): void
    {
        $packageToBeInspected = $parameters['package'];

        $this->initTempComposer();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf('Could not find package "%s" in your project', $packageToBeInspected));

        $appTester = $this->getApplicationTester();
        $this->assertEquals(
            Command::FAILURE,
            $appTester->run(['command' => $command] + $parameters)
        );
    }

    /**
     * Test that SUT should show a warning message when the provided package was not found in the project
     *
     * @covers       \Composer\Command\BaseDependencyCommand
     * @covers       \Composer\Command\DependsCommand
     * @covers       \Composer\Command\ProhibitsCommand
     *
     * @dataProvider caseProvider
     *
     * @param string $command
     * @param array<mixed> $parameters
     */
    public function testExceptionWhenPackageWasNotFoundInProject(string $command, array $parameters): void
    {
        $packageToBeInspected = $parameters['package'];

        $this->initTempComposer([
            'require' => [
                'vendor1/package2' => '1.*',
                'vendor2/package1' => '2.*'
            ]
        ]);

        $firstRequiredPackage = self::getPackage('vendor1/package2');
        $secondRequiredPackage = self::getPackage('vendor2/package1');

        $this->createInstalledJson([$firstRequiredPackage, $secondRequiredPackage]);
        $this->createComposerLock([$firstRequiredPackage, $secondRequiredPackage]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf('Could not find package "%s" in your project', $packageToBeInspected));

        $appTester = $this->getApplicationTester();

        $this->assertEquals(Command::FAILURE, $appTester->run(['command' => $command] + $parameters));
    }

    /**
     * Test that SUT should show a warning message when dependencies have not been installed yet
     *
     * @covers       \Composer\Command\BaseDependencyCommand
     * @covers       \Composer\Command\DependsCommand
     * @covers       \Composer\Command\ProhibitsCommand
     *
     * @dataProvider caseProvider
     *
     * @param string $command
     * @param array<mixed> $parameters
     */
    public function testWarningWhenDependenciesAreNotInstalled(string $command, array $parameters): void
    {
        $expectedWarningMessage = <<<OUTPUT
<warning>No dependencies installed. Try running composer install or update, or use --locked.</warning>
OUTPUT;

        $this->initTempComposer([
            'require' => [
                'vendor1/package1' => '1.*'
            ],
            'require-dev' => [
                'vendor2/package1' => '2.*'
            ]
        ]);

        $someRequiredPackage = self::getPackage('vendor1/package1');
        $someDevRequiredPackage = self::getPackage('vendor2/package1');

        $this->createComposerLock([$someRequiredPackage], [$someDevRequiredPackage]);

        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => $command] + $parameters);

        $this->assertSame($expectedWarningMessage, trim($appTester->getDisplay(true)));
    }

    /**
     * @return Generator [$command, $parameters]
     */
    public function caseProvider(): Generator
    {
        yield '`why` command' => [
            'why',
            ['package' => 'vendor1/package1']
        ];

        yield '`why-not` command' => [
            'why-not',
            ['package' => 'vendor1/package1', 'version' => '1.*']
        ];
    }

    /**
     * Test that SUT should finish successfully and show some outputs depending different command parameters
     *
     * @covers       \Composer\Command\BaseDependencyCommand
     * @covers       \Composer\Command\DependsCommand
     *
     * @dataProvider caseWhyProvider
     *
     * @param array<mixed> $parameters
     * @param array<mixed> $expectedMessages
     */
    public function testWhyCommandOutputs(array $parameters, array $expectedMessages): void
    {
        $packageToBeInspected = $parameters['package'];

        $this->initTempComposer([
            'repositories' => [
                'packages' => [
                    'type' => 'package',
                    'package' => [
                        ['name' => 'vendor1/package1', 'version' => '1.3.0', 'require' => ['vendor1/package2' => '^2']],
                        ['name' => 'vendor1/package2', 'version' => '2.3.0']
                    ],
                ],
            ],
            'require' => [
                'vendor1/package2' => '2.0.1'
            ],
            'require-dev' => [
                'vendor2/package1' => '2.*'
            ]
        ]);

        $firstRequiredPackage = self::getPackage('vendor1/package1');
        $firstRequiredPackage->setRequires([
            'vendor1/package2' => new Link(
                'vendor1/package1',
                'vendor1/package2',
                new MatchAllConstraint(),
                Link::TYPE_REQUIRE,
                '^2'
            )
        ]);
        $secondRequiredPackage = self::getPackage('vendor1/package2', '1.1.0');
        $someDevRequiredPackage = self::getPackage('vendor2/package1');
        $this->createComposerLock([$firstRequiredPackage, $secondRequiredPackage], [$someDevRequiredPackage]);
        $this->createInstalledJson([$firstRequiredPackage, $secondRequiredPackage], [$someDevRequiredPackage]);

        $appTester = $this->getApplicationTester();
        $appTester->run([
            'command' => 'why',
            'package' => $packageToBeInspected
        ]);

        $appTester->assertCommandIsSuccessful();
        $this->assertSame(implode(PHP_EOL, $expectedMessages), trim($appTester->getDisplay(true)));
    }

    /**
     * @return Generator [$parameters, $expectedMessages]
     */
    public function caseWhyProvider(): Generator
    {
        yield 'there is no installed package depending on the package' => [
            ['package' => 'vendor1/package1'],
            [
                'There is no installed package depending on "vendor1/package1"'
            ]
        ];

        yield 'a simple package dependency' => [
            ['package' => 'vendor1/package2'],
            [
                '__root__         -     requires vendor1/package2 (2.0.1) ',
                'vendor1/package1 1.0.0 requires vendor1/package2 (^2)'
            ]
        ];

        yield 'a simple package dev dependency' => [
            ['package' => 'vendor2/package1'],
            [
                '__root__ - requires (for development) vendor2/package1 (2.*)'
            ]
        ];
    }

    /**
     * Test that SUT should finish successfully and show some outputs depending different command parameters
     *
     * @covers       \Composer\Command\BaseDependencyCommand
     * @covers       \Composer\Command\ProhibitsCommand
     *
     * @dataProvider caseWhyNotProvider
     *
     * @param array<mixed> $parameters
     * @param array<mixed> $expectedMessages
     */
    public function testWhyNotCommandOutputs(array $parameters, array $expectedMessages): void
    {
        $packageToBeInspected = $parameters['package'];
        $packageVersionToBeInspected = $parameters['version'];

        $this->initTempComposer([
            'repositories' => [
                'packages' => [
                    'type' => 'package',
                    'package' => [
                        ['name' => 'vendor1/package1', 'version' => '1.3.0'],
                        ['name' => 'vendor2/package1', 'version' => '1.0.0']
                    ],
                ],
            ],
            'require' => [
                'vendor1/package1' => '1.*'
            ],
            'require-dev' => [
                'vendor2/package1' => '2.*'
            ]
        ]);

        $someRequiredPackage = self::getPackage('vendor1/package1');
        $someDevRequiredPackage = self::getPackage('vendor2/package1');
        $this->createComposerLock([$someRequiredPackage], [$someDevRequiredPackage]);
        $this->createInstalledJson([$someRequiredPackage], [$someDevRequiredPackage]);

        $appTester = $this->getApplicationTester();
        $appTester->run([
            'command' => 'why-not',
            'package' => $packageToBeInspected,
            'version' => $packageVersionToBeInspected
        ]);

        $appTester->assertCommandIsSuccessful();
        $this->assertSame(implode(PHP_EOL, $expectedMessages), trim($appTester->getDisplay(true)));
    }

    /**
     * @return Generator [$parameters, $expectedMessages]
     */
    public function caseWhyNotProvider(): Generator
    {
        yield 'it could not found the package with a specific version' => [
            ['package' => 'vendor1/package1', 'version' => '3.*'],
            [
                <<<OUTPUT
Package "vendor1/package1" could not be found with constraint "3.*", results below will most likely be incomplete.
OUTPUT,
                '__root__ - requires vendor1/package1 (1.*) ',
                <<<OUTPUT
Not finding what you were looking for? Try calling `composer update "vendor1/package1:3.*" --dry-run` to get another view on the problem.
OUTPUT
            ]
        ];

        yield 'it could not found the package and there is no installed package with a specific version' => [
            ['package' => 'vendor1/package1', 'version' => '^1.4'],
            [
                <<<OUTPUT
Package "vendor1/package1" could not be found with constraint "^1.4", results below will most likely be incomplete.
OUTPUT,
                'There is no installed package depending on "vendor1/package1" in versions not matching ^1.4',
                <<<OUTPUT
Not finding what you were looking for? Try calling `composer update "vendor1/package1:^1.4" --dry-run` to get another view on the problem.
OUTPUT
            ]
        ];

        yield 'there is no installed package depending on the package in versions not matching a specific version' => [
            ['package' => 'vendor1/package1', 'version' => '^1.3'],
            [
                'There is no installed package depending on "vendor1/package1" in versions not matching ^1.3',
                <<<OUTPUT
Not finding what you were looking for? Try calling `composer update "vendor1/package1:^1.3" --dry-run` to get another view on the problem.
OUTPUT
            ]
        ];
    }
}
