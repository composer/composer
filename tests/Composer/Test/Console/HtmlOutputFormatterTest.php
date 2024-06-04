<?php declare(strict_types=1);

/*
 * This file is part of Composer.
 *
 * (c) Nils Adermann <naderman@naderman.de>
 *     Jordi Boggiano <j.boggiano@seld.be>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Composer\Test\Console;

use Composer\Console\HtmlOutputFormatter;
use Composer\Test\TestCase;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;

class HtmlOutputFormatterTest extends TestCase
{
    public function testFormatting(): void
    {
        $formatter = new HtmlOutputFormatter([
            'warning' => new OutputFormatterStyle('black', 'yellow'),
        ]);

        self::assertEquals(
            'text <span style="color:green;">green</span> <span style="color:yellow;">yellow</span> <span style="color:black;background-color:yellow;">black w/ yello bg</span>',
            $formatter->format('text <info>green</info> <comment>yellow</comment> <warning>black w/ yello bg</warning>')
        );
    }
}
