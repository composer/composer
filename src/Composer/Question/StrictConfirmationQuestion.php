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

namespace Composer\Question;

use Composer\Pcre\Preg;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Question\Question;

/**
 * Represents a yes/no question
 * Enforces strict responses rather than non-standard answers counting as default
 * Based on Symfony\Component\Console\Question\ConfirmationQuestion
 *
 * @author Theo Tonge <theo@theotonge.co.uk>
 */
class StrictConfirmationQuestion extends Question
{
    /** @var string */
    private $trueAnswerRegex;
    /** @var string */
    private $falseAnswerRegex;

    /**
     * Constructor.s
     *
     * @param string $question         The question to ask to the user
     * @param bool   $default          The default answer to return, true or false
     * @param string $trueAnswerRegex  A regex to match the "yes" answer
     * @param string $falseAnswerRegex A regex to match the "no" answer
     */
    public function __construct($question, $default = true, $trueAnswerRegex = '/^y(?:es)?$/i', $falseAnswerRegex = '/^no?$/i')
    {
        parent::__construct($question, (bool) $default);

        $this->trueAnswerRegex = $trueAnswerRegex;
        $this->falseAnswerRegex = $falseAnswerRegex;
        $this->setNormalizer($this->getDefaultNormalizer());
        $this->setValidator($this->getDefaultValidator());
    }

    /**
     * Returns the default answer normalizer.
     *
     * @return callable
     */
    private function getDefaultNormalizer()
    {
        $default = $this->getDefault();
        $trueRegex = $this->trueAnswerRegex;
        $falseRegex = $this->falseAnswerRegex;

        return function ($answer) use ($default, $trueRegex, $falseRegex) {
            if (is_bool($answer)) {
                return $answer;
            }
            if (empty($answer) && !empty($default)) {
                return $default;
            }

            if (Preg::isMatch($trueRegex, $answer)) {
                return true;
            }

            if (Preg::isMatch($falseRegex, $answer)) {
                return false;
            }

            return null;
        };
    }

    /**
     * Returns the default answer validator.
     *
     * @return callable
     */
    private function getDefaultValidator()
    {
        return function ($answer) {
            if (!is_bool($answer)) {
                throw new InvalidArgumentException('Please answer yes, y, no, or n.');
            }

            return $answer;
        };
    }
}
