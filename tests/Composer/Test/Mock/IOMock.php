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

namespace Composer\Test\Mock;

use Composer\Config;
use Composer\IO\BufferIO;
use Composer\IO\IOInterface;
use Composer\Pcre\PcreException;
use Composer\Pcre\Preg;
use Composer\Util\HttpDownloader;
use Composer\Util\Http\Response;
use Composer\Downloader\TransportException;
use Composer\Util\Platform;
use LogicException;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\AssertionFailedError;
use Symfony\Component\Console\Output\OutputInterface;

class IOMock extends BufferIO
{
    /**
     * @var list<array{text: string, verbosity?: IOInterface::*}|array{ask: string, reply?: string}|array{auth: array{string, string, string|null}}>|null
     */
    private $expectations = null;
    /**
     * @var bool
     */
    private $strict = false;
    /**
     * @var list<array{string, string, string|null}>
     */
    private $authLog = [];

    /**
     * @param IOInterface::* $verbosity
     */
    public function __construct(int $verbosity)
    {
        $sfVerbosity = [
            self::QUIET => OutputInterface::VERBOSITY_QUIET,
            self::NORMAL => OutputInterface::VERBOSITY_NORMAL,
            self::VERBOSE => OutputInterface::VERBOSITY_VERBOSE,
            self::VERY_VERBOSE => OutputInterface::VERBOSITY_VERY_VERBOSE,
            self::DEBUG => OutputInterface::VERBOSITY_DEBUG,
        ][$verbosity];
        parent::__construct('', $sfVerbosity);
    }

    /**
     * @param list<array{text: string, verbosity?: IOInterface::*, regex?: true}|array{ask: string, reply: string}|array{auth: array{string, string, string|null}}> $expectations
     * @param bool                                                                                                   $strict         set to true if you want to provide *all* expected messages, and not just a subset you are interested in testing
     */
    public function expects(array $expectations, bool $strict = false): void
    {
        $this->expectations = $expectations;
        $inputs = [];
        foreach ($expectations as $expect) {
            if (isset($expect['ask'])) {
                if (!array_key_exists('reply', $expect) || !is_string($expect['reply'])) {
                    throw new \LogicException('A question\'s reply must be a string, use empty string for null replies');
                }
                $inputs[] = $expect['reply'];
            }
        }

        if (count($inputs) > 0) {
            $this->setUserInputs($inputs);
        }

        $this->strict = $strict;
    }

    public function assertComplete(): void
    {
        $output = $this->getOutput();

        if (Platform::getEnv('DEBUG_OUTPUT') === '1') {
            echo PHP_EOL.'Collected output: '.$output.PHP_EOL;
        }

        // this was not configured to expect anything, so no need to react here
        if (!is_array($this->expectations)) {
            return;
        }

        if (count($this->expectations) > 0) {
            $lines = Preg::split("{\r?\n}", $output);

            foreach ($this->expectations as $expect) {
                if (isset($expect['auth'])) {
                    while (count($this->authLog) > 0) {
                        $auth = array_shift($this->authLog);
                        if ($auth === $expect['auth']) {
                            continue 2;
                        }

                        if ($this->strict) {
                            throw new AssertionFailedError('IO authentication mismatch. Expected:'.PHP_EOL.json_encode($expect['auth']).PHP_EOL.'Got:'.PHP_EOL.json_encode($auth));
                        }
                    }

                    throw new AssertionFailedError('Expected "'.json_encode($expect['auth']).'" auth to be set but there are no setAuthentication calls left to consume.');
                }

                if (isset($expect['ask'], $expect['reply'])) {
                    $pattern = '{^'.preg_quote($expect['ask']).'$}';
                } elseif (isset($expect['regex']) && $expect['regex']) {
                    $pattern = $expect['text'];
                } else {
                    $pattern = '{^'.preg_quote($expect['text']).'$}';
                }

                while (count($lines) > 0) {
                    $line = array_shift($lines);
                    try {
                        if (Preg::isMatch($pattern, $line)) {
                            continue 2;
                        }
                    } catch (PcreException $e) {
                        throw new LogicException('Invalid regex pattern in IO expectation "'.$pattern.'": '.$e->getMessage());
                    }

                    if ($this->strict) {
                        throw new AssertionFailedError('IO output mismatch. Expected:'.PHP_EOL.($expect['text'] ?? $expect['ask']).PHP_EOL.'Got:'.PHP_EOL.$line);
                    }
                }

                throw new AssertionFailedError('Expected "'.($expect['text'] ?? $expect['ask']).'" to be output still but there is no output left to consume. Complete output:'.PHP_EOL.$output);
            }
        } elseif ($output !== '' && $this->strict) {
            throw new AssertionFailedError('There was strictly no output expected but some output occurred: '.$output);
        }

        // dummy assertion to ensure the test is not marked as having no assertions
        Assert::assertTrue(true); // @phpstan-ignore-line
    }

        /**
     * @inheritDoc
     */
    public function ask($question, $default = null)
    {
        return parent::ask(rtrim($question, "\r\n").PHP_EOL, $default);
    }

    /**
     * @inheritDoc
     */
    public function askConfirmation($question, $default = true)
    {
        return parent::askConfirmation(rtrim($question, "\r\n").PHP_EOL, $default);
    }

    /**
     * @inheritDoc
     */
    public function askAndValidate($question, $validator, $attempts = null, $default = null)
    {
        return parent::askAndValidate(rtrim($question, "\r\n").PHP_EOL, $validator, $attempts, $default);
    }

    /**
     * @inheritDoc
     */
    public function askAndHideAnswer($question)
    {
        // do not hide answer in tests because that blocks on windows with hiddeninput.exe
        return parent::ask(rtrim($question, "\r\n").PHP_EOL);
    }

    /**
     * @inheritDoc
     */
    public function select($question, $choices, $default, $attempts = false, $errorMessage = 'Value "%s" is invalid', $multiselect = false)
    {
        return parent::select(rtrim($question, "\r\n").PHP_EOL, $choices, $default, $attempts, $errorMessage, $multiselect);
    }

    public function setAuthentication($repositoryName, $username, $password = null)
    {
        $this->authentications[$repositoryName] = ['username' => $username, 'password' => $password];
        $this->authLog[] = [$repositoryName, $username, $password];

        parent::setAuthentication($repositoryName, $username, $password);
    }
}
