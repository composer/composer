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

namespace Composer\Console;

use Composer\IO\IOInterface;

final class GithubActionError
{
    /**
     * @var IOInterface
     */
    protected $io;

    public function __construct(IOInterface  $io)
    {
        $this->io = $io;
    }

    /**
     * @param string      $message
     * @param null|string $file
     * @param null|int    $line
     */
    public function emit($message, $file = null, $line = null)
    {
        if (getenv('GITHUB_ACTIONS') && !getenv('COMPOSER_TESTS_ARE_RUNNING')) {
            // newlines need to be encoded
            // see https://github.com/actions/starter-workflows/issues/68#issuecomment-581479448
            $message = str_replace("\n", '%0A', $message);

            if ($file && $line) {
                $this->io->write("::error file=". $file .",line=". $line ."::". $message);
            } elseif ($file) {
                $this->io->write("::error file=". $file ."::". $message);
            } else {
                $this->io->write("::error ::". $message);
            }
        }
    }
}
