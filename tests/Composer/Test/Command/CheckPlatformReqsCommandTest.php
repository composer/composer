<?php

namespace Composer\Test\Command;

use Composer\Test\TestCase;
use Generator;
use LogicException;

class CheckPlatformReqsCommandTest extends TestCase
{
    /**
     * @dataProvider provideCheckPlatformReqs
     * @param array<array<string>> $composerJson
     * @param array<boolean> $command
     * @param boolean $lock
     * @param string $output
     * @return void
     */
    public function testCheckPlatformReqs(array $composerJson, array $command, bool $lock= false, string $output = ""): void
    {
        $this->initTempComposer($composerJson);

        $packages = [
            $this->getPackage('first/pkg', '2.3'),
            $this->getPackage('second/pkg', '3.3.4'),
        ];

        $devPackages = [
            $this->getPackage('dev/pkg', '2.3.4.5'),
        ];


        $this->createInstalledJson($packages, $devPackages);
        if($lock)
        {
            $this->createComposerLock($packages, $devPackages);
        }

        $appTester = $this->getApplicationTester();
        $this->assertSame(0,  $appTester->run(array_merge(['command' => 'check-platform-reqs'], $command)));
        $this->assertSame(trim($output), trim($appTester->getDisplay(true)));
    }

    public function provideCheckPlatformReqs(): Generator
    {
        yield 'by default with vendor folder' => [
            'composerJson' => [
                'require' => [
                    'first/pkg' => '^2.0',
                    'second/pkg' => '3.*',
                ],
                'require-dev' => [
                    'dev/pkg' => '~2.0',
                ],
            ],
            'command' => [],
            'lock' => false,
            'output' => "Checking platform requirements for packages in the vendor dir",
        ];

        yield '--lock' => [
            'composerJson' => [
                'require' => [
                    'first/pkg' => '^2.0',
                    'second/pkg' => '3.*',
                ],
                'require-dev' => [
                    'dev/pkg' => '~2.0',
                ],
            ],
            'command' => ['--lock' => true],
            'lock' => true,
            'output' => "Checking platform requirements using the lock file",
        ];


        yield '--no-dev' => [
            'composerJson' => [
                'require' => [
                    'first/pkg' => '^2.0',
                    'second/pkg' => '3.*',
                ],
                'require-dev' => [
                    'dev/pkg' => '~2.0',
                ],
            ],
            'command' => ['--no-dev' => true],
            'lock' => false,
            'output' => "Checking non-dev platform requirements for packages in the vendor dir",
        ];
    }

    /**
     * @dataProvider provideThrowsNoLockFileFound
     * @param array<array<string>> $composerJson
     * @param array<boolean> $command
     * @return void
     */
    public function testRequireThrowsNoLockFileFound(array $composerJson, array $command): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage("No lockfile found. Unable to read locked packages");

        $this->initTempComposer($composerJson);

        $appTester = $this->getApplicationTester();
        $appTester->run(array_merge(['command' => 'check-platform-reqs'], $command));
    }

    public function provideThrowsNoLockFileFound(): Generator
    {
        yield 'by default without vendor folder' => [
            'composerJson' => [
                'require' => [
                    'first/pkg' => '^2.0',
                    'second/pkg' => '3.*',
                ],
                'require-dev' => [
                    'dev/pkg' => '~2.0',
                ],
            ],
            'command' => []
        ];

        yield '--lock without Vendor folder' => [
            'composerJson' => [
                'require' => [
                    'first/pkg' => '^2.0',
                    'second/pkg' => '3.*',
                ],
                'require-dev' => [
                    'dev/pkg' => '~2.0',
                ],
            ],
            'command' => ['--lock' => true],
        ];

        yield '--no-dev without vendor folder' => [
            'composerJson' => [
                'require' => [
                    'first/pkg' => '^2.0',
                    'second/pkg' => '3.*',
                ],
                'require-dev' => [
                    'dev/pkg' => '~2.0',
                ],
            ],
            'command' => ['--no-dev' => true]
        ];
    }
}
