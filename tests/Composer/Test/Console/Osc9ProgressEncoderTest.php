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

use Composer\Console\Osc9ProgressEncoder;
use Composer\Console\ProgramStatus;
use Composer\Test\TestCase;

class Osc9ProgressEncoderTest extends TestCase
{
    /** @dataProvider provideStatuses */
    public function testEncodesProgress(ProgramStatus $status, string $expected): void
    {
        $encoder = new Osc9ProgressEncoder();
        self::assertSame('9;4', $encoder->getCommand());
        self::assertSame($expected, $encoder->encode($status));
    }

    public function testIgnoresOtherProtocolOptions(): void
    {
        $status = ProgramStatus::blocked()
            ->withProtocolOption('osc7501', 'kind', 'foobar')
            ->withProtocolOption('oscFuture', 'whatnot', false);
        self::assertSame('4', (new Osc9ProgressEncoder())->encode($status));
    }

    public static function provideStatuses(): array
    {
        return [
            'unknown progress' => [ProgramStatus::working(), '3'],
            'zero progress' => [ProgramStatus::working()->withProgress(0), '1;0'],
            'percentage' => [ProgramStatus::working('Installing')->withProgress(42), '1;42'],
            'full progress' => [ProgramStatus::working()->withProgress(100), '1;100'],
            'blocked' => [ProgramStatus::blocked('Password?')->withProtocolOption('osc7501', 'kind', 'auth'), '4'],
            'blocked with progress' => [ProgramStatus::blocked()->withProgress(42), '4;42'],
            'error' => [ProgramStatus::error('Failed'), '2'],
            'done' => [ProgramStatus::done(), '0'],
            'idle' => [ProgramStatus::idle(), '0'],
            'clear' => [ProgramStatus::clear(), '0'],
            'cancelled' => [ProgramStatus::fromExitCode(130), '0'],
        ];
    }
}
