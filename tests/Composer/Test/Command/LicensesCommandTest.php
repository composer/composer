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

class LicensesCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->initTempComposer([
            'name' => 'test/pkg',
            'version' => '1.2.3',
            'license' => 'MIT',
            'require' => [
                'first/pkg' => '^2.0',
                'second/pkg' => '3.*',
                'third/pkg' => '^1.3',
            ],
            'require-dev' => [
                'dev/pkg' => '~2.0',
            ],
        ]);

        $first = self::getPackage('first/pkg', '2.3.4');
        $first->setLicense(['MIT']);

        $second = self::getPackage('second/pkg', '3.4.0');
        $second->setLicense(['LGPL-2.0-only']);
        $second->setHomepage('https://example.org');

        $third = self::getPackage('third/pkg', '1.5.4');

        $dev = self::getPackage('dev/pkg', '2.3.4.5');
        $dev->setLicense(['MIT']);

        $this->createInstalledJson([$first, $second, $third], [$dev]);
        $this->createComposerLock([$first, $second, $third], [$dev]);
    }

    public function testBasicRun(): void
    {
        $appTester = $this->getApplicationTester();
        self::assertSame(0, $appTester->run(['command' => 'license']));

        $expected = [
            ["Name:", "test/pkg"],
            ["Version:", "1.2.3"],
            ["Licenses:", "MIT"],
            ["Dependencies:"],
            [],
            ["Name", "Version", "Licenses"],
            ["dev/pkg", "2.3.4.5", "MIT"],
            ["first/pkg", "2.3.4", "MIT"],
            ["second/pkg", "3.4.0", "LGPL-2.0-only"],
            ["third/pkg", "1.5.4", "none"],
        ];

        array_walk_recursive($expected, static function (&$value) {
            $value = preg_quote($value, '/');
        });

        foreach (explode(PHP_EOL, $appTester->getDisplay()) as $i => $line) {
            if (trim($line) === '') {
                continue;
            }

            if (!isset($expected[$i])) {
                $this->fail('Got more output lines than expected');
            }
            self::assertMatchesRegularExpression("/" . implode("\s+", $expected[$i]) . "/", $line);
        }
    }

    public function testNoDev(): void
    {
        $appTester = $this->getApplicationTester();
        self::assertSame(0, $appTester->run(['command' => 'license', '--no-dev' => true]));

        $expected = [
            ["Name:", "test/pkg"],
            ["Version:", "1.2.3"],
            ["Licenses:", "MIT"],
            ["Dependencies:"],
            [],
            ["Name", "Version", "Licenses"],
            ["first/pkg", "2.3.4", "MIT"],
            ["second/pkg", "3.4.0", "LGPL-2.0-only"],
            ["third/pkg", "1.5.4", "none"],
        ];

        array_walk_recursive($expected, static function (&$value) {
            $value = preg_quote($value, '/');
        });

        foreach (explode(PHP_EOL, $appTester->getDisplay()) as $i => $line) {
            if (trim($line) === '') {
                continue;
            }

            if (!isset($expected[$i])) {
                $this->fail('Got more output lines than expected');
            }
            self::assertMatchesRegularExpression("/" . implode("\s+", $expected[$i]) . "/", $line);
        }
    }

    public function testFormatJson(): void
    {
        $appTester = $this->getApplicationTester();
        self::assertSame(0, $appTester->run(['command' => 'license', '--format' => 'json'], ['capture_stderr_separately' => true]));

        $expected = [
            "name" => "test/pkg",
            "version" => "1.2.3",
            "license" => ["MIT"],
            "dependencies" => [
                "dev/pkg" => [
                    "version" => "2.3.4.5",
                    "license" => [
                        "MIT",
                    ],
                ],
                "first/pkg" => [
                    "version" => "2.3.4",
                    "license" => [
                        "MIT",
                    ],
                ],
                "second/pkg" => [
                    "version" => "3.4.0",
                    "license" => [
                        "LGPL-2.0-only",
                    ],
                ],
                "third/pkg" => [
                    "version" => "1.5.4",
                    "license" => [],
                ],
            ],
        ];

        self::assertSame($expected, json_decode($appTester->getDisplay(), true));
    }

    public function testFormatSummary(): void
    {
        $appTester = $this->getApplicationTester();
        self::assertSame(0, $appTester->run(['command' => 'license', '--format' => 'summary']));

        $expected = [
            ['-', '-'],
            ['License', 'Number of dependencies'],
            ['-', '-'],
            ['MIT', '2'],
            ['LGPL-2.0-only', '1'],
            ['none', '1'],
            ['-', '-'],
        ];

        $lines = explode("\n", $appTester->getDisplay());

        foreach ($expected as $i => $expect) {
            [$key, $value] = $expect;

            self::assertMatchesRegularExpression("/$key\s+$value/", $lines[$i]);
        }
    }

    public function testFormatUnknown(): void
    {
        $this->expectException(\RuntimeException::class);

        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => 'license', '--format' => 'unknown']);
    }
}
