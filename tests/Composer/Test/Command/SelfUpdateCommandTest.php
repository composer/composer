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

use Composer\Composer;
use Composer\Test\TestCase;

/**
 * @group slow
 * @depends Composer\Test\AllFunctionalTest::testBuildPhar
 */
class SelfUpdateCommandTest extends TestCase
{
    /**
     * @var string
     */
    private $prevArgv;

    public function setUp(): void
    {
        parent::setUp();

        $this->prevArgv = $_SERVER['argv'][0];
        $dir = $this->initTempComposer();
        copy(__DIR__.'/../../../composer-test.phar', $dir.'/composer.phar');
        $_SERVER['argv'][0] = $dir.'/composer.phar';
    }

    public function tearDown(): void
    {
        parent::tearDown();

        $_SERVER['argv'][0] = $this->prevArgv;
    }

    public function testSuccessfulUpdate(): void
    {
        if (Composer::VERSION !== '@package_version'.'@') {
            $this->markTestSkipped('On releases this test can fail to upgrade as we are already on latest version');
        }

        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => 'self-update']);

        $appTester->assertCommandIsSuccessful();
        self::assertStringContainsString('Upgrading to version', $appTester->getDisplay());
    }

    public function testUpdateToSpecificVersion(): void
    {
        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => 'self-update', 'version' => '2.4.0']);

        $appTester->assertCommandIsSuccessful();
        self::assertStringContainsString('Upgrading to version 2.4.0', $appTester->getDisplay());
    }

    public function testUpdateWithInvalidOptionThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The "invalid-option" argument does not exist.');

        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => 'self-update', 'invalid-option' => true]);
    }

    /**
     * @dataProvider channelOptions
     */
    public function testUpdateToDifferentChannel(string $option, string $expectedOutput): void
    {
        if (Composer::VERSION !== '@package_version'.'@' && in_array($option, ['--stable', '--preview'], true)) {
            $this->markTestSkipped('On releases this test can fail to upgrade as we are already on latest version');
        }

        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => 'self-update', $option => true]);
        $appTester->assertCommandIsSuccessful();

        self::assertStringContainsString('Upgrading to version', $appTester->getDisplay());
        self::assertStringContainsString($expectedOutput, $appTester->getDisplay());
    }

    /**
     * @return array<array<string>>
     */
    public function channelOptions(): array
    {
        return [
            ['--stable', 'stable channel'],
            ['--preview', 'preview channel'],
            ['--snapshot', 'snapshot channel'],
        ];
    }
}
