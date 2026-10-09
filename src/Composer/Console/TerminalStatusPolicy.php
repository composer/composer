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

use Composer\Pcre\Preg;
use Composer\Util\Platform;

/**
 * Selects terminal status protocols without reading terminal input.
 */
final class TerminalStatusPolicy
{
    /** @var list<AbstractOscProgramStatusEncoder> */
    private $encoders;

    /** @param array<string, string> $environment */
    public function __construct(array $environment)
    {
        $protocols = $this->selectProtocols($environment);
        $this->encoders = [];
        // Send rich status last so a mapped progress report cannot overwrite it.
        if (in_array('9;4', $protocols, true)) {
            $this->encoders[] = new Osc9ProgressEncoder();
        }
        if (in_array('7501', $protocols, true)) {
            $this->encoders[] = new Osc7501ProgramStatusEncoder();
        }
    }

    public static function fromEnvironment(): self
    {
        $environment = [];
        foreach (['COMPOSER_TERMINAL_STATUS', 'TERM', 'TERM_PROGRAM', 'TERM_PROGRAM_VERSION', 'WT_SESSION', 'ConEmuANSI', 'TMUX', 'STY', 'SSH_TTY', 'SSH_CONNECTION'] as $name) {
            $value = Platform::getEnv($name);
            if ($value !== false) {
                $environment[$name] = $value;
            }
        }

        return new self($environment);
    }

    /** @return list<AbstractOscProgramStatusEncoder> */
    public function getEncoders(): array
    {
        return $this->encoders;
    }

    /**
     * @param array<string, string> $environment
     * @return list<string>
     */
    private function selectProtocols(array $environment): array
    {
        $setting = trim($environment['COMPOSER_TERMINAL_STATUS'] ?? 'auto');
        if ($setting === '' || $setting === 'auto') {
            return $this->supportsProgress($environment) ? ['7501', '9;4'] : ['7501'];
        }
        if ($setting === 'off') {
            return [];
        }

        $protocols = array_map('trim', explode(',', $setting));
        foreach ($protocols as $protocol) {
            if (!in_array($protocol, ['7501', '9;4'], true)) {
                throw new \InvalidArgumentException('COMPOSER_TERMINAL_STATUS must be auto, off, 7501, 9;4, or a comma-separated list of protocols.');
            }
        }

        return array_values(array_unique($protocols));
    }

    /** @param array<string, string> $environment */
    private function supportsProgress(array $environment): bool
    {
        // Outer terminal identity does not establish support through another terminal.
        foreach (['TMUX', 'STY', 'SSH_TTY', 'SSH_CONNECTION'] as $name) {
            if (($environment[$name] ?? '') !== '') {
                return false;
            }
        }
        if (Preg::isMatch('{^(?:screen|tmux)(?:[-.]|$)}', $environment['TERM'] ?? '')) {
            return false;
        }
        if (($environment['WT_SESSION'] ?? '') !== '' || ($environment['ConEmuANSI'] ?? '') === 'ON') {
            return true;
        }

        $minimumVersions = ['ghostty' => '1.2.0', 'iTerm.app' => '3.6.6'];
        $program = $environment['TERM_PROGRAM'] ?? '';
        $version = $environment['TERM_PROGRAM_VERSION'] ?? '';

        return isset($minimumVersions[$program])
            && Preg::isMatch('{^\d+\.\d+\.\d+$}', $version)
            && version_compare($version, $minimumVersions[$program], '>=');
    }
}
