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

/**
 * The Input/Output helper interface.
 *
 * @author François Pluchino <francois.pluchino@opendisplay.com>
 */
interface IOInterface
{
    /**
     * Is this input means interactive?
     *
     * @return Boolean
     */
    function isInteractive();

    /**
     * Is this input verbose?
     *
     * @return Boolean
     */
    function isVerbose();

    /**
     * Is this output decorated?
     *
     * @return Boolean
     */
    function isDecorated();

    /**
     * Writes a message to the output.
     *
     * @param string|array $messages The message as an array of lines or a single string
     * @param Boolean      $newline  Whether to add a newline or not
     */
    function write($messages, $newline = true);

    /**
     * Overwrites a previous message to the output.
     *
     * @param string|array $messages The message as an array of lines or a single string
     * @param Boolean      $newline  Whether to add a newline or not
     * @param integer      $size     The size of line
     */
    function overwrite($messages, $newline = true, $size = 80);

    /**
     * Asks a question to the user.
     *
     * @param string|array    $question The question to ask
     * @param string          $default  The default answer if none is given by the user
     *
     * @return string The user answer
     *
     * @throws \RuntimeException If there is no data to read in the input stream
     */
    function ask($question, $default = null);

    /**
     * Asks a confirmation to the user.
     *
     * The question will be asked until the user answers by nothing, yes, or no.
     *
     * @param string|array    $question The question to ask
     * @param Boolean         $default  The default answer if the user enters nothing
     *
     * @return Boolean true if the user has confirmed, false otherwise
     */
    function askConfirmation($question, $default = true);

    /**
     * Asks for a value and validates the response.
     *
     * The validator receives the data to validate. It must return the
     * validated data when the data is valid and throw an exception
     * otherwise.
     *
     * @param string|array    $question  The question to ask
     * @param callback        $validator A PHP callback
     * @param integer         $attempts  Max number of times to ask before giving up (false by default, which means infinite)
     * @param string          $default  The default answer if none is given by the user
     *
     * @return mixed
     *
     * @throws \Exception When any of the validators return an error
     */
    function askAndValidate($question, $validator, $attempts = false, $default = null);

    /**
     * Asks a question to the user and hide the answer.
     *
     * @param string $question The question to ask
     *
     * @return string The answer
     */
    function askAndHideAnswer($question);

    /**
     * Get the last username entered.
     *
     * @return string The username
     */
    function getLastUsername();

    /**
     * Get the last password entered.
     *
     * @return string The password
     */
    function getLastPassword();

    /**
     * Get all authorization informations entered.
     *
     * @return array The map of authorization
     */
    function getAuthorizations();

    /**
     * Verify if the repository has a authorization informations.
     *
     * @param string $repositoryName The unique name of repository
     *
     * @return boolean
     */
    function hasAuthorization($repositoryName);

    /**
     * Get the username and password of repository.
     *
     * @param string $repositoryName The unique name of repository
     *
     * @return array The 'username' and 'password'
     */
    function getAuthorization($repositoryName);

    /**
     * Set the authorization informations for the repository.
     *
     * @param string $repositoryName The unique name of repository
     * @param string $username       The username
     * @param string $password       The password
     */
    function setAuthorization($repositoryName, $username, $password = null);
}
