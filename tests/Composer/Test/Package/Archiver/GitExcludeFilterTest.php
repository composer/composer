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
    public function testPatternEscape($ignore, $expected): void
    {
        $filter = new GitExcludeFilter('/');

        $this->assertEquals($expected, $filter->parseGitAttributesLine($ignore));
    }

    public function providePatterns(): array
    {
        return array(
            array('app/config/parameters.yml export-ignore', array('{(?=[^\.])app/(?=[^\.])config/(?=[^\.])parameters\.yml(?=$|/)}', false, false)),
            array('app/config/parameters.yml -export-ignore', array('{(?=[^\.])app/(?=[^\.])config/(?=[^\.])parameters\.yml(?=$|/)}', true, false)),
        );
    }
}
