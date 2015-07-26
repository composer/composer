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

namespace Composer;

use Composer\Console\HtmlOutputFormatter;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;

class HtmlOutputFormatterTest extends \PHPUnit_Framework_TestCase
{
    public function testFormatting()
    {
        $formatter = new HtmlOutputFormatter(array(
            'warning' => new OutputFormatterStyle('black', 'yellow'),
        ));

        return $this->assertEquals(
            'text <span style="color:green;">green</span> <span style="color:yellow;">yellow</span> <span style="color:black;background-color:yellow;">black w/ yello bg</span>',
            $formatter->format('text <info>green</info> <comment>yellow</comment> <warning>black w/ yello bg</warning>')
        );
    }
}
