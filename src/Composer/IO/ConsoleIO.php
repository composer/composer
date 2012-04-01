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
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Formatter\OutputFormatterInterface;
use Symfony\Component\Console\Helper\HelperSet;

/**
 * The Input/Output helper.
 *
 * @author François Pluchino <francois.pluchino@opendisplay.com>
 */
class ConsoleIO implements IOInterface
{
    protected $input;
    protected $output;
    protected $helperSet;
    protected $authorizations = array();
    protected $lastUsername;
    protected $lastPassword;
    protected $lastMessage;

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
    public function isVerbose()
    {
        return (Boolean) $this->input->getOption('verbose');
    }

    /**
     * {@inheritDoc}
     */
    public function write($messages, $newline = true)
    {
        $this->output->write($messages, $newline);
        $this->lastMessage = join($newline ? "\n" : '', (array) $messages);
    }

    /**
     * {@inheritDoc}
     */
    public function overwrite($messages, $newline = true, $size = null)
    {
        // messages can be an array, let's convert it to string anyway
        $messages = join($newline ? "\n" : '', (array) $messages);

        // since overwrite is supposed to overwrite last message...
        if (!isset($size)) {
            // removing possible formatting of lastMessage with strip_tags
            $size = strlen(strip_tags($this->lastMessage));
        }
        // ...let's fill its length with backspaces
        $this->write(str_repeat("\x08", $size), false);

        // write the new message
        $this->write($messages, false);

        $fill = $size - strlen(strip_tags($messages));
        if ($fill > 0) {
            // whitespace whatever has left
            $this->write(str_repeat(' ', $fill), false);
            // move the cursor back
            $this->write(str_repeat("\x08", $fill), false);
        }

        if ($newline) {
            $this->write('');
        }
        $this->lastMessage = $messages;
    }

    /**
     * {@inheritDoc}
     */
    public function ask($question, $default = null)
    {
        return $this->helperSet->get('dialog')->ask($this->output, $question, $default);
    }

    /**
     * {@inheritDoc}
     */
    public function askConfirmation($question, $default = true)
    {
        return $this->helperSet->get('dialog')->askConfirmation($this->output, $question, $default);
    }

    /**
     * {@inheritDoc}
     */
    public function askAndValidate($question, $validator, $attempts = false, $default = null)
    {
        return $this->helperSet->get('dialog')->askAndValidate($this->output, $question, $validator, $attempts, $default);
    }

    /**
     * {@inheritDoc}
     */
    public function askAndHideAnswer($question)
    {
        // for windows OS (does not hide the answer in the popup, but it never appears in the STDIN history)
        if (defined('PHP_WINDOWS_VERSION_BUILD')) {
            $vbscript = sys_get_temp_dir() . '/prompt_password.vbs';
            file_put_contents($vbscript,
                    'wscript.echo(Inputbox("' . addslashes($question) . '","'
                            . addslashes($question) . '", ""))');
            $command = "cscript //nologo " . escapeshellarg($vbscript);

            $this->write($question, false);

            $value = rtrim(shell_exec($command));
            unlink($vbscript);

            $this->write('***');

            return $value;
        }

        // for other OS with shell_exec (hide the answer)
        $command = "/usr/bin/env bash -c 'echo OK'";
        if (rtrim(shell_exec($command)) === 'OK') {
            $this->write($question, false);

            $command = "/usr/bin/env bash -c 'read -s mypassword && echo \$mypassword'";
            $value = rtrim(shell_exec($command));

            for ($i = 0; $i < strlen($value); ++$i) {
                $this->write('*', false);
            }

            $this->write('');

            return $value;
        }

        // for other OS without shell_exec (does not hide the answer)
        $this->write('');

        return $this->ask($question);
    }

    /**
     * {@inheritDoc}
     */
    public function getLastUsername()
    {
        return $this->lastUsername;
    }

    /**
     * {@inheritDoc}
     */
    public function getLastPassword()
    {
        return $this->lastPassword;
    }

    /**
     * {@inheritDoc}
     */
    public function getAuthorizations()
    {
        return $this->authorizations;
    }

    /**
     * {@inheritDoc}
     */
    public function hasAuthorization($repositoryName)
    {
        $auths = $this->getAuthorizations();
        return isset($auths[$repositoryName]);
    }

    /**
     * {@inheritDoc}
     */
    public function getAuthorization($repositoryName)
    {
        $auths = $this->getAuthorizations();
        return isset($auths[$repositoryName]) ? $auths[$repositoryName] : array('username' => null, 'password' => null);
    }

    /**
     * {@inheritDoc}
     */
    public function setAuthorization($repositoryName, $username, $password = null)
    {
        $auths = $this->getAuthorizations();
        $auths[$repositoryName] = array('username' => $username, 'password' => $password);

        $this->authorizations = $auths;
        $this->lastUsername = $username;
        $this->lastPassword = $password;
    }
}
