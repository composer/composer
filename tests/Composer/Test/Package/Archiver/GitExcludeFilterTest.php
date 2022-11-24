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

namespace Composer\Test\Package\Archiver;

use Composer\Package\Archiver\GitExcludeFilter;
use Composer\Test\TestCase;

class GitExcludeFilterTest extends TestCase
{
    /**
     * @dataProvider providePatterns
     *
     * @param mixed[] $expected
     */
    public function testPatternEscape(string $ignore, array $expected): void
    {
        $filter = new GitExcludeFilter('/');

        $this->assertEquals($expected, $filter->parseGitAttributesLine($ignore));
    }

    public static function providePatterns(): array
    {
        return [
            ['app/config/parameters.yml export-ignore', ['{(?=[^\.])app/(?=[^\.])config/(?=[^\.])parameters\.yml(?=$|/)}', false, false]],
            ['app/config/parameters.yml -export-ignore', ['{(?=[^\.])app/(?=[^\.])config/(?=[^\.])parameters\.yml(?=$|/)}', true, false]],
        ];
    }
}
