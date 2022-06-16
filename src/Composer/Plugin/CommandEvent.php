<?php declare(strict_types=1);

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
     * @param mixed[]         $args        Arguments passed by the user
     * @param mixed[]         $flags       Optional flags to pass data not as argument
     */
    public function __construct(string $name, string $commandName, InputInterface $input, OutputInterface $output, array $args = [], array $flags = [])
    {
        parent::__construct($name, $args, $flags);
        $this->commandName = $commandName;
        $this->input = $input;
        $this->output = $output;
    }

    /**
     * Returns the command input interface
     *
     * @return InputInterface
     */
    public function getInput(): InputInterface
    {
        return $this->input;
    }

    /**
     * Retrieves the command output interface
     *
     * @return OutputInterface
     */
    public function getOutput(): OutputInterface
    {
        return $this->output;
    }

    /**
     * Retrieves the name of the command being run
     *
     * @return string
     */
    public function getCommandName(): string
    {
        return $this->commandName;
    }
}
