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

use Composer\Question\StrictConfirmationQuestion;
use Symfony\Component\Console\Helper\HelperSet;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\Question;

/**
 * The Input/Output helper.
 *
 * @author François Pluchino <francois.pluchino@opendisplay.com>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class ConsoleIO extends BaseIO
{
    /** @var InputInterface */
    protected $input;
    /** @var OutputInterface */
    protected $output;
    /** @var HelperSet */
    protected $helperSet;
    /** @var string */
    protected $lastMessage = '';
    /** @var string */
    protected $lastMessageErr = '';

    /** @var float */
    private $startTime;
    /** @var array<IOInterface::*, OutputInterface::VERBOSITY_*> */
    private $verbosityMap;

    /**
     * Constructor.
     *
     * @param InputInterface  $input     The input instance
     * @param OutputInterface $output    The output instance
     * @param HelperSet       $helperSet The helperSet instance
     */
    public function __construct(InputInterface $input, OutputInterface $output, HelperSet $helperSet)
    {
        $this->input = $input;
        $this->output = $output;
        $this->helperSet = $helperSet;
        $this->verbosityMap = array(
            self::QUIET => OutputInterface::VERBOSITY_QUIET,
            self::NORMAL => OutputInterface::VERBOSITY_NORMAL,
            self::VERBOSE => OutputInterface::VERBOSITY_VERBOSE,
            self::VERY_VERBOSE => OutputInterface::VERBOSITY_VERY_VERBOSE,
            self::DEBUG => OutputInterface::VERBOSITY_DEBUG,
        );
    }

    /**
     * @param float $startTime
     *
     * @return void
     */
    public function enableDebugging(float $startTime)
    {
        $this->startTime = $startTime;
    }

    /**
     * @inheritDoc
     */
    public function isInteractive()
    {
        return $this->input->isInteractive();
    }

    /**
     * @inheritDoc
     */
    public function isDecorated()
    {
        return $this->output->isDecorated();
    }

    /**
     * @inheritDoc
     */
    public function isVerbose()
    {
        return $this->output->isVerbose();
    }

    /**
     * @inheritDoc
     */
    public function isVeryVerbose()
    {
        return $this->output->isVeryVerbose();
    }

    /**
     * @inheritDoc
     */
    public function isDebug()
    {
        return $this->output->isDebug();
    }

    /**
     * @inheritDoc
     */
    public function write($messages, bool $newline = true, int $verbosity = self::NORMAL)
    {
        $this->doWrite($messages, $newline, false, $verbosity);
    }

    /**
     * @inheritDoc
     */
    public function writeError($messages, bool $newline = true, int $verbosity = self::NORMAL)
    {
        $this->doWrite($messages, $newline, true, $verbosity);
    }

    /**
     * @inheritDoc
     */
    public function writeRaw($messages, bool $newline = true, int $verbosity = self::NORMAL)
    {
        $this->doWrite($messages, $newline, false, $verbosity, true);
    }

    /**
     * @inheritDoc
     */
    public function writeErrorRaw($messages, bool $newline = true, int $verbosity = self::NORMAL)
    {
        $this->doWrite($messages, $newline, true, $verbosity, true);
    }

    /**
     * @param string[]|string $messages
     * @param bool                 $newline
     * @param bool                 $stderr
     * @param int                  $verbosity
     * @param bool                 $raw
     *
     * @return void
     */
    private function doWrite($messages, bool $newline, bool $stderr, int $verbosity, bool $raw = false): void
    {
        $sfVerbosity = $this->verbosityMap[$verbosity];
        if ($sfVerbosity > $this->output->getVerbosity()) {
            return;
        }

        if ($raw) {
            $sfVerbosity |= OutputInterface::OUTPUT_RAW;
        }

        if (null !== $this->startTime) {
            $memoryUsage = memory_get_usage() / 1024 / 1024;
            $timeSpent = microtime(true) - $this->startTime;
            $messages = array_map(function ($message) use ($memoryUsage, $timeSpent): string {
                return sprintf('[%.1fMiB/%.2fs] %s', $memoryUsage, $timeSpent, $message);
            }, (array) $messages);
        }

        if (true === $stderr && $this->output instanceof ConsoleOutputInterface) {
            $this->output->getErrorOutput()->write($messages, $newline, $sfVerbosity);
            $this->lastMessageErr = implode($newline ? "\n" : '', (array) $messages);

            return;
        }

        $this->output->write($messages, $newline, $sfVerbosity);
        $this->lastMessage = implode($newline ? "\n" : '', (array) $messages);
    }

    /**
     * @inheritDoc
     */
    public function overwrite($messages, bool $newline = true, ?int $size = null, int $verbosity = self::NORMAL)
    {
        $this->doOverwrite($messages, $newline, $size, false, $verbosity);
    }

    /**
     * @inheritDoc
     */
    public function overwriteError($messages, bool $newline = true, ?int $size = null, int $verbosity = self::NORMAL)
    {
        $this->doOverwrite($messages, $newline, $size, true, $verbosity);
    }

    /**
     * @param string[]|string $messages
     * @param bool         $newline
     * @param int|null     $size
     * @param bool         $stderr
     * @param int          $verbosity
     *
     * @return void
     */
    private function doOverwrite($messages, bool $newline, ?int $size, bool $stderr, int $verbosity): void
    {
        // messages can be an array, let's convert it to string anyway
        $messages = implode($newline ? "\n" : '', (array) $messages);

        // since overwrite is supposed to overwrite last message...
        if (!isset($size)) {
            // removing possible formatting of lastMessage with strip_tags
            $size = strlen(strip_tags($stderr ? $this->lastMessageErr : $this->lastMessage));
        }
        // ...let's fill its length with backspaces
        $this->doWrite(str_repeat("\x08", $size), false, $stderr, $verbosity);

        // write the new message
        $this->doWrite($messages, false, $stderr, $verbosity);

        // In cmd.exe on Win8.1 (possibly 10?), the line can not be cleared, so we need to
        // track the length of previous output and fill it with spaces to make sure the line is cleared.
        // See https://github.com/composer/composer/pull/5836 for more details
        $fill = $size - strlen(strip_tags($messages));
        if ($fill > 0) {
            // whitespace whatever has left
            $this->doWrite(str_repeat(' ', $fill), false, $stderr, $verbosity);
            // move the cursor back
            $this->doWrite(str_repeat("\x08", $fill), false, $stderr, $verbosity);
        }

        if ($newline) {
            $this->doWrite('', true, $stderr, $verbosity);
        }

        if ($stderr) {
            $this->lastMessageErr = $messages;
        } else {
            $this->lastMessage = $messages;
        }
    }

    /**
     * @param  int         $max
     * @return ProgressBar
     */
    public function getProgressBar(int $max = 0)
    {
        return new ProgressBar($this->getErrorOutput(), $max);
    }

    /**
     * @inheritDoc
     */
    public function ask($question, $default = null)
    {
        /** @var \Symfony\Component\Console\Helper\QuestionHelper $helper */
        $helper = $this->helperSet->get('question');
        $question = new Question($question, $default);

        return $helper->ask($this->input, $this->getErrorOutput(), $question);
    }

    /**
     * @inheritDoc
     */
    public function askConfirmation($question, $default = true)
    {
        /** @var \Symfony\Component\Console\Helper\QuestionHelper $helper */
        $helper = $this->helperSet->get('question');
        $question = new StrictConfirmationQuestion($question, $default);

        return $helper->ask($this->input, $this->getErrorOutput(), $question);
    }

    /**
     * @inheritDoc
     */
    public function askAndValidate($question, $validator, $attempts = null, $default = null)
    {
        /** @var \Symfony\Component\Console\Helper\QuestionHelper $helper */
        $helper = $this->helperSet->get('question');
        $question = new Question($question, $default);
        $question->setValidator($validator);
        $question->setMaxAttempts($attempts);

        return $helper->ask($this->input, $this->getErrorOutput(), $question);
    }

    /**
     * @inheritDoc
     */
    public function askAndHideAnswer($question)
    {
        /** @var \Symfony\Component\Console\Helper\QuestionHelper $helper */
        $helper = $this->helperSet->get('question');
        $question = new Question($question);
        $question->setHidden(true);

        return $helper->ask($this->input, $this->getErrorOutput(), $question);
    }

    /**
     * @inheritDoc
     */
    public function select($question, $choices, $default, $attempts = false, $errorMessage = 'Value "%s" is invalid', $multiselect = false)
    {
        /** @var \Symfony\Component\Console\Helper\QuestionHelper $helper */
        $helper = $this->helperSet->get('question');
        $question = new ChoiceQuestion($question, $choices, $default);
        $question->setMaxAttempts($attempts ?: null); // IOInterface requires false, and Question requires null or int
        $question->setErrorMessage($errorMessage);
        $question->setMultiselect($multiselect);

        $result = $helper->ask($this->input, $this->getErrorOutput(), $question);

        if (!is_array($result)) {
            return (string) array_search($result, $choices, true);
        }

        $results = array();
        foreach ($choices as $index => $choice) {
            if (in_array($choice, $result, true)) {
                $results[] = (string) $index;
            }
        }

        return $results;
    }

    /**
     * @return OutputInterface
     */
    private function getErrorOutput(): OutputInterface
    {
        if ($this->output instanceof ConsoleOutputInterface) {
            return $this->output->getErrorOutput();
        }

        return $this->output;
    }
}
