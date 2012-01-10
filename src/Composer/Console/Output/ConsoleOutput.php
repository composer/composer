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

namespace Composer\Console\Output;

use Symfony\Component\Console\Output\ConsoleOutput as BaseConsoleOutput;

/**
 * ConsoleOutput is the default class for all CLI output.
 *
 * @author François Pluchino <francois.pluchino@opendisplay.com>
 */
class ConsoleOutput extends BaseConsoleOutput
{
    /**
     * Overwrites a previous message to the output.
     *
     * @param string|array $messages The message as an array of lines of a single string
     * @param integer      $size     The size of line
     * @param Boolean      $newline  Whether to add a newline or not
     * @param integer      $type     The type of output
     */
    public function overwrite($messages, $size = 80, $newline = false, $type = 0)
    {
        for ($place = $size; $place > 0; $place--) {
            $this->write("\x08");
        }

        $this->write($messages, false, $type);

        for ($place = ($size - strlen($messages)); $place > 0; $place--) {
            $this->write(' ');
        }

        // clean up the end line
        for ($place = ($size - strlen($messages)); $place > 0; $place--) {
            $this->write("\x08");
        }

        if ($newline) {
            $this->writeln('');
        }
    }

    /**
     * Overwrites a previous message to the output and adds a newline at the end.
     *
     * @param string|array $messages The message as an array of lines of a single string
     * @param integer      $size     The size of line
     * @param integer      $type     The type of output
     */
    public function overwriteln($messages, $size = 80, $type = 0)
    {
        $this->write($messages, $size, true, $type);
    }

    /**
     * Interactively prompts for input without echoing to the terminal.
     * Requires a bash shell or Windows and won't work with safe_mode
     * settings (Uses `shell_exec`).
     *
     * @param string $title The title of prompt (only for windows)
     *
     * @return string The value
     */
    public function promptSilent($title = '')
    {
        if (preg_match('/^win/i', PHP_OS)) {
            $vbscript = sys_get_temp_dir() . '/prompt_password.vbs';
            file_put_contents($vbscript,
                    'wscript.echo(Inputbox("' . addslashes($title) . '","'
                            . addslashes($title) . '", ""))');
            $command = "cscript //nologo " . escapeshellarg($vbscript);
            $value = rtrim(shell_exec($command));
            unlink($vbscript);
            $this->writeln('');

            return $value;

        } else {
            $command = "/usr/bin/env bash -c 'echo OK'";

            if (rtrim(shell_exec($command)) !== 'OK') {
                trigger_error("Can't invoke bash");
                return;
            }

            $command = "/usr/bin/env bash -c 'read -s mypassword && echo \$mypassword'";
            $value = rtrim(shell_exec($command));
            $this->writeln('');

            return $value;
        }
    }
}
