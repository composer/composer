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

namespace Composer\Util;

use Symfony\Component\Process\Process as BaseProcess;

/**
 * @author Robert Schönthal <seroscho@googlemail.com>
 */
class Process extends BaseProcess
{
    /**
     * runs a process on the commandline
     *
     * @static
     * @param $command the command to execute
     * @param null $output the output will be written into this var if passed
     * @return int statuscode
     */
    public static function execute($command, &$output = null)
    {
        $process = new static($command);
        $process->run(function($type, $buffer) use ($output) {
            if (null === $output) {
               return;
            }

            echo $buffer;
        });

        if (null !== $output) {
           $output = $process->getOutput();
        }

        return $process->getExitCode();
    }
}