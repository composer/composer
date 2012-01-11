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

namespace Composer\Console\Helper;

use Composer\Console\Helper\WrapperInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Helper\HelperInterface;

/**
 * Helper wrapper.
 *
 * @author François Pluchino <francois.pluchino@opendisplay.com>
 */
class Wrapper implements WrapperInterface
{
    protected $input;
    protected $output;
    protected $helper;

    /**
     * Constructor.
     *
     * @param InputInterface         $input  The input instance
     * @param ConsoleOutputInterface $output The output instance
     * @param HelperInterface        $helper The helper instance
     */
    public function __construct(InputInterface $input, ConsoleOutputInterface $output, HelperInterface $helper = null)
    {
        $this->input = $input;
        $this->output = $output;
        $this->helper = $helper;
    }

    /**
     * {@inheritDoc}
     */
    public function getInput()
    {
        return $this->input;
    }

    /**
     * {@inheritDoc}
     */
    public function setInput(InputInterface $input)
    {
        $this->input = $input;
    }

    /**
     * {@inheritDoc}
     */
    public function getOutput()
    {
        return $this->output;
    }

    /**
     * {@inheritDoc}
     */
    public function setOutput(ConsoleOutputInterface $output)
    {
        $this->output = $output;
    }

    /**
     * {@inheritDoc}
     */
    public function getHelper()
    {
        return $this->helper;
    }

    /**
     * {@inheritDoc}
     */
    public function setHelper(HelperInterface $helper)
    {
        $this->helper = $helper;
    }

    /**
     * {@inheritDoc}
     */
    public function overwrite($messages, $size = 80, $newline = false, $type = 0)
    {
        for ($place = $size; $place > 0; $place--) {
            $this->getOutput()->write("\x08");
        }

        $this->getOutput()->write($messages, false, $type);

        for ($place = ($size - strlen($messages)); $place > 0; $place--) {
            $this->getOutput()->write(' ');
        }

        // clean up the end line
        for ($place = ($size - strlen($messages)); $place > 0; $place--) {
            $this->getOutput()->write("\x08");
        }

        if ($newline) {
            $this->getOutput()->writeln('');
        }
    }

    /**
     * {@inheritDoc}
     */
    public function overwriteln($messages, $size = 80, $type = 0)
    {
        $this->overwrite($messages, $size, true, $type);
    }

    /**
     * {@inheritDoc}
     */
    public function promptSilent($title = '')
    {
        // for windows OS
        if (preg_match('/^win/i', PHP_OS)) {
            $vbscript = sys_get_temp_dir() . '/prompt_password.vbs';
            file_put_contents($vbscript,
                    'wscript.echo(Inputbox("' . addslashes($title) . '","'
                            . addslashes($title) . '", ""))');
            $command = "cscript //nologo " . escapeshellarg($vbscript);
            $value = rtrim(shell_exec($command));
            unlink($vbscript);
            $this->getOutput()->writeln('');

            return $value;
        }

        // for other OS
        else {
            $command = "/usr/bin/env bash -c 'echo OK'";

            if (rtrim(shell_exec($command)) !== 'OK') {
                throw new \RuntimeException("Can't invoke bash for silent prompt");
            }

            $command = "/usr/bin/env bash -c 'read -s mypassword && echo \$mypassword'";
            $value = rtrim(shell_exec($command));
            $this->getOutput()->writeln('');

            return $value;
        }
    }
}
