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

namespace Composer\IO;

/**
 * IOInterface that is not interactive and never writes the output
 *
 * @author Christophe Coevoet <stof@notk.org>
 */
class NullIO extends BaseIO
{
    /**
     * @inheritDoc
     */
    public function isInteractive(): bool
    {
        return false;
    }

    /**
     * @inheritDoc
     */
    public function isVerbose(): bool
    {
        return false;
    }

    /**
     * @inheritDoc
     */
    public function isVeryVerbose(): bool
    {
        return false;
    }

    /**
     * @inheritDoc
     */
    public function isDebug(): bool
    {
        return false;
    }

    /**
     * @inheritDoc
     */
    public function isDecorated(): bool
    {
        return false;
    }

    /**
     * @inheritDoc
     */
    public function write($messages, bool $newline = true, int $verbosity = self::NORMAL): void
    {
    }

    /**
     * @inheritDoc
     */
    public function writeError($messages, bool $newline = true, int $verbosity = self::NORMAL): void
    {
    }

    /**
     * @inheritDoc
     */
    public function overwrite($messages, bool $newline = true, ?int $size = null, int $verbosity = self::NORMAL): void
    {
    }

    /**
     * @inheritDoc
     */
    public function overwriteError($messages, bool $newline = true, ?int $size = null, int $verbosity = self::NORMAL): void
    {
    }

    /**
     * @inheritDoc
     */
    public function ask($question, $default = null)
    {
        return $default;
    }

    /**
     * @inheritDoc
     */
    public function askConfirmation($question, $default = true): bool
    {
        return $default;
    }

    /**
     * @inheritDoc
     */
    public function askAndValidate($question, $validator, $attempts = null, $default = null)
    {
        return $default;
    }

    /**
     * @inheritDoc
     */
    public function askAndHideAnswer($question): ?string
    {
        return null;
    }

    /**
     * @inheritDoc
     */
    public function select($question, $choices, $default, $attempts = false, $errorMessage = 'Value "%s" is invalid', $multiselect = false)
    {
        return $default;
    }
}
