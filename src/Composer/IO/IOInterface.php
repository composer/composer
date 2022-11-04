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

use Composer\Config;
use Psr\Log\LoggerInterface;

/**
 * The Input/Output helper interface.
 *
 * @author François Pluchino <francois.pluchino@opendisplay.com>
 */
interface IOInterface extends LoggerInterface
{
    public const QUIET = 1;
    public const NORMAL = 2;
    public const VERBOSE = 4;
    public const VERY_VERBOSE = 8;
    public const DEBUG = 16;

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
     * @param string|string[] $messages  The message as an array of lines or a single string
     * @param bool            $newline   Whether to add a newline or not
     * @param int             $verbosity Verbosity level from the VERBOSITY_* constants
     *
     * @return void
     */
    public function write($messages, bool $newline = true, int $verbosity = self::NORMAL);

    /**
     * Writes a message to the error output.
     *
     * @param string|string[] $messages  The message as an array of lines or a single string
     * @param bool            $newline   Whether to add a newline or not
     * @param int             $verbosity Verbosity level from the VERBOSITY_* constants
     *
     * @return void
     */
    public function writeError($messages, bool $newline = true, int $verbosity = self::NORMAL);

    /**
     * Writes a message to the output, without formatting it.
     *
     * @param string|string[] $messages  The message as an array of lines or a single string
     * @param bool            $newline   Whether to add a newline or not
     * @param int             $verbosity Verbosity level from the VERBOSITY_* constants
     *
     * @return void
     */
    public function writeRaw($messages, bool $newline = true, int $verbosity = self::NORMAL);

    /**
     * Writes a message to the error output, without formatting it.
     *
     * @param string|string[] $messages  The message as an array of lines or a single string
     * @param bool            $newline   Whether to add a newline or not
     * @param int             $verbosity Verbosity level from the VERBOSITY_* constants
     *
     * @return void
     */
    public function writeErrorRaw($messages, bool $newline = true, int $verbosity = self::NORMAL);

    /**
     * Overwrites a previous message to the output.
     *
     * @param string|string[] $messages  The message as an array of lines or a single string
     * @param bool            $newline   Whether to add a newline or not
     * @param int             $size      The size of line
     * @param int             $verbosity Verbosity level from the VERBOSITY_* constants
     *
     * @return void
     */
    public function overwrite($messages, bool $newline = true, ?int $size = null, int $verbosity = self::NORMAL);

    /**
     * Overwrites a previous message to the error output.
     *
     * @param string|string[] $messages  The message as an array of lines or a single string
     * @param bool            $newline   Whether to add a newline or not
     * @param int             $size      The size of line
     * @param int             $verbosity Verbosity level from the VERBOSITY_* constants
     *
     * @return void
     */
    public function overwriteError($messages, bool $newline = true, ?int $size = null, int $verbosity = self::NORMAL);

    /**
     * Asks a question to the user.
     *
     * @param string $question The question to ask
     * @param string|bool|int|float|null $default  The default answer if none is given by the user
     *
     * @throws \RuntimeException If there is no data to read in the input stream
     * @return mixed       The user answer
     */
    public function ask(string $question, $default = null);

    /**
     * Asks a confirmation to the user.
     *
     * The question will be asked until the user answers by nothing, yes, or no.
     *
     * @param string $question The question to ask
     * @param bool   $default  The default answer if the user enters nothing
     *
     * @return bool true if the user has confirmed, false otherwise
     */
    public function askConfirmation(string $question, bool $default = true);

    /**
     * Asks for a value and validates the response.
     *
     * The validator receives the data to validate. It must return the
     * validated data when the data is valid and throw an exception
     * otherwise.
     *
     * @param string   $question  The question to ask
     * @param callable $validator A PHP callback
     * @param null|int $attempts  Max number of times to ask before giving up (default of null means infinite)
     * @param mixed    $default   The default answer if none is given by the user
     *
     * @throws \Exception When any of the validators return an error
     * @return mixed
     */
    public function askAndValidate(string $question, callable $validator, ?int $attempts = null, $default = null);

    /**
     * Asks a question to the user and hide the answer.
     *
     * @param string $question The question to ask
     *
     * @return string|null The answer
     */
    public function askAndHideAnswer(string $question);

    /**
     * Asks the user to select a value.
     *
     * @param string      $question     The question to ask
     * @param string[]    $choices      List of choices to pick from
     * @param bool|string $default      The default answer if the user enters nothing
     * @param bool|int    $attempts     Max number of times to ask before giving up (false by default, which means infinite)
     * @param string      $errorMessage Message which will be shown if invalid value from choice list would be picked
     * @param bool        $multiselect  Select more than one value separated by comma
     *
     * @throws \InvalidArgumentException
     * @return int|string|list<string>|bool     The selected value or values (the key of the choices array)
     */
    public function select(string $question, array $choices, $default, $attempts = false, string $errorMessage = 'Value "%s" is invalid', bool $multiselect = false);

    /**
     * Get all authentication information entered.
     *
     * @return array<string, array{username: string|null, password: string|null}> The map of authentication data
     */
    public function getAuthentications();

    /**
     * Verify if the repository has a authentication information.
     *
     * @param string $repositoryName The unique name of repository
     *
     * @return bool
     */
    public function hasAuthentication(string $repositoryName);

    /**
     * Get the username and password of repository.
     *
     * @param string $repositoryName The unique name of repository
     *
     * @return array{username: string|null, password: string|null}
     */
    public function getAuthentication(string $repositoryName);

    /**
     * Set the authentication information for the repository.
     *
     * @param string      $repositoryName The unique name of repository
     * @param string      $username       The username
     * @param null|string $password       The password
     *
     * @return void
     */
    public function setAuthentication(string $repositoryName, string $username, ?string $password = null);

    /**
     * Loads authentications from a config instance
     *
     * @return void
     */
    public function loadConfiguration(Config $config);
}
