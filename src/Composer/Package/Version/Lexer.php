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

    /**
     * {@inheritDoc}
     */
    protected function getCatchablePatterns()
    {
        return array(
            'v?(\*|\d+)(\.(?:\d+|[x*]))?(\.(?:\d+|[x*]))?(\.(?:\d+|[x*]))?', // version match (eg: v1.2.3.4.*)
            '\~|<>|!=|>=|<=|==|<|>', // version comparison modifier
            //'([^,\s]+?)@(' . implode('|', array_keys(BasePackage::$stabilities)) . ')', // match stabilities
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

        if (preg_match('/^v?(\*|\d+)(\.(?:\d+|[x*]))?(\.(?:\d+|[x*]))?(\.(?:\d+|[x*]))?$/', $value)) {
            return self::T_VERSION;
        }

        if ('(' === $value) {
            return self::T_OPEN_PARENTHESIS;
        }

        if (')' === $value) {
            return self::T_CLOSE_PARENTHESIS;
        }

        if (',' === $value) {
            return self::T_COMMA;
        }

        if ('|' === $value) {
            return self::T_COMMA;
        }

        return self::T_NONE;
    }
}
