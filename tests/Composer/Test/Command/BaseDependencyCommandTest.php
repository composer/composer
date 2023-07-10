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

use Symfony\Component\Console\Command\Command;
use UnexpectedValueException;
use InvalidArgumentException;
use Composer\Test\TestCase;
use RuntimeException;
use Generator;

/**
 * @covers \Composer\Command\BaseDependencyCommand
 * @covers \Composer\Command\DependsCommand
 * @covers \Composer\Command\ProhibitsCommand
 */
class BaseDependencyCommandTest extends TestCase
{
    /**
     * Test that SUT will throw an exception when there were not provided some parameters
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
     * Test that SUT will show a warning message when dependencies have not been installed yet
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
        $someRequiredDevPackage = self::getPackage('vendor2/package1');

        $this->createComposerLock([$someRequiredPackage], [$someRequiredDevPackage]);

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
}
