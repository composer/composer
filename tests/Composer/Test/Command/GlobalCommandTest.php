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
    public function tearDown(): void
    {
        parent::tearDown();
        Platform::clearEnv('COMPOSER_HOME');
        Platform::clearEnv('COMPOSER');
    }
    
    public function testGlobal(): void
    {
        $script = '@php -r \'echo getenv("COMPOSER") . PHP_EOL;\'';
        $fakeComposer = 'TMP_COMPOSER.JSON';
        $composerHome = $this->initTempComposer(
            [
                "scripts" => [
                    "test-script" => $script,
                ],
            ]
        );

        Platform::putEnv('COMPOSER_HOME', $composerHome);
        Platform::putEnv('COMPOSER', $fakeComposer);

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
            'Changed current directory to ' . $composerHome,
            $display
        );
        self::assertStringContainsString($script, $display);
        self::assertStringNotContainsString($fakeComposer, $display, '$COMPOSER is not unset by global command');
    }

    public function testCannotCreateHome(): void
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
