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

class FundCommandTest extends TestCase
{
    /** 
     * @dataProvider useCaseProvider
     * @param array<mixed> $composerJson 
     * @param array<mixed> $command 
     */
    public function testFundCommand(
        array $composerJson,
        array $command,
        string $expected,
        bool $lock = false
    ): void {
        $this->initTempComposer($composerJson);

        $packages = [
            $this->getPackage('first/pkg', '2.3.4'),
        ];
        $devPackages = [
            $this->getPackage('dev/pkg', '2.3.4.5')
        ];

        $this->createInstalledJson($packages, $devPackages);

        if ($lock) {
            $this->createComposerLock($packages, $devPackages);
        }
        
        $appTester = $this->getApplicationTester();
        $appTester->run(array_merge(['command' => 'fund']), $command);

        $appTester->assertCommandIsSuccessful();
        $this->assertSame(trim($expected), trim($appTester->getDisplay(true)));
    }

    public function useCaseProvider(): Generator
    {
        yield 'no funding links were found' => [
            [
                'require' => [
                    'first/pkg' => '^2.0',
                ],
                'require-dev' => [
                    'dev/pkg' => '~4.0',
                ]
            ],
            [],
            "Info from https://repo.packagist.org: #StandWithUkraine
No funding links were found in your package dependencies. This doesn't mean they don't need your support!" 
        ];   
    }
}
