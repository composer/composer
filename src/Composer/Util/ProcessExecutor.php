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
    static protected $timeout = 60;

    /**
     * runs a process on the commandline
     *
     * @param $command the command to execute
     * @param null $output the output will be written into this var if passed
     * @return int statuscode
     */
    public function execute($command, &$output = null)
    {
        $captureOutput = count(func_get_args()) > 1;
        $process = new Process($command, null, null, null, static::getTimeout());
        $process->run(function($type, $buffer) use ($captureOutput) {
            if ($captureOutput) {
                return;
            }

            echo $buffer;
        });

        if ($captureOutput) {
            $output = $process->getOutput();
        }

        return $process->getExitCode();
    }

    public function splitLines($output)
    {
        return ((string) $output === '') ? array() : preg_split('{\r?\n}', $output);
    }

    static public function getTimeout()
    {
        return static::$timeout;
    }

    static public function setTimeout($timeout)
    {
        static::$timeout = $timeout;
    }
}
