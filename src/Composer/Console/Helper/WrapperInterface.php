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

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Helper\HelperInterface;

/**
 * Helper wrapper interface.
 *
 * @author François Pluchino <francois.pluchino@opendisplay.com>
 */
interface WrapperInterface
{
    /**
     * Returns an InputInterface instance.
     *
     * @return InputInterface "InputArgument", "InputOption", "InputDefinition"
     */
    function getInput();

    /**
     * Set an InputInterface instance.
     *
     * @param InputInterface $input The input
     */
    function setInput(InputInterface $input);

    /**
     * Returns an ConsoleOutput instance.
     *
     * @return ConsoleOutputInterface
     */
    function getOutput();

    /**
     * Set an ConsoleOutput instance.
     *
     * @param ConsoleOutputInterface $output The output
     */
    function setOutput(ConsoleOutputInterface $output);

    /**
     * Returns an HelperInterface instance.
     *
     * @return HelperInterface
     */
    function getHelper();

    /**
     * Set an HelperInterface instance.
     *
     * @param HelperInterface $helper The helper
     */
    function setHelper(HelperInterface $helper);

    /**
     * Overwrites a previous message to the output.
     *
     * @param string|array $messages The message as an array of lines of a single string
     * @param integer      $size     The size of line
     * @param Boolean      $newline  Whether to add a newline or not
     * @param integer      $type     The type of output
     */
    public function overwrite($messages, $size = 80, $newline = false, $type = 0);

    /**
     * Overwrites a previous message to the output and adds a newline at the end.
     *
     * @param string|array $messages The message as an array of lines of a single string
     * @param integer      $size     The size of line
     * @param integer      $type     The type of output
     */
    public function overwriteln($messages, $size = 80, $type = 0);

    /**
     * Interactively prompts for input without echoing to the terminal.
     *
     * @param string $title The title of prompt (used only for windows)
     *
     * @return string The value
     */
    public function promptSilent($title = '');
}
