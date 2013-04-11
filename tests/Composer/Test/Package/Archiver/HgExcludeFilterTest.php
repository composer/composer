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

use Composer\Package\Archiver\HgExcludeFilter;

class HgExcludeFilterTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @dataProvider patterns
     */
    public function testPatternEscape($ignore, $expected)
    {
        $filter = new HgExcludeFilter('/');

        $this->assertEquals($expected, $filter->patternFromRegex($ignore));
    }

    public function patterns()
    {
        return array(
            array('.#', array('#.\\##', false, true)),
            array('.\\#', array('#.\\\\\\##', false, true)),
            array('\\.#', array('#\\.\\##', false, true)),
            array('\\\\.\\\\\\\\#', array('#\\\\.\\\\\\\\\\##', false, true)),
            array('.\\\\\\\\\\#', array('#.\\\\\\\\\\\\\\##', false, true)),
        );
    }
}
