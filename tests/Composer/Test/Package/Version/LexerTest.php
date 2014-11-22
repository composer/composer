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

namespace Composer\Test\Package\Version;

use Composer\Package\Version\Lexer;

class LexerTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var Lexer
     */
    private $lexer;

    /**
     * {@inheritDoc}
     */
    protected function setUp()
    {
        $this->lexer = new Lexer();
    }

    public function testBasicVersionMatch()
    {
        $this->lexer->setInput('1');

        $this->assertSame(
            array(
                'value'    => '1',
                'type'     => Lexer::T_VERSION,
                'position' => 0,
            ),
            $this->lexer->glimpse()
        );
    }

    public function testPointVersionMatch()
    {
        $this->lexer->setInput('1.2.3.4');

        $this->assertSame(
            array(
                'value'    => '1.2.3.4',
                'type'     => Lexer::T_VERSION,
                'position' => 0,
            ),
            $this->lexer->glimpse()
        );
    }

    public function testComplexVersionMatch()
    {
        $this->lexer->setInput('1.2.*');

        $this->assertSame(
            array(
                'value'    => '1.2.*',
                'type'     => Lexer::T_VERSION,
                'position' => 0,
            ),
            $this->lexer->glimpse()
        );
    }

    public function testComparisonMatch()
    {
        $this->lexer->setInput('>=1');

        $this->assertSame(
            array(
                'value'    => '>=',
                'type'     => Lexer::T_COMPARISON,
                'position' => 0,
            ),
            $this->lexer->glimpse()
        );

        $this->lexer->moveNext();

        $this->assertSame(
            array(
                'value'    => '1',
                'type'     => Lexer::T_VERSION,
                'position' => 2,
            ),
            $this->lexer->glimpse()
        );
    }

    /**
     * @param string $token
     * @param int    $expectedTokenType
     *
     * @dataProvider matchableTokens
     */
    public function testMatchedTokens($expectedTokenType, $token)
    {
        $this->lexer->setInput($token);

        $current = $this->lexer->glimpse();

        $this->assertSame($expectedTokenType, $current['type']);
        $this->assertSame(0, $current['position']);
        $this->assertSame($token, $current['value']);
    }

    public function testTokenIsOpenParenthesis()
    {
        $this->lexer->setInput('(>2.0)');

        $this->assertTrue($this->lexer->tokenIsOpenParenthesis());
        $this->assertEquals(
            $this->lexer->glimpse(), 
            array(
                'value' => '(',
                'type' => Lexer::T_OPEN_PARENTHESIS,
                'position' => 0,
            )
        );

        $this->lexer->moveNext();
        $this->assertFalse($this->lexer->tokenIsOpenParenthesis());
    }

    /**
     * @dataProvider individualToken
     */
    public function testTokenComparisons($token, $methodToComparison, $expectedType, $expectedValue)
    {
        $this->lexer->setInput($token);
        $this->assertTrue($this->lexer->$methodToComparison());
        $this->assertEquals(
            $this->lexer->glimpse(), 
            array(
                'value' => $expectedValue,
                'type' => $expectedType,
                'position' => 0,
            )
        );
    }

    /**
     * Data provider
     *
     * @return string[][]
     */
    public function matchableTokens()
    {
        return array(
            //array('', Lexer::T_NONE),
            //array('invalidstring', Lexer::T_NONE),
            //array(Lexer::T_NONE, ' '),
            array(Lexer::T_NONE, 'b'),
            array(Lexer::T_VERSION, '1'),
            array(Lexer::T_VERSION, '1.2'),
            array(Lexer::T_VERSION, '1.2.3'),
            array(Lexer::T_VERSION, '1.2.3.4'),
            //array(Lexer::T_VERSION, '1.2.3.4.5'),
            //array(Lexer::T_VERSION, '1.2.3.4.*'),
            array(Lexer::T_VERSION, '1.2.3.*'),
            array(Lexer::T_VERSION, '1.2.*'),
            array(Lexer::T_VERSION, '1.*'),
            array(Lexer::T_VERSION, '*'),
        );
    }

    /**
     * @return string[][]
     */
    public function individualToken()
    {
        return array(
            array('(',    'tokenIsOpenParenthesis',  Lexer::T_OPEN_PARENTHESIS,  '('),
            array(')',    'tokenIsCloseParenthesis', Lexer::T_CLOSE_PARENTHESIS, ')'),
            array('>',    'tokenIsComparison',       Lexer::T_COMPARISON,        '>'),
            array('2.0',  'tokenIsVersion',          Lexer::T_VERSION,           '2.0'),
            array('#AB',  'tokenIsBranch',           Lexer::T_BRANCH,            '#AB'),
            array('@dev', 'tokenIsStability',        Lexer::T_STABILITY,         'dev'),
        );
    }
}
