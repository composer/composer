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

use Composer\Console\AbstractOscProgramStatusEncoder;
use Composer\Console\TerminalStatusPolicy;
use Composer\Test\TestCase;

class TerminalStatusPolicyTest extends TestCase
{
    /**
     * @dataProvider provideEnvironments
     * @param array<string, string> $environment
     * @param list<string> $expected
     */
    public function testSelectsProtocols(array $environment, array $expected): void
    {
        $policy = new TerminalStatusPolicy($environment);
        self::assertSame($expected, array_map(static function (AbstractOscProgramStatusEncoder $encoder): string {
            return $encoder->getCommand();
        }, $policy->getEncoders()));
    }

    public static function provideEnvironments(): array
    {
        $ghostty = ['TERM_PROGRAM' => 'ghostty', 'TERM_PROGRAM_VERSION' => '1.2.0'];

        return [
            'unknown' => [[], ['7501']],
            'auto' => [['COMPOSER_TERMINAL_STATUS' => 'auto'], ['7501']],
            'empty default' => [['COMPOSER_TERMINAL_STATUS' => ''], ['7501']],
            'ANSI support alone' => [['TERM' => 'xterm-256color'], ['7501']],
            'Windows Terminal' => [['WT_SESSION' => 'session-id'], ['9;4', '7501']],
            'ConEmu' => [['ConEmuANSI' => 'ON'], ['9;4', '7501']],
            'ConEmu disabled' => [['ConEmuANSI' => 'OFF'], ['7501']],
            'Ghostty' => [$ghostty, ['9;4', '7501']],
            'newer Ghostty' => [['TERM_PROGRAM' => 'ghostty', 'TERM_PROGRAM_VERSION' => '1.3.0'], ['9;4', '7501']],
            'older Ghostty' => [['TERM_PROGRAM' => 'ghostty', 'TERM_PROGRAM_VERSION' => '1.1.3'], ['7501']],
            'missing version' => [['TERM_PROGRAM' => 'ghostty'], ['7501']],
            'unrecognized version' => [['TERM_PROGRAM' => 'ghostty', 'TERM_PROGRAM_VERSION' => 'unknown'], ['7501']],
            'iTerm2' => [['TERM_PROGRAM' => 'iTerm.app', 'TERM_PROGRAM_VERSION' => '3.6.6'], ['9;4', '7501']],
            'older iTerm2' => [['TERM_PROGRAM' => 'iTerm.app', 'TERM_PROGRAM_VERSION' => '3.6.5'], ['7501']],
            'unknown program' => [['TERM_PROGRAM' => 'Other', 'TERM_PROGRAM_VERSION' => '99.0.0'], ['7501']],
            'tmux' => [$ghostty + ['TMUX' => '/socket'], ['7501']],
            'screen' => [$ghostty + ['STY' => 'session'], ['7501']],
            'screen TERM' => [$ghostty + ['TERM' => 'screen-256color'], ['7501']],
            'tmux TERM' => [$ghostty + ['TERM' => 'tmux-256color'], ['7501']],
            'SSH connection' => [$ghostty + ['SSH_CONNECTION' => 'connection'], ['7501']],
            'SSH terminal' => [$ghostty + ['SSH_TTY' => '/dev/pts/1'], ['7501']],
            'off' => [$ghostty + ['COMPOSER_TERMINAL_STATUS' => 'off'], []],
            '7501 only' => [$ghostty + ['COMPOSER_TERMINAL_STATUS' => '7501'], ['7501']],
            'progress only' => [['COMPOSER_TERMINAL_STATUS' => '9;4'], ['9;4']],
            'both explicit' => [['COMPOSER_TERMINAL_STATUS' => '7501,9;4'], ['9;4', '7501']],
            'explicit through tmux' => [['COMPOSER_TERMINAL_STATUS' => '7501,9;4', 'TMUX' => '/socket'], ['9;4', '7501']],
            'duplicates' => [['COMPOSER_TERMINAL_STATUS' => '9;4,7501,9;4'], ['9;4', '7501']],
            'whitespace' => [['COMPOSER_TERMINAL_STATUS' => ' 7501, 9;4 '], ['9;4', '7501']],
        ];
    }

    /** @dataProvider provideInvalidSettings */
    public function testRejectsInvalidSetting(string $setting): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new TerminalStatusPolicy(['COMPOSER_TERMINAL_STATUS' => $setting]);
    }

    public static function provideInvalidSettings(): array
    {
        return [['unknown'], ['auto,7501'], ['off,9;4'], ['7501,'], ['7501,unknown']];
    }
}
