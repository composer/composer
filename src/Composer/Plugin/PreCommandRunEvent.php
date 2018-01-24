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

/**
 * The pre command run event.
 *
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class PreCommandRunEvent extends Event
{
    /**
     * @var InputInterface
     */
    private $input;

    /**
     * Constructor.
     *
     * @param string         $name         The event name
     * @param InputInterface $input
     */
    public function __construct($name, InputInterface $input)
    {
        parent::__construct($name);
        $this->input = $input;
    }

    /**
     * Returns the console input
     *
     * @return InputInterface
     */
    public function getInput()
    {
        return $this->input;
    }
}
