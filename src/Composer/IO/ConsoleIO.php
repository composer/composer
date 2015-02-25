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
use Symfony\Component\Process\ExecutableFinder;

/**
 * The Input/Output helper.
 *
 * @author François Pluchino <francois.pluchino@opendisplay.com>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class ConsoleIO extends BaseIO
{
    protected $input;
    protected $output;
    protected $helperSet;
    protected $lastMessage;
    protected $lastMessageErr;
    private $startTime;

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
    }

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
        return $this->output->getVerbosity() >= 3; // OutputInterface::VERSOBITY_VERY_VERBOSE
    }

    /**
     * {@inheritDoc}
     */
    public function isDebug()
    {
        return $this->output->getVerbosity() >= 4; // OutputInterface::VERBOSITY_DEBUG
    }

    /**
     * {@inheritDoc}
     */
    public function write($messages, $newline = true)
    {
        $this->doWrite($messages, $newline, false);
    }

    /**
     * {@inheritDoc}
     */
    public function writeError($messages, $newline = true)
    {
        $this->doWrite($messages, $newline, true);
    }

    /**
     * @param array $messages
     * @param boolean $newline
     * @param boolean $stderr
     */
    private function doWrite($messages, $newline, $stderr)
    {
        if (null !== $this->startTime) {
            $memoryUsage = memory_get_usage() / 1024 / 1024;
            $timeSpent = microtime(true) - $this->startTime;
            $messages = array_map(function ($message) use ($memoryUsage, $timeSpent) {
                return sprintf('[%.1fMB/%.2fs] %s', $memoryUsage, $timeSpent, $message);
            }, (array) $messages);
        }

        if (true === $stderr && $this->output instanceof ConsoleOutputInterface) {
            $this->output->getErrorOutput()->write($messages, $newline);
            $this->lastMessageErr = join($newline ? "\n" : '', (array) $messages);
            return;
        }

        $this->output->write($messages, $newline);
        $this->lastMessage = join($newline ? "\n" : '', (array) $messages);
    }

    /**
     * {@inheritDoc}
     */
    public function overwrite($messages, $newline = true, $size = null)
    {
        $this->doOverwrite($messages, $newline, $size, false);
    }

    /**
     * {@inheritDoc}
     */
    public function overwriteError($messages, $newline = true, $size = null)
    {
        $this->doOverwrite($messages, $newline, $size, true);
    }

    /**
     * @param array $messages
     * @param boolean $newline
     * @param integer $size
     * @param boolean $stderr
     */
    private function doOverwrite($messages, $newline, $size, $stderr)
    {
        if (true === $stderr && $this->output instanceof ConsoleOutputInterface) {
            $output = $this->output->getErrorOutput();
        } else {
            $output = $this->output;
        }

        if (!$output->isDecorated()) {
            if (!$messages) {
                return;
            }

            $this->doWrite($messages, count($messages) === 1 || $newline, $stderr);
            return;
        }

        // messages can be an array, let's convert it to string anyway
        $messages = join($newline ? "\n" : '', (array) $messages);

        // since overwrite is supposed to overwrite last message...
        if (!isset($size)) {
            // removing possible formatting of lastMessage with strip_tags
            $size = strlen(strip_tags($stderr ? $this->lastMessageErr : $this->lastMessage));
        }
        // ...let's fill its length with backspaces
        $this->doWrite(str_repeat("\x08", $size), false, $stderr);

        // write the new message
        $this->doWrite($messages, false, $stderr);

        $fill = $size - strlen(strip_tags($messages));
        if ($fill > 0) {
            // whitespace whatever has left
            $this->doWrite(str_repeat(' ', $fill), false, $stderr);
            // move the cursor back
            $this->doWrite(str_repeat("\x08", $fill), false, $stderr);
        }

        if ($newline) {
            $this->doWrite('', true, $stderr);
        }
        $this->lastMessage = $messages;
    }

    /**
     * {@inheritDoc}
     */
    public function ask($question, $default = null)
    {
        $output = $this->output;

        if ($output instanceof ConsoleOutputInterface) {
            $output = $output->getErrorOutput();
        }

        /** @var \Symfony\Component\Console\Helper\DialogHelper $dialog */
        $dialog = $this->helperSet->get('dialog');

        return $dialog->ask($output, $question, $default);
    }

    /**
     * {@inheritDoc}
     */
    public function askConfirmation($question, $default = true)
    {
        $output = $this->output;

        if ($output instanceof ConsoleOutputInterface) {
            $output = $output->getErrorOutput();
        }

        /** @var \Symfony\Component\Console\Helper\DialogHelper $dialog */
        $dialog = $this->helperSet->get('dialog');

        return $dialog->askConfirmation($output, $question, $default);
    }

    /**
     * {@inheritDoc}
     */
    public function askAndValidate($question, $validator, $attempts = false, $default = null)
    {
        $output = $this->output;

        if ($output instanceof ConsoleOutputInterface) {
            $output = $output->getErrorOutput();
        }

        /** @var \Symfony\Component\Console\Helper\DialogHelper $dialog */
        $dialog = $this->helperSet->get('dialog');

        return $dialog->askAndValidate($output, $question, $validator, $attempts, $default);
    }

    /**
     * {@inheritDoc}
     */
    public function askAndHideAnswer($question)
    {
        // handle windows
        if (defined('PHP_WINDOWS_VERSION_BUILD')) {
            $finder = new ExecutableFinder();

            // use bash if it's present
            if ($finder->find('bash') && $finder->find('stty')) {
                $this->writeError($question, false);
                $value = rtrim(shell_exec('bash -c "stty -echo; read -n0 discard; read -r mypassword; stty echo; echo $mypassword"'));
                $this->writeError('');

                return $value;
            }

            // fallback to hiddeninput executable
            $exe = __DIR__.'\\hiddeninput.exe';

            // handle code running from a phar
            if ('phar:' === substr(__FILE__, 0, 5)) {
                $tmpExe = sys_get_temp_dir().'/hiddeninput.exe';

                // use stream_copy_to_stream instead of copy
                // to work around https://bugs.php.net/bug.php?id=64634
                $source = fopen(__DIR__.'\\hiddeninput.exe', 'r');
                $target = fopen($tmpExe, 'w+');
                stream_copy_to_stream($source, $target);
                fclose($source);
                fclose($target);
                unset($source, $target);

                $exe = $tmpExe;
            }

            $this->writeError($question, false);
            $value = rtrim(shell_exec($exe));
            $this->writeError('');

            // clean up
            if (isset($tmpExe)) {
                unlink($tmpExe);
            }

            return $value;
        }

        if (file_exists('/usr/bin/env')) {
            // handle other OSs with bash/zsh/ksh/csh if available to hide the answer
            $test = "/usr/bin/env %s -c 'echo OK' 2> /dev/null";
            foreach (array('bash', 'zsh', 'ksh', 'csh') as $sh) {
                if ('OK' === rtrim(shell_exec(sprintf($test, $sh)))) {
                    $shell = $sh;
                    break;
                }
            }
            if (isset($shell)) {
                $this->writeError($question, false);
                $readCmd = ($shell === 'csh') ? 'set mypassword = $<' : 'read -r mypassword';
                $command = sprintf("/usr/bin/env %s -c 'stty -echo; %s; stty echo; echo \$mypassword'", $shell, $readCmd);
                $value = rtrim(shell_exec($command));
                $this->writeError('');

                return $value;
            }
        }

        // not able to hide the answer, proceed with normal question handling
        return $this->ask($question);
    }
}
