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
class NullIO implements IOInterface
{
    /**
     * {@inheritDoc}
     */
    public function isInteractive()
    {
        return false;
    }

    /**
     * {@inheritDoc}
     */
    public function isVerbose()
    {
        return false;
    }

    /**
     * {@inheritDoc}
     */
    public function isDecorated()
    {
        return false;
    }

    /**
     * {@inheritDoc}
     */
    public function write($messages, $newline = true)
    {
    }

    /**
     * {@inheritDoc}
     */
    public function overwrite($messages, $newline = true, $size = 80)
    {
    }

    /**
     * {@inheritDoc}
     */
    public function ask($question, $default = null)
    {
        return $default;
    }

    /**
     * {@inheritDoc}
     */
    public function askConfirmation($question, $default = true)
    {
        return $default;
    }

    /**
     * {@inheritDoc}
     */
    public function askAndValidate($question, $validator, $attempts = false, $default = null)
    {
        return $default;
    }

    /**
     * {@inheritDoc}
     */
    public function askAndHideAnswer($question)
    {
        return null;
    }

    /**
     * {@inheritDoc}
     */
    public function getLastUsername()
    {
        return null;
    }

    /**
     * {@inheritDoc}
     */
    public function getLastPassword()
    {
        return null;
    }

    /**
     * {@inheritDoc}
     */
    public function getAuthorizations()
    {
        return array();
    }

    /**
     * {@inheritDoc}
     */
    public function hasAuthorization($repositoryName)
    {
        return false;
    }

    /**
     * {@inheritDoc}
     */
    public function getAuthorization($repositoryName)
    {
        return array('username' => null, 'password' => null);
    }

    /**
     * {@inheritDoc}
     */
    public function setAuthorization($repositoryName, $username, $password = null)
    {
    }
}
