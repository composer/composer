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
    public function write($messages, $newline = true)
    {
        $this->output->write($messages, $newline);
    }

    /**
     * {@inheritDoc}
     */
    public function overwrite($messages, $size = 80, $newline = false)
    {
        for ($place = $size; $place > 0; $place--) {
            $this->write("\x08", false);
        }

        $this->write($messages, false);

        for ($place = ($size - strlen($messages)); $place > 0; $place--) {
            $this->write(' ', false);
        }

        // clean up the end line
        for ($place = ($size - strlen($messages)); $place > 0; $place--) {
            $this->write("\x08", false);
        }

        if ($newline) {
            $this->write('');
        }
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
        if (preg_match('/^win/i', PHP_OS)) {
            $vbscript = sys_get_temp_dir() . '/prompt_password.vbs';
            file_put_contents($vbscript,
                    'wscript.echo(Inputbox("' . addslashes($question) . '","'
                            . addslashes($question) . '", ""))');
            $command = "cscript //nologo " . escapeshellarg($vbscript);

            $this->write($question);

            $value = rtrim(shell_exec($command));
            unlink($vbscript);

            for ($i = 0; $i < strlen($value); ++$i) {
                $this->write('*');
            }

            $this->writeln('');

            return $value;
        }

        // for other OS with shell_exec (hide the answer)
        if (rtrim(shell_exec($command)) === 'OK') {
            $command = "/usr/bin/env bash -c 'echo OK'";

            $this->write($question);

            $command = "/usr/bin/env bash -c 'read -s mypassword && echo \$mypassword'";
            $value = rtrim(shell_exec($command));

            for ($i = 0; $i < strlen($value); ++$i) {
                $this->write('*');
            }

            $this->writeln('');

            return $value;
        }

        // for other OS without shell_exec (does not hide the answer)
        $this->writeln('');

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
