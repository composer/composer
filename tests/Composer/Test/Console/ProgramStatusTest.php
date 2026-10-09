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

namespace Composer\Test\Console;

use Composer\Console\ProgramStatus;
use Composer\Test\TestCase;

class ProgramStatusTest extends TestCase
{
    /**
     * @dataProvider provideExitCodes
     */
    public function testFromExitCode(int $exitCode, string $state): void
    {
        self::assertSame($state, ProgramStatus::fromExitCode($exitCode)->getState());
    }

    /**
     * @return array<string, array{int, string}>
     */
    public static function provideExitCodes(): array
    {
        return [
            'success' => [0, 'done'],
            'failure' => [1, 'error'],
            'command not found' => [127, 'error'],
            'hangup' => [129, 'idle'],
            'interrupted' => [130, 'idle'],
            'terminated' => [143, 'idle'],
            'other signal' => [137, 'error'],
            'negative exit code' => [-1, 'error'],
        ];
    }

    public function testProgressReturnsCloneAndCanBeCleared(): void
    {
        $status = ProgramStatus::working();
        $progress = $status->withProgress(40);
        self::assertNotSame($status, $progress);
        self::assertSame(ProgramStatus::STATE_WORKING, $status->getState());
        self::assertNull($status->getProgress());
        self::assertSame(40, $progress->getProgress());
        self::assertNull($progress->withProgress(null)->getProgress());
    }

    public function testRejectsUnknownState(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ProgramStatus('unknown');
    }

    public function testProtocolOptionsReturnIndependentClones(): void
    {
        $original = ProgramStatus::blocked('Continue?')->withProgress(42);
        $first = $original->withProtocolOption('osc7501', 'kind', 'foobar');
        $second = $first->withProtocolOption('oscFuture', 'kind', false);
        $replaced = $second->withProtocolOption('osc7501', 'kind', 'permission');

        self::assertNotSame($original, $first);
        self::assertNotSame($first, $second);
        self::assertNotSame($second, $replaced);
        self::assertNull($original->getProtocolOption('osc7501', 'kind'));
        self::assertNull($first->getProtocolOption('oscFuture', 'kind'));
        self::assertSame('foobar', $second->getProtocolOption('osc7501', 'kind'));
        self::assertFalse($second->getProtocolOption('oscFuture', 'kind'));
        self::assertSame('permission', $replaced->getProtocolOption('osc7501', 'kind'));
        self::assertSame('Continue?', $replaced->getMessage());
        self::assertSame(42, $replaced->getProgress());
        self::assertNull($replaced->withProtocolOption('osc7501', 'kind', null)->getProtocolOption('osc7501', 'kind'));
        self::assertSame('permission', $replaced->getProtocolOption('osc7501', 'kind'));
    }

    /**
     * @dataProvider provideProtocolOptionValues
     * @param mixed $value
     */
    public function testPreservesProtocolOptionValues($value): void
    {
        $status = ProgramStatus::working()->withProtocolOption('oscFuture', 'option', $value);
        self::assertSame($value, $status->getProtocolOption('oscFuture', 'option'));
    }

    public static function provideProtocolOptionValues(): array
    {
        return [
            [false],
            [true],
            [0],
            [42],
            [1.5],
            [''],
            ['value'],
            [null],
            [[]],
            [['a', false, 42]],
            [['nested' => ['enabled' => false, 'limit' => null]]],
            [new \stdClass()],
            [static function (): void {
            }],
            [['nested' => new \stdClass()]],
        ];
    }

    public function testPreservesResourceProtocolOption(): void
    {
        $resource = fopen('php://memory', 'w+');
        self::assertIsResource($resource);
        try {
            $status = ProgramStatus::working()->withProtocolOption('oscFuture', 'stream', $resource);
            self::assertSame($resource, $status->getProtocolOption('oscFuture', 'stream'));
        } finally {
            fclose($resource);
        }
    }

    public function testRejectsInvalidProgress(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ProgramStatus::working()->withProgress(101);
    }
}
