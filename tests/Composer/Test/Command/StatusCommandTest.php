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

namespace Composer\Test\Command;

use Composer\Test\TestCase;
use Generator;

class StatusCommandTest extends TestCase
{
    /**
     * @dataProvider caseProvider
     * @param array<mixed> $composerJson
     * @param string $expected
     */
    public function testStatusCommand(
        array $composerJson,
        string $expected
    ): void {
        $this->initTempComposer($composerJson);

        $package = self::getPackage('root/req');
        $package->setType('metapackage');

        $this->createComposerLock([$package], []);
        $this->createInstalledJson([$package], []);

        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => 'status']);

        $this->assertSame(trim($expected), trim($appTester->getDisplay(true)));
    }

    public function caseProvider(): Generator
    {
        yield 'test no changes made to installed packages' => [
            ['require' => ['root/req' => '1.*']],
            'No local changes'
        ];
    }
}
