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

use Composer\Config;

/**
 * The Input/Output helper interface.
 *
 * @author François Pluchino <francois.pluchino@opendisplay.com>
 */
interface IOInterface
{
    const QUIET = 1;
    const NORMAL = 2;
    const VERBOSE = 4;
    const VERY_VERBOSE = 8;
    const DEBUG = 16;

    /**
     * Is this input means interactive?
     *
     * @return bool
     */
    public function isInteractive();

    /**
     * Is this output verbose?
     *
     * @return bool
     */
    public function isVerbose();

    /**
     * Is the output very verbose?
     *
     * @return bool
     */
    public function isVeryVerbose();

    /**
     * Is the output in debug verbosity?
     *
     * @return bool
     */
    public function isDebug();

    /**
     * Is this output decorated?
     *
     * @return bool
     */
    public function isDecorated();

    /**
     * Writes a message to the output.
     *
     * @param string|array $messages  The message as an array of lines or a single string
     * @param bool         $newline   Whether to add a newline or not
     * @param int          $verbosity Verbosity level from the VERBOSITY_* constants
     */
    public function write($messages, $newline = true, $verbosity = self::NORMAL);

    /**
     * Writes a message to the error output.
     *
     * @param string|array $messages  The message as an array of lines or a single string
     * @param bool         $newline   Whether to add a newline or not
     * @param int          $verbosity Verbosity level from the VERBOSITY_* constants
     */
    public function writeError($messages, $newline = true, $verbosity = self::NORMAL);

    /**
     * Overwrites a previous message to the output.
     *
     * @param string|array $messages  The message as an array of lines or a single string
     * @param bool         $newline   Whether to add a newline or not
     * @param int          $size      The size of line
     * @param int          $verbosity Verbosity level from the VERBOSITY_* constants
     */
    public function overwrite($messages, $newline = true, $size = null, $verbosity = self::NORMAL);

    /**
     * Overwrites a previous message to the error output.
     *
     * @param string|array $messages  The message as an array of lines or a single string
     * @param bool         $newline   Whether to add a newline or not
     * @param int          $size      The size of line
     * @param int          $verbosity Verbosity level from the VERBOSITY_* constants
     */
    public function overwriteError($messages, $newline = true, $size = null, $verbosity = self::NORMAL);

    /**
     * Asks a question to the user.
     *
     * @param string|array $question The question to ask
     * @param string       $default  The default answer if none is given by the user
     *
     * @throws \RuntimeException If there is no data to read in the input stream
     * @return string            The user answer
     */
    public function ask($question, $default = null);

    /**
     * Asks a confirmation to the user.
     *
     * The question will be asked until the user answers by nothing, yes, or no.
     *
     * @param string|array $question The question to ask
     * @param bool         $default  The default answer if the user enters nothing
     *
     * @return bool true if the user has confirmed, false otherwise
     */
    public function askConfirmation($question, $default = true);

    /**
     * Asks for a value and validates the response.
     *
     * The validator receives the data to validate. It must return the
     * validated data when the data is valid and throw an exception
     * otherwise.
     *
     * @param string|array $question  The question to ask
     * @param callback     $validator A PHP callback
     * @param null|int     $attempts  Max number of times to ask before giving up (default of null means infinite)
     * @param mixed        $default   The default answer if none is given by the user
     *
     * @throws \Exception When any of the validators return an error
     * @return mixed
     */
    public function askAndValidate($question, $validator, $attempts = null, $default = null);

    /**
     * Asks a question to the user and hide the answer.
     *
     * @param string $question The question to ask
     *
     * @return string The answer
     */
    public function askAndHideAnswer($question);

    /**
     * Get all authentication information entered.
     *
     * @return array The map of authentication data
     */
    public function getAuthentications();

    /**
     * Verify if the repository has a authentication information.
     *
     * @param string $repositoryName The unique name of repository
     *
     * @return bool
     */
    public function hasAuthentication($repositoryName);

    /**
     * Get the username and password of repository.
     *
     * @param string $repositoryName The unique name of repository
     *
     * @return array The 'username' and 'password'
     */
    public function getAuthentication($repositoryName);

    /**
     * Set the authentication information for the repository.
     *
     * @param string $repositoryName The unique name of repository
     * @param string $username       The username
     * @param string $password       The password
     */
    public function setAuthentication($repositoryName, $username, $password = null);

    /**
     * Loads authentications from a config instance
     *
     * @param Config $config
     */
    public function loadConfiguration(Config $config);
}
