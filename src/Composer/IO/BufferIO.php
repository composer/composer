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

namespace Composer\IO;

use Composer\Pcre\Preg;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;
use Symfony\Component\Console\Formatter\OutputFormatterInterface;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Helper\HelperSet;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class BufferIO extends ConsoleIO
{
    public function __construct(string $input = '', int $verbosity = StreamOutput::VERBOSITY_NORMAL, ?OutputFormatterInterface $formatter = null)
    {
        $input = new StringInput($input);
        $input->setInteractive(false);

        $stream = fopen('php://memory', 'rw');
        if ($stream === false) {
            throw new \RuntimeException('Unable to open memory output stream');
        }
        $output = new StreamOutput($stream, $verbosity, $formatter !== null ? $formatter->isDecorated() : false, $formatter);

        parent::__construct($input, $output, new HelperSet([
            new QuestionHelper(),
        ]));
    }

    /**
     * @return string output
     */
    public function getOutput(): string
    {
        assert($this->output instanceof StreamOutput);
        fseek($this->output->getStream(), 0);

        $output = (string) stream_get_contents($this->output->getStream());

        $output = Preg::replaceCallback("{(?<=^|\n|\x08)(.+?)(\x08+)}", static function ($matches): string {
            $pre = strip_tags($matches[1]);

            if (strlen($pre) === strlen($matches[2])) {
                return '';
            }

            // TODO reverse parse the string, skipping span tags and \033\[([0-9;]+)m(.*?)\033\[0m style blobs
            return rtrim($matches[1])."\n";
        }, $output);

        return $output;
    }

    /**
     * @param string[] $inputs
     *
     * @see createStream
     */
    public function setUserInputs(array $inputs): void
    {
        if (!$this->input instanceof StreamableInputInterface) {
            throw new \RuntimeException('Setting the user inputs requires at least the version 3.2 of the symfony/console component.');
        }

        $this->input->setStream($this->createStream($inputs));
        $this->input->setInteractive(true);
    }

    /**
     * @param string[] $inputs
     *
     * @return resource stream
     */
    private function createStream(array $inputs)
    {
        $stream = fopen('php://memory', 'r+');
        if ($stream === false) {
            throw new \RuntimeException('Unable to open memory output stream');
        }

        foreach ($inputs as $input) {
            fwrite($stream, $input.PHP_EOL);
        }

        rewind($stream);

        return $stream;
    }
}
