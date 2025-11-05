<?php declare(strict_types=1);

namespace Composer\Test\Command;

use Composer\Test\TestCase;
use LogicException;

class ListPlatformReqsCommandTest extends TestCase
{
    /**
     * @dataProvider caseProvider
     * @param array<mixed> $composerJson
     * @param array<mixed> $command
     * @param string $expected
     * @param bool $lock
     */
    public function testListPlatformReqs(
        array $composerJson,
        array $command,
        string $expected,
        bool $lock = true
    ): void {
        $this->initTempComposer($composerJson);

        $packages = [
            self::getPackage('ext-foobar', '2.3.4'),
        ];
        $devPackages = [
            self::getPackage('ext-barbaz', '2.3.4.5'),
        ];

        $this->createInstalledJson($packages, $devPackages);

        if ($lock) {
            $this->createComposerLock($packages, $devPackages);
        }

        $appTester = $this->getApplicationTester();
        $appTester->run(array_merge(['command' => 'list-platform-reqs'], $command));

        $appTester->assertCommandIsSuccessful();
        if (isset($command['--format']) && $command['--format'] === 'json') {
            $actual = trim($appTester->getDisplay(true));
            self::assertSame(
                json_decode($expected, true),
                json_decode($actual, true)
            );
        } else {
            self::assertSame(trim($expected), trim($appTester->getDisplay(true)));
        }
    }

    public function testExceptionThrownIfNoLockfileFound(): void
    {
        $this->expectException(LogicException::class);
        $this->initTempComposer([]);
        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => 'list-platform-reqs', '--lock' => true]);
    }

    public static function caseProvider(): \Generator
    {
        yield 'Default text output with dev requirements' => [
            [
                'require' => [
                    'ext-foobar' => '^2.0',
                ],
                'require-dev' => [
                    'ext-barbaz' => '~4.0',
                ],
            ],
            [],
            "ext-barbaz: ~4.0\next-foobar: ^2.0",
        ];

        yield 'Text output with --no-dev' => [
            [
                'require' => [
                    'ext-foobar' => '^2.0',
                ],
                'require-dev' => [
                    'ext-barbaz' => '~4.0',
                ],
            ],
            ['--no-dev' => true],
            "ext-foobar: ^2.0",
        ];

        yield 'JSON output' => [
            [
                'require' => [
                    'ext-foobar' => '^2.0',
                ],
                'require-dev' => [
                    'ext-barbaz' => '~4.0',
                ],
            ],
            ['--format' => 'json'],
            '[{"name":"ext-barbaz","constraints":["~4.0"]},{"name":"ext-foobar","constraints":["^2.0"]}]',
        ];

        yield 'Text output with --lock' => [
            [
                'require' => [
                    'ext-foobar' => '^2.3',
                ],
                'require-dev' => [
                    'ext-barbaz' => '~2.0',
                ],
            ],
            ['--lock' => true],
            "ext-barbaz: ~2.0\next-foobar: ^2.3",
        ];
    }
}
