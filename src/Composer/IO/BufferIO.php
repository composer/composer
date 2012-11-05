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

namespace Composer\IO;

use Symfony\Component\Console\Output\StreamOutput;
use Symfony\Component\Console\Formatter\OutputFormatterInterface;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Helper\HelperSet;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class BufferIO extends ConsoleIO
{
    /**
     * @param string $input
     * @param int    $verbosity
     */
    public function __construct($input = '', $verbosity = null, OutputFormatterInterface $formatter = null)
    {
        $input = new StringInput($input);
        $input->setInteractive(false);

        $output = new StreamOutput(fopen('php://memory', 'rw'), $verbosity === null ? StreamOutput::VERBOSITY_NORMAL : $verbosity, !empty($formatter), $formatter);

        parent::__construct($input, $output, new HelperSet(array()));
    }

    public function getOutput()
    {
        fseek($this->output->getStream(), 0);

        return stream_get_contents($this->output->getStream());
    }
}
