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

namespace Composer\Console;

/**
 * Encodes ConEmu-compatible OSC 9 progress reports.
 *
 * @see https://conemu.github.io/en/AnsiEscapeCodes.html#ConEmu_specific_OSC
 *
 * @internal
 */
final class Osc9ProgressEncoder extends AbstractOscProgramStatusEncoder
{
    public function getCommand(): string
    {
        return '9;4';
    }

    public function encodeStart(): ?string
    {
        // Progress and prompts activate the indicator instead of every command starting a spinner.
        return null;
    }

    public function encode(ProgramStatus $status): string
    {
        $progress = $status->getProgress();
        switch ($status->getState()) {
            case ProgramStatus::STATE_WORKING:
                return $progress === null ? '3' : '1;'.$progress;
            case ProgramStatus::STATE_BLOCKED:
                return '4'.($progress === null ? '' : ';'.$progress);
            case ProgramStatus::STATE_ERROR:
                return '2';
            default:
                // Completion and cancellation remove the progress indicator.
                return '0';
        }
    }
}
