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

use Composer\Package\Version\CleanUnnecessaryParenthesis;
use Composer\Package\Version\Lexer;

class CleanUnnecessaryParenthesisTest extends \PHPUnit_Framework_TestCase
{
    public function testCanClearUnnecesasaryParenthesis()
    {
        $input = '((> 2.0.0.0, <= 3.0.0.0) | (> 4.0.0.0, > 5.0.0.0))';

        $lexer = new Lexer();
        $lexer->setInput($input);
        
        $clear = CleanUnnecessaryParenthesis::removeOn($input, $lexer);
        $this->assertEquals($input, $clear);
    }
}
