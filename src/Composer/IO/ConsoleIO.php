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

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\HelperSet;
use Symfony\Component\Console\Question\ConfirmationQuestion;
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
    protected $lastMessage;
    /** @var string */
    protected $lastMessageErr;

    /** @var float */
    private $startTime;
    /** @var array<int, int> */
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
     */
    public function enableDebugging($startTime)
    {
        $this->startTime = $startTime;
    }

    /**
     * {@inheritDoc}
     */
    public function isInteractive()
    {
        return $this->input->isInteractive();
    }

    /**
     * {@inheritDoc}
     */
    public function isDecorated()
    {
        return $this->output->isDecorated();
    }

    /**
     * {@inheritDoc}
     */
    public function isVerbose()
    {
        return $this->output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE;
    }

    /**
     * {@inheritDoc}
     */
    public function isVeryVerbose()
    {
        return $this->output->getVerbosity() >= OutputInterface::VERBOSITY_VERY_VERBOSE;
    }

    /**
     * {@inheritDoc}
     */
    public function isDebug()
    {
        return $this->output->getVerbosity() >= OutputInterface::VERBOSITY_DEBUG;
    }

    /**
     * {@inheritDoc}
     */
    public function write($messages, $newline = true, $verbosity = self::NORMAL)
    {
        $this->doWrite($messages, $newline, false, $verbosity);
    }

    /**
     * {@inheritDoc}
     */
    public function writeError($messages, $newline = true, $verbosity = self::NORMAL)
    {
        $this->doWrite($messages, $newline, true, $verbosity);
    }

    /**
     * @param array|string $messages
     * @param bool         $newline
     * @param bool         $stderr
     * @param int          $verbosity
     */
    private function doWrite($messages, $newline, $stderr, $verbosity)
    {
        $sfVerbosity = $this->verbosityMap[$verbosity];
        if ($sfVerbosity > $this->output->getVerbosity()) {
            return;
        }

        // hack to keep our usage BC with symfony<2.8 versions
        // this removes the quiet output but there is no way around it
        // see https://github.com/composer/composer/pull/4913
        if (OutputInterface::VERBOSITY_QUIET === 0) {
            $sfVerbosity = OutputInterface::OUTPUT_NORMAL;
        }

        if (null !== $this->startTime) {
            $memoryUsage = memory_get_usage() / 1024 / 1024;
            $timeSpent = microtime(true) - $this->startTime;
            $messages = array_map(function ($message) use ($memoryUsage, $timeSpent) {
                return sprintf('[%.1fMB/%.2fs] %s', $memoryUsage, $timeSpent, $message);
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
     * {@inheritDoc}
     */
    public function overwrite($messages, $newline = true, $size = null, $verbosity = self::NORMAL)
    {
        $this->doOverwrite($messages, $newline, $size, false, $verbosity);
    }

    /**
     * {@inheritDoc}
     */
    public function overwriteError($messages, $newline = true, $size = null, $verbosity = self::NORMAL)
    {
        $this->doOverwrite($messages, $newline, $size, true, $verbosity);
    }

    /**
     * @param array|string $messages
     * @param bool         $newline
     * @param int|null     $size
     * @param bool         $stderr
     * @param int          $verbosity
     */
    private function doOverwrite($messages, $newline, $size, $stderr, $verbosity)
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
     * {@inheritDoc}
     */
    public function ask($question, $default = null)
    {
        /** @var \Symfony\Component\Console\Helper\QuestionHelper $helper */
        $helper = $this->helperSet->get('question');
        $question = new Question($question, $default);

        return $helper->ask($this->input, $this->getErrorOutput(), $question);
    }

    /**
     * {@inheritDoc}
     */
    public function askConfirmation($question, $default = true)
    {
        /** @var \Symfony\Component\Console\Helper\QuestionHelper $helper */
        $helper = $this->helperSet->get('question');
        $question = new ConfirmationQuestion($question, $default);

        return $helper->ask($this->input, $this->getErrorOutput(), $question);
    }

    /**
     * {@inheritDoc}
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
     * {@inheritDoc}
     */
    public function askAndHideAnswer($question)
    {
        $this->writeError($question, false);

        return \Seld\CliPrompt\CliPrompt::hiddenPrompt(true);
    }

    /**
     * {@inheritDoc}
     */
    public function select($question, $choices, $default, $attempts = false, $errorMessage = 'Value "%s" is invalid', $multiselect = false)
    {
        if ($this->isInteractive()) {
            return $this->helperSet->get('dialog')->select($this->getErrorOutput(), $question, $choices, $default, $attempts, $errorMessage, $multiselect);
        }

        return $default;
    }

    /**
     * @return OutputInterface
     */
    private function getErrorOutput()
    {
        if ($this->output instanceof ConsoleOutputInterface) {
            return $this->output->getErrorOutput();
        }

        return $this->output;
    }
}
