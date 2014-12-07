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

namespace Composer\Console;

use Symfony\Component\Console\Output\ConsoleOutput as BaseOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @author Alexandru G. <alex@gentle.ro>
 */
class ConsoleOutput extends BaseOutput
{
    /**
     * {@inheritDoc}
     */
    public function write($messages, $newline = false, $type = 0)
    {
        if (self::VERBOSITY_QUIET === $this->getVerbosity()) {
            return;
        }

        $messages = (array) $messages;

        foreach ($messages as $message) {
            $stream = (strpos($message, '<error>') !== false)
                ? $this->getErrorOutput()->getStream()
                : $this->getStream()
            ;

            switch ($type) {
                case OutputInterface::OUTPUT_NORMAL:
                    $message = $this->getFormatter()->format($message);
                    break;
                case OutputInterface::OUTPUT_RAW:
                    break;
                case OutputInterface::OUTPUT_PLAIN:
                    $message = strip_tags($this->getFormatter()->format($message));
                    break;
                default:
                    throw new \InvalidArgumentException(sprintf('Unknown output type given (%s)', $type));
            }

            $this->doWrite($message, $newline, $stream);
        }
    }

    /**
     * Writes a message to the output.
     *
     * If no `$stream` resource is provided, stdout will be used.
     *
     * @param string  $message A message to write to the output
     * @param Boolean $newline Whether to add a newline or not
     * @param resource $stream (optional) Stream resource used for output
     *
     * @throws \RuntimeException When unable to write output (should never happen)
     */
    protected function doWrite($message, $newline, $stream = null)
    {
        if (!is_resource($stream) || 'stream' !== get_resource_type($stream)) {
            $stream = $this->getStream();
        }

        if (false === @fwrite($stream, $message.($newline ? PHP_EOL : ''))) {
            // @codeCoverageIgnoreStart
            // should never happen
            throw new \RuntimeException('Unable to write output.');
            // @codeCoverageIgnoreEnd
        }

        fflush($stream);
    }
}
