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

class HtmlOutputFormatterTest extends \PHPUnit_Framework_TestCase
{
    public function testFormatting()
    {
        $formatter = new HtmlOutputFormatter;

        return $this->assertEquals(
            'Reading composer.json of <span style="color:green;">https://github.com/ccqgithub/sherry-php</span> (<span style="color:yellow;">master</span>)',
            $formatter->format('Reading composer.json of <info>https://github.com/ccqgithub/sherry-php</info> (<comment>master</comment>)')
        );
    }
}
