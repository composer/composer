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

namespace Composer\Test\Package\Archiver;

use Composer\Package\Archiver\GitExcludeFilter;
use Composer\Test\TestCase;

class GitExcludeFilterTest extends TestCase
{
    /**
     * @dataProvider providePatterns
     *
     * @param string  $ignore
     * @param mixed[] $expected
     */
    public function testPatternEscape($ignore, $expected)
    {
        $filter = new GitExcludeFilter('/');

        $this->assertEquals($expected, $filter->parseGitIgnoreLine($ignore));
    }

    public function providePatterns()
    {
        return array(
            array('app/config/parameters.yml', array('{(?=[^\.])app/(?=[^\.])config/(?=[^\.])parameters\.yml(?=$|/)}', false, false)),
            array('!app/config/parameters.yml', array('{(?=[^\.])app/(?=[^\.])config/(?=[^\.])parameters\.yml(?=$|/)}', true, false)),
        );
    }
}
