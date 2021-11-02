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
     *
     * @return void
     */
    public function emit($message, $file = null, $line = null)
    {
        if (getenv('GITHUB_ACTIONS') && !getenv('COMPOSER_TESTS_ARE_RUNNING')) {
            $message = $this->escapeData($message);

            if ($file && $line) {
                $file = $this->escapeProperty($file);
                $this->io->write("::error file=". $file .",line=". $line ."::". $message);
            } elseif ($file) {
                $file = $this->escapeProperty($file);
                $this->io->write("::error file=". $file ."::". $message);
            } else {
                $this->io->write("::error ::". $message);
            }
        }
    }
    
    /**
     * @param string $data
     * @return string
     */
    private function escapeData($data) {
        // see https://github.com/actions/toolkit/blob/4f7fb6513a355689f69f0849edeb369a4dc81729/packages/core/src/command.ts#L80-L85
        $data = str_replace("%", '%25', $data);
        $data = str_replace("\r", '%0D', $data);
        $data = str_replace("\n", '%0A', $data);

        return $data;
    }
    
    /**
     * @param string $property
     * @return string
     */
    private function escapeProperty($property) {
        // see https://github.com/actions/toolkit/blob/4f7fb6513a355689f69f0849edeb369a4dc81729/packages/core/src/command.ts#L87-L94
        $property = str_replace("%", '%25', $property);
        $property = str_replace("\r", '%0D', $property);
        $property = str_replace("\n", '%0A', $property);
        $property = str_replace(":", '%3A', $property);
        $property = str_replace(",", '%2C', $property);

        return $property;
    }
}
