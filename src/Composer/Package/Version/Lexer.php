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
        return array(
            'v?[0-9.x\*]+', // version match (eg: v1.2.3.4.*)
            '\~|<>|!=|>=|<=|==|<|>', // version comparison modifier
            '\@' . implode('|\@', array_keys(BasePackage::$stabilities)). '|-' . implode('|-', array_keys(BasePackage::$stabilities)), // match stabilities
            '\#[\w\/\@\d]+'
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

        if (preg_match('/#[\w(\/\@\d)?]+/', $value)) {
            return self::T_BRANCH;
        }

        if (preg_match('/[\dx]+/i', $value)) {
            return self::T_VERSION;
        }

        if (in_array(trim($value, '@-'), array_keys(BasePackage::$stabilities))) {
            $value = trim($value, '@-');
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
}
