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

namespace Composer\Test\Mock;

use Composer\IO\IOInterface;

class ConsoleIOMock implements IOInterface, \IteratorAggregate, \Countable
{
    protected $lines = array();

    public function __toString()
    {
        return implode("\n", $this->lines);
    }

    public function count()
    {
        return count($this->lines);
    }

    public function getIterator()
    {
        return new \ArrayIterator($this->lines);
    }

    public function isInteractive()
    {
    }

    public function isDecorated()
    {
    }

    public function isVerbose()
    {
    }

    public function write($messages, $newline = true)
    {
        if ($newline) {
            $this->lines[] = $messages;
        } else {
            $this->lines[key($this->lines)] .= $messages;
        }
    }

    public function overwrite($messages, $newline = true, $size = null)
    {
    }

    public function ask($question, $default = null)
    {
    }

    public function askConfirmation($question, $default = true)
    {
    }

    public function askAndValidate($question, $validator, $attempts = false, $default = null)
    {
    }

    public function askAndHideAnswer($question)
    {
    }

    public function getAuthorizations()
    {
    }

    public function hasAuthorization($repositoryName)
    {
    }

    public function getAuthorization($repositoryName)
    {
    }

    public function setAuthorization($repositoryName, $username, $password = null)
    {
    }
}
