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

namespace Composer\Package\Version;

use Composer\Package\BasePackage;
use Doctrine\Common\Lexer\AbstractLexer;

/**
 * Lexer for version constraints
 *
 * @author Marco Pivetta <ocramius@gmail.com>
 */
final class Lexer extends AbstractLexer
{
    // All tokens that are not valid identifiers must be < 100
    const T_NONE              = 1;

    // All tokens that are also identifiers should be >= 100
    const T_VERSION           = 100;
    const T_COMPARISON        = 101;
    const T_STABILITY         = 102;
    const T_CLOSE_PARENTHESIS = 103;
    const T_OPEN_PARENTHESIS  = 104;
    const T_COMMA             = 105;
    const T_PIPE              = 106;

    const T_BRANCH            = 107;

    /**
     * {@inheritDoc}
     */
    protected function getCatchablePatterns()
    {
        $stabilities = $this->getStabilitiesPattern();

        return array(
            'v?[0-9.x\*]+', // version match (eg: v1.2.3.4.*)
            '\~|<>|!=|>=|<=|==|<|>', // version comparison modifier
            '\@' . implode('|\@', $stabilities) . '|-' . implode('|-', $stabilities), // match stabilities
            '\#[\w\/\@\d]+'
        );
    }

    private function getStabilitiesPattern()
    {
        return array_merge(
            array('beta\d+?', 'b\d+?', 'r', 'p', 'pl'),
            array_keys(BasePackage::$stabilities)
        );
    }

    /**
     * {@inheritDoc}
     */
    protected function getNonCatchablePatterns()
    {
        return array('\s+', '(.)');
    }

    /**
     * {@inheritDoc}
     */
    protected function getType(& $value)
    {
        if (preg_match('/^(\~|<>|!=|>=|<=|==|<|>)$/', $value)) {
            return self::T_COMPARISON;
        }

        if (preg_match('/#[\w(\/\@\d)?]+/i', $value)) {
            return self::T_BRANCH;
        }

        if (preg_match('/^[\d.x\*]+/i', $value)) {
            return self::T_VERSION;
        }

        if (preg_match('/'.implode('|', $this->getStabilitiesPattern()).'/i', ltrim($value, '-@'))) {
            return self::T_STABILITY;
        }

        switch ($value) {
            case '(': return self::T_OPEN_PARENTHESIS;
            case ')': return self::T_CLOSE_PARENTHESIS;
            case ',': return self::T_COMMA;
            case '|': return self::T_PIPE;
        }

        return self::T_NONE;
    }

    public function tokenIsOpenParenthesis()
    {
        return $this->token['type'] == self::T_OPEN_PARENTHESIS;
    }

    public function tokenIsCloseParenthesis()
    {
        return $this->token['type'] == self::T_CLOSE_PARENTHESIS;
    }

    public function tokenIsComparison()
    {
        return $this->token['type'] == self::T_COMPARISON;
    }

    public function tokenIsVersion()
    {
        return $this->token['type'] == self::T_VERSION;
    }

    public function tokenIsStability()
    {
        return $this->token['type'] == self::T_STABILITY;
    }

    public function tokenIsComma()
    {
        return $this->token['type'] == self::T_COMMA;
    }

    public function tokenIsPipe()
    {
        return $this->token['type'] == self::T_PIPE;
    }

    public function tokenIsBranch()
    {
        return $this->token['type'] == self::T_BRANCH;
    }

    public function tokenIsInvalid()
    {
        return $this->token['type'] == self::T_NONE;
    }
}
