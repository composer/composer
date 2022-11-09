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
use LogicException;

class CheckPlatformReqsCommandTest extends TestCase
{
    /**
     * @dataProvider caseProvider
     * @param array<mixed> $composerJson
     * @param array<mixed> $command
     */
    public function testPlatformReqsAreSatisfied(
        array $composerJson,
        array $command,
        string $expected,
        bool $lock = true
    ): void {
        $this->initTempComposer($composerJson);

        $packages = [
            $this->getPackage('ext-foobar', '2.3.4'),
        ];
        $devPackages = [
            $this->getPackage('ext-barbaz', '2.3.4.5')
        ];

        $this->createInstalledJson($packages, $devPackages);

        if ($lock) {
           $this->createComposerLock($packages, $devPackages);
        }

        $appTester = $this->getApplicationTester();
        $appTester->run(array_merge(['command' => 'check-platform-reqs'], $command));

        $appTester->assertCommandIsSuccessful();
        $this->assertSame(trim($expected), trim($appTester->getDisplay(true)));
    }

    public function testExceptionThrownIfNoLockfileFound(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage("No lockfile found. Unable to read locked packages");
        $this->initTempComposer([]);
        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => 'check-platform-reqs']);
    }

    public function caseProvider(): \Generator
    {
        yield 'Disables checking of require-dev packages requirements.' => [
            [
                'require' => [
                    'ext-foobar' => '^2.0',
                ],
                'require-dev' => [
                    'ext-barbaz' => '~4.0',
                ]
            ],
            ['--no-dev' => true],
            'Checking non-dev platform requirements for packages in the vendor dir
ext-foobar 2.3.4   success'
        ];

        yield 'Checks requirements only from the lock file, not from installed packages.' => [
            [
                'require' => [
                    'ext-foobar' => '^2.3',
                ],
                'require-dev' => [
                    'ext-barbaz' => '~2.0',
                ]
            ],
            ['--lock' => true],
            'Checking platform requirements using the lock file
ext-barbaz 2.3.4.5   success 
ext-foobar 2.3.4     success'
        ];
    }
}
