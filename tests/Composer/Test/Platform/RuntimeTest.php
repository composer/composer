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

namespace Composer\Test\Platform;

use Composer\Platform\Runtime;
use Composer\Test\TestCase;

class RuntimeTest extends TestCase
{
    /**
     * @dataProvider provideExtensionInfos
     */
    public function testParseExtensionInfo(string $htmlInput, string $expectedOutput): void
    {
        self::assertSame($expectedOutput, Runtime::parseHtmlExtensionInfo($htmlInput));
    }

    public function provideExtensionInfos(): array
    {
        return [
            'pdo_sqlite' => [
                '<h2><a name="module_pdo_sqlite" href="#module_pdo_sqlite">pdo_sqlite</a></h2>
<table>
<tr><td class="e">PDO Driver for SQLite 3.x </td><td class="v">enabled </td></tr>
<tr><td class="e">SQLite Library </td><td class="v">3.40.1 </td></tr>
</table>',
                'pdo_sqlite

PDO Driver for SQLite 3.x => enabled
SQLite Library => 3.40.1'
            ]
        ];
    }
}
