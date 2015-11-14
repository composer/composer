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

class GitExcludeFilterTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @dataProvider patterns
     */
    public function testPatternEscape($ignore, $expected)
    {
        $filter = new GitExcludeFilter('/');

        $this->assertEquals($expected, $filter->parseGitIgnoreLine($ignore));
    }

    public function patterns()
    {
        return array(
            array('app/config/parameters.yml', array('{(?=[^\.])app/(?=[^\.])config/(?=[^\.])parameters\.yml(?=$|/)}', false, false)),
            array('!app/config/parameters.yml', array('{(?=[^\.])app/(?=[^\.])config/(?=[^\.])parameters\.yml(?=$|/)}', true, false)),
        );
    }
}
