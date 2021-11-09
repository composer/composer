<?php

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
    public function isInteractive()
    {
        return false;
    }

    /**
     * @inheritDoc
     */
    public function isVerbose()
    {
        return false;
    }

    /**
     * @inheritDoc
     */
    public function isVeryVerbose()
    {
        return false;
    }

    /**
     * @inheritDoc
     */
    public function isDebug()
    {
        return false;
    }

    /**
     * @inheritDoc
     */
    public function isDecorated()
    {
        return false;
    }

    /**
     * @inheritDoc
     */
    public function write($messages, $newline = true, $verbosity = self::NORMAL)
    {
    }

    /**
     * @inheritDoc
     */
    public function writeError($messages, $newline = true, $verbosity = self::NORMAL)
    {
    }

    /**
     * @inheritDoc
     */
    public function overwrite($messages, $newline = true, $size = 80, $verbosity = self::NORMAL)
    {
    }

    /**
     * @inheritDoc
     */
    public function overwriteError($messages, $newline = true, $size = 80, $verbosity = self::NORMAL)
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
    public function askConfirmation($question, $default = true)
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
    public function askAndHideAnswer($question)
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
