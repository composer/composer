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

namespace Composer\Plugin;

use Composer\EventDispatcher\Event;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * An event for all commands.
 *
 * @author Nils Adermann <naderman@naderman.de>
 */
class CommandEvent extends Event
{
    /**
     * @var string
     */
    private $commandName;

    /**
     * @var InputInterface
     */
    private $input;

    /**
     * @var OutputInterface
     */
    private $output;

    /**
     * Constructor.
     *
     * @param string          $name        The event name
     * @param string          $commandName The command name
     * @param InputInterface  $input
     * @param OutputInterface $output
     */
    public function __construct($name, $commandName, $input, $output)
    {
        parent::__construct($name);
        $this->commandName = $commandName;
        $this->input = $input;
        $this->output = $output;
    }

    /**
     * Returns the command input interface
     *
     * @return InputInterface
     */
    public function getInput()
    {
        return $this->input;
    }

    /**
     * Retrieves the command output interface
     *
     * @return OutputInterface
     */
    public function getOutput()
    {
        return $this->output;
    }

    /**
     * Retrieves the name of the command being run
     *
     * @return string
     */
    public function getCommandName()
    {
        return $this->commandName;
    }
}
