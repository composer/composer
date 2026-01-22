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
use Composer\Question\StrictConfirmationQuestion;
use Symfony\Component\Console\Helper\HelperSet;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Helper\Table;
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
        $this->verbosityMap = [
            self::QUIET => OutputInterface::VERBOSITY_QUIET,
            self::NORMAL => OutputInterface::VERBOSITY_NORMAL,
            self::VERBOSE => OutputInterface::VERBOSITY_VERBOSE,
            self::VERY_VERBOSE => OutputInterface::VERBOSITY_VERY_VERBOSE,
            self::DEBUG => OutputInterface::VERBOSITY_DEBUG,
        ];
    }

    /**
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
        $messages = self::sanitize($messages);

        $this->doWrite($messages, $newline, false, $verbosity);
    }

    /**
     * @inheritDoc
     */
    public function writeError($messages, bool $newline = true, int $verbosity = self::NORMAL)
    {
        $messages = self::sanitize($messages);

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
            $messages = array_map(static function ($message) use ($memoryUsage, $timeSpent): string {
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
        $question = new Question(self::sanitize($question), is_string($default) ? self::sanitize($default) : $default);

        return $helper->ask($this->input, $this->getErrorOutput(), $question);
    }

    /**
     * @inheritDoc
     */
    public function askConfirmation($question, $default = true)
    {
        /** @var \Symfony\Component\Console\Helper\QuestionHelper $helper */
        $helper = $this->helperSet->get('question');
        $question = new StrictConfirmationQuestion(self::sanitize($question), is_string($default) ? self::sanitize($default) : $default);

        return $helper->ask($this->input, $this->getErrorOutput(), $question);
    }

    /**
     * @inheritDoc
     */
    public function askAndValidate($question, $validator, $attempts = null, $default = null)
    {
        /** @var \Symfony\Component\Console\Helper\QuestionHelper $helper */
        $helper = $this->helperSet->get('question');
        $question = new Question(self::sanitize($question), is_string($default) ? self::sanitize($default) : $default);
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
        $question = new Question(self::sanitize($question));
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
        $question = new ChoiceQuestion(self::sanitize($question), self::sanitize($choices), is_string($default) ? self::sanitize($default) : $default);
        $question->setMaxAttempts($attempts ?: null); // IOInterface requires false, and Question requires null or int
        $question->setErrorMessage($errorMessage);
        $question->setMultiselect($multiselect);

        $result = $helper->ask($this->input, $this->getErrorOutput(), $question);

        $isAssoc = (bool) \count(array_filter(array_keys($choices), 'is_string'));
        if ($isAssoc) {
            return $result;
        }

        if (!is_array($result)) {
            return (string) array_search($result, $choices, true);
        }

        $results = [];
        foreach ($choices as $index => $choice) {
            if (in_array($choice, $result, true)) {
                $results[] = (string) $index;
            }
        }

        return $results;
    }

    public function getTable(): Table
    {
        return new Table($this->output);
    }

    private function getErrorOutput(): OutputInterface
    {
        if ($this->output instanceof ConsoleOutputInterface) {
            return $this->output->getErrorOutput();
        }

        return $this->output;
    }

    /**
     * Sanitize string to remove control characters
     *
     * If $allowNewlines is true, \x0A (\n) and \x0D\x0A (\r\n) are let through. Single \r are still sanitized away to prevent overwriting whole lines.
     *
     * All other control chars (except NULL bytes) as well as ANSI escape sequences are removed.
     *
     * Invalid unicode sequences are turned into question marks.
     *
     * @param string|iterable<string> $messages
     * @return string|array<string>
     * @phpstan-return ($messages is string ? string : array<string>)
     */
    public static function sanitize($messages, bool $allowNewlines = true)
    {
        // Match ANSI escape sequences:
        // - CSI (Control Sequence Introducer): ESC [ params intermediate final
        // - OSC (Operating System Command): ESC ] ... ESC \ or BEL
        // - Other ESC sequences: ESC followed by any character
        $escapePattern = '\x1B\[[\x30-\x3F]*[\x20-\x2F]*[\x40-\x7E]|\x1B\].*?(?:\x1B\\\\|\x07)|\x1B.';
        $pattern = $allowNewlines ? "{{$escapePattern}|[\x01-\x09\x0B\x0C\x0E-\x1A]|\r(?!\n)}u" : "{{$escapePattern}|[\x01-\x1A]}u";
        if (is_string($messages)) {
            $messages = self::ensureValidUtf8($messages);
            return Preg::replace($pattern, '', $messages);
        }

        $sanitized = [];
        foreach ($messages as $key => $message) {
            $message = self::ensureValidUtf8($message);
            $sanitized[$key] = Preg::replace($pattern, '', $message);
        }

        return $sanitized;
    }

    /**
     * Ensures a string is valid UTF-8, replacing invalid byte sequences with '?'
     */
    private static function ensureValidUtf8(string $string): string
    {
        // Quick check: if string is already valid UTF-8, return as-is
        if (function_exists('mb_check_encoding') && mb_check_encoding($string, 'UTF-8')) {
            return $string;
        }

        // Use mb_convert_encoding to replace invalid sequences with '?'
        // This makes it visible when data quality issues occur
        if (function_exists('mb_convert_encoding')) {
            return (string) mb_convert_encoding($string, 'UTF-8', 'UTF-8');
        }

        // Fallback to iconv if mbstring unavailable
        if (function_exists('iconv')) {
            $cleaned = @iconv('UTF-8', 'UTF-8//TRANSLIT', $string);
            if ($cleaned !== false) {
                return $cleaned;
            }
        }

        // Last resort: return as-is (should never happen - Composer requires mbstring OR iconv)
        return $string;
    }
}
