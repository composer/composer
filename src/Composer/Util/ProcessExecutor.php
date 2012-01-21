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

use Symfony\Component\Process\Process;

/**
 * @author Robert Schönthal <seroscho@googlemail.com>
 */
class ProcessExecutor
{
    /**
     * runs a process on the commandline
     *
     * @param $command the command to execute
     * @param null $output the output will be written into this var if passed
     * @return int statuscode
     */
    public function execute($command, &$output = null)
    {
        $process = new Process($command);
        $process->run(function($type, $buffer) use ($output) {
            if (null === $output) {
               return;
            }

            echo $buffer;
        });

        //if (null !== $output) {
           //$output = $process->getOutput();
        //}
        $output = explode("\n", $process->getOutput());
        
        return $process->getExitCode();
    }
}