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
use Composer\Util\Platform;

class GlobalCommandTest extends TestCase
{
    public function testGlobal(): void
    {
        $script = '@php -r \'echo getenv("COMPOSER") . PHP_EOL;\'';
        $fake_composer = 'TMP_COMPOSER.JSON';
        $composer_home = $this->initTempComposer(
            [
                "scripts" => [
                    "test-script" => $script,
                ],
            ]
        );

        Platform::putEnv('COMPOSER_HOME', $composer_home);
        Platform::putEnv('COMPOSER', $fake_composer);

        $dir = self::getUniqueTmpDirectory();
        chdir($dir);

        $appTester = $this->getApplicationTester();
        $appTester->run([
            'command' => 'global',
            'command-name' => 'test-script',
            '--no-interaction' => true,
        ]);

        $display = $appTester->getDisplay(true);

        self::assertStringContainsString(
            'Changed current directory to ' . $composer_home,
            $display
        );
        self::assertStringContainsString($script, $display);
        self::assertStringNotContainsString($fake_composer, $display);
    }

    public function testNotCreateHome(): void
    {
        $dir = self::getUniqueTmpDirectory();
        $filename = $dir . '/file';
        file_put_contents($filename, '');

        Platform::putEnv('COMPOSER_HOME', $filename);

        self::expectException(\RuntimeException::class);
        $this->expectExceptionMessage($filename . ' exists and is not a directory.');

        $appTester = $this->getApplicationTester();
        $appTester->run([
            'command' => 'global',
            'command-name' => 'test-script',
            '--no-interaction' => true,
        ]);
    }
}
